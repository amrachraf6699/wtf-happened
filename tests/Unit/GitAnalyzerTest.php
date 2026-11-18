<?php

namespace AmrAchraf\WtfHappened\Tests\Unit;

use AmrAchraf\WtfHappened\Analysis\ChangeAnalyzer;
use AmrAchraf\WtfHappened\Git\GitAnalyzer;
use AmrAchraf\WtfHappened\Tests\GitFixture;
use PHPUnit\Framework\TestCase;

class GitAnalyzerTest extends TestCase
{
    use GitFixture;

    protected function setUp(): void
    {
        $this->createRepository();
    }

    protected function tearDown(): void
    {
        $this->removeRepository();
    }

    public function test_empty_and_outside_repositories(): void
    {
        $this->assertSame([], (new GitAnalyzer)->analyze($this->directory, 7, null)['commits']);
        $this->expectException(\RuntimeException::class);
        (new GitAnalyzer)->analyze(sys_get_temp_dir(), 7, null);
    }

    public function test_inherited_git_directory_cannot_redirect_analysis(): void
    {
        $this->writeFixture('routes/web.php', '<?php');
        $this->commit();
        $previous = getenv('GIT_DIR');
        putenv('GIT_DIR='.$this->directory.'/nonexistent');
        try {
            $this->assertCount(1, (new GitAnalyzer)->analyze($this->directory, 7, null)['commits']);
        } finally {
            putenv($previous === false ? 'GIT_DIR' : 'GIT_DIR='.$previous);
        }
    }

    public function test_missing_git_has_an_actionable_error(): void
    {
        $previous = getenv('PATH');
        putenv('PATH='.$this->directory);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Install Git');
            (new GitAnalyzer)->analyze($this->directory, 7, null);
        } finally {
            putenv('PATH='.$previous);
        }
    }

    public function test_changes_authors_ranges_and_read_only_behavior(): void
    {
        $this->writeFixture('routes/web.php', "<?php // first\n");
        $first = $this->commit('Initial', time() - 864000);
        $this->writeFixture('routes/web.php', "<?php // changed\n");
        $this->writeFixture('app/Models/Space Name.php', "<?php // model\n");
        $this->commit('Unicode café <error>not markup</error> $(touch nope)', null, 'second@example.test');
        $before = $this->git(['status', '--porcelain=v1']);
        $reader = new GitAnalyzer;
        $result = $reader->analyze($this->directory, 7, null);
        $this->assertCount(1, $result['commits']);
        $this->assertCount(2, $result['commits'][0]['changes']);
        $this->assertSame('M', $result['commits'][0]['changes'][1]['status']);
        $this->assertSame($before, $this->git(['status', '--porcelain=v1']));
        $this->assertCount(1, $reader->analyze($this->directory, null, $first)['commits']);
        $this->assertSame([], $reader->analyze($this->directory, null, 'HEAD')['commits']);
        $all = $reader->analyze($this->directory, 30, null);
        $this->assertSame(2, (new ChangeAnalyzer)->analyze($all)['statistics']['contributors']);
        $this->assertFileDoesNotExist($this->directory.'/nope');
    }

    public function test_renames_deletions_and_scope(): void
    {
        $this->writeFixture('laravel/artisan', 'fixture');
        $this->writeFixture('laravel/app/Old.php', "<?php // unchanged content\n");
        $this->writeFixture('sibling/private.php', 'private');
        $first = $this->commit();
        $this->git(['mv', 'laravel/app/Old.php', 'laravel/app/New.php']);
        $this->commit('Rename');
        $result = (new GitAnalyzer)->analyze($this->directory.'/laravel', null, $first);
        $this->assertSame('R', $result['commits'][0]['changes'][0]['status']);
        $this->assertSame('app/Old.php', $result['commits'][0]['changes'][0]['old_path']);
        $this->assertSame('laravel', $result['scope']);
        $this->git(['rm', 'laravel/app/New.php']);
        $this->commit('Delete');
        $result = (new GitAnalyzer)->analyze($this->directory.'/laravel', 7, null);
        $this->assertSame('D', $result['commits'][0]['changes'][0]['status']);
        $this->assertStringNotContainsString('private', json_encode($result));
    }

    public function test_cross_scope_moves_do_not_expose_sibling_paths(): void
    {
        $this->writeFixture('laravel/artisan', 'fixture');
        $this->writeFixture('laravel/app/Thing.php', '<?php // test');
        $this->writeFixture('sibling/keep.txt', 'keep');
        $first = $this->commit();
        $this->git(['mv', 'laravel/app/Thing.php', 'sibling/Secret.php']);
        $this->commit('Move out');
        $result = (new GitAnalyzer)->analyze($this->directory.'/laravel', null, $first);
        $analysis = (new ChangeAnalyzer)->analyze($result);
        $this->assertSame('D', $analysis['changes']['files'][0]['status']);
        $this->assertStringNotContainsString('sibling', json_encode($analysis));
    }

    public function test_invalid_and_nonancestor_references(): void
    {
        $this->writeFixture('a', 'a');
        $this->commit();
        foreach (['--help', 'missing', 'HEAD~1', 'HEAD..HEAD', 'HEAD:routes'] as $ref) {
            try {
                (new GitAnalyzer)->analyze($this->directory, null, $ref);
                $this->fail('Accepted invalid ref');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('--since', $e->getMessage());
            }
        }
        $this->git(['checkout', '-b', 'other']);
        $this->writeFixture('b', 'b');
        $other = $this->commit();
        $this->git(['checkout', 'main']);
        $this->expectException(\InvalidArgumentException::class);
        (new GitAnalyzer)->analyze($this->directory, null, $other);
    }

    public function test_limits_and_nonmonotonic_dates(): void
    {
        $this->writeFixture('a', 'new ancestor');
        $this->commit('Recent ancestor', time() - 60);
        $this->writeFixture('a', 'old child');
        $this->commit('Old child', time() - 864000);
        $this->writeFixture('a', 'recent child');
        $this->commit('Recent child', time() - 30);
        $result = (new GitAnalyzer)->analyze($this->directory, 7, null);
        $this->assertCount(2, $result['commits']);
        $result = (new GitAnalyzer)->analyze($this->directory, 7, null, 1);
        $this->assertFalse($result['complete']);
        $this->assertCount(1, $result['commits']);
        $this->assertSame([], (new GitAnalyzer)->analyze($this->directory, 7, null, 100, time() - 8640000)['commits']);
    }

    public function test_shallow_boundaries_are_not_root_changes(): void
    {
        $this->writeFixture('a', '1');
        $this->commit();
        $this->writeFixture('a', '2');
        $this->commit();
        $clone = $this->directory.'/clone';
        $url = 'file:///'.ltrim(str_replace('\\', '/', $this->directory), '/');
        $this->git(['clone', '--depth=1', $url, $clone]);
        $result = (new GitAnalyzer)->analyze($clone, 7, null);
        $this->assertFalse($result['complete']);
        $this->assertCount(0, $result['commits']);
    }

    public function test_merge_history_includes_side_branch_and_merge(): void
    {
        $this->writeFixture('base', 'a');
        $this->commit();
        $this->git(['checkout', '-b', 'feature']);
        $this->writeFixture('routes/api.php', '<?php');
        $this->commit('Feature');
        $this->git(['checkout', 'main']);
        $this->writeFixture('app/Models/User.php', '<?php');
        $this->commit('Model');
        $this->git(['merge', '--no-ff', 'feature', '-m', 'Merge feature']);
        $result = (new GitAnalyzer)->analyze($this->directory, 7, null);
        $this->assertCount(4, $result['commits']);
        $this->assertSame(3, (new ChangeAnalyzer)->analyze($result)['statistics']['files']);
    }
}

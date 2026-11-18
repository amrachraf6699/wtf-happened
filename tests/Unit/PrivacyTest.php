<?php

namespace AmrAchraf\WtfHappened\Tests\Unit;

use AmrAchraf\WtfHappened\AI\AiContextBuilder;
use AmrAchraf\WtfHappened\Analysis\ChangeAnalyzer;
use AmrAchraf\WtfHappened\Git\GitAnalyzer;
use AmrAchraf\WtfHappened\Tests\GitFixture;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase
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

    public function test_redaction_and_terminal_control_filtering(): void
    {
        $input = "password='super secret' api_key=abcdef Bearer abc.def https://user:pass@example.test user@example.test\n-----BEGIN PRIVATE KEY-----\nprivate content\n-----END PRIVATE KEY-----\n\033[31mred\033[0m";
        $clean = AiContextBuilder::clean($input);
        foreach (['super secret', 'abcdef', 'abc.def', 'user:pass', 'user@example.test', 'private content', "\033"] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertStringContainsString('red', $clean);
    }

    public function test_context_excludes_sensitive_files_authors_and_diffs_without_consent(): void
    {
        $this->writeFixture('.env', 'PASSWORD=private');
        $this->writeFixture('config/services.php', '<?php // config-secret');
        $this->writeFixture('app/Services/Payment.php', "<?php\n\$token = 'private-token';\n");
        $this->commit('Change token=secret-subject');
        $git = new GitAnalyzer;
        $evidence = $git->analyze($this->directory, 7, null);
        $analysis = (new ChangeAnalyzer)->analyze($evidence);
        $builder = new AiContextBuilder;
        $config = ['include_diffs' => false, 'max_payload_bytes' => 40000, 'max_diff_bytes' => 20000];
        $context = $builder->build($evidence, $analysis, $config, $git);
        $json = json_encode($context);
        foreach (['.env', 'private-token', 'secret-subject', 'fixture@example', 'excerpt'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $config['include_diffs'] = true;
        $context = $builder->build($evidence, $analysis, $config, $git);
        $json = json_encode($context);
        $this->assertStringContainsString('excerpt', $json);
        $this->assertStringNotContainsString('private-token', $json);
        $this->assertStringNotContainsString('config-secret', $json);
    }

    public function test_large_binary_and_sensitive_rename_excerpts_are_excluded(): void
    {
        $this->writeFixture('app/Large.php', str_repeat('x', 262145));
        $this->writeFixture('app/Binary.php', "<?php\0private binary");
        $this->writeFixture('app/secrets.php', '<?php // private source');
        $this->commit();
        $this->git(['mv', 'app/secrets.php', 'app/Public.php']);
        $this->commit('Rename secret');
        $git = new GitAnalyzer;
        $evidence = $git->analyze($this->directory, 7, null);
        $context = (new AiContextBuilder)->build($evidence, (new ChangeAnalyzer)->analyze($evidence), ['include_diffs' => true, 'max_payload_bytes' => 40000, 'max_diff_bytes' => 20000], $git);
        $this->assertStringNotContainsString('excerpt', json_encode($context));
        $this->assertTrue($context['omitted']);
    }

    public function test_serialized_context_and_excerpt_limits(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->writeFixture('app/Models/Long'.$i.'.php', "<?php\n".str_repeat("// long text\n", 500));
        }
        $this->commit(str_repeat('long subject ', 200));
        $git = new GitAnalyzer;
        $evidence = $git->analyze($this->directory, 7, null);
        $context = (new AiContextBuilder)->build($evidence, (new ChangeAnalyzer)->analyze($evidence), ['include_diffs' => true, 'max_payload_bytes' => 4000, 'max_diff_bytes' => 3000], $git);
        $this->assertLessThanOrEqual(4000, strlen(json_encode($context['payload'])));
        $bytes = 0;
        foreach ($context['payload']['evidence'] as $entry) {
            foreach ($entry['files'] as $file) {
                $this->assertLessThanOrEqual(2048, strlen($file['excerpt'] ?? ''));
                $bytes += strlen($file['excerpt'] ?? '');
            }
        }
        $this->assertLessThanOrEqual(3000, $bytes);
        $this->assertTrue($context['omitted']);
    }
}

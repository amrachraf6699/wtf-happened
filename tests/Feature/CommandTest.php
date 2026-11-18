<?php

namespace AmrAchraf\WtfHappened\Tests\Feature;

use AmrAchraf\WtfHappened\Tests\GitFixture;
use AmrAchraf\WtfHappened\WtfHappenedServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CommandTest extends TestCase
{
    use GitFixture;

    protected function getPackageProviders($app): array
    {
        return [WtfHappenedServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('wtf-happened.ai.model', 'fixture-model');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRepository();
        $this->app->useAppPath($this->directory.'/app');
        $this->app->setBasePath($this->directory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removeRepository();
        parent::tearDown();
    }

    private function runCommand(array $options = []): array
    {
        $tester = new CommandTester(Artisan::all()['wtf:happened']);
        $code = $tester->execute($options, ['capture_stderr_separately' => true]);

        return [$code, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    private function history(): void
    {
        $this->writeFixture('routes/web.php', '<?php // fixture');
        $this->commit('Update routes');
    }

    public function test_discovery_defaults_and_partial_config(): void
    {
        $this->assertSame(7, config('wtf-happened.default_days'));
        $this->assertSame(false, config('wtf-happened.ai.enabled'));
        $this->assertSame('fixture-model', config('wtf-happened.ai.model'));
        $this->history();
        [$code, $output] = $this->runCommand(['--json' => true]);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame(1, $report['statistics']['commits']);
        $this->assertSame('disabled', $report['ai']['status']);
        $this->assertStringNotContainsString('fixture@example', $output);
        $this->assertStringNotContainsString($this->directory, $output);
        Http::assertNothingSent();
    }

    public function test_invalid_options_produce_json_and_stderr(): void
    {
        foreach ([['--days' => '-1'], ['--days' => 'abc'], ['--days' => '7', '--since' => 'HEAD'], ['--unknown' => true]] as $options) {
            [$code, $output, $stderr] = $this->runCommand($options + ['--json' => true]);
            $this->assertSame(2, $code);
            $this->assertSame('error', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
            $this->assertNotEmpty($stderr);
            $this->assertStringNotContainsString("\033", $output);
        }
    }

    public function test_no_ai_overrides_persistent_and_explicit_consent(): void
    {
        $this->history();
        config(['wtf-happened.ai.enabled' => true, 'wtf-happened.ai.include_diffs' => true]);
        [$code, $output] = $this->runCommand(['--json' => true, '--ai' => true, '--no-ai' => true, '--funny' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('disabled', json_decode($output, true)['ai']['status']);
        Http::assertNothingSent();
    }

    public function test_missing_key_falls_back_and_empty_history_skips_ai(): void
    {
        config(['wtf-happened.ai.api_key' => null]);
        [$code, $output] = $this->runCommand(['--json' => true, '--ai' => true]);
        $this->assertSame(0, $code);
        $this->assertSame('skipped_empty', json_decode($output, true)['ai']['status']);
        $this->history();
        [$code, $output, $stderr] = $this->runCommand(['--json' => true, '--ai' => true]);
        $this->assertSame(1, $code);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('degraded', $report['status']);
        $this->assertSame(1, $report['statistics']['commits']);
        $this->assertStringContainsString('OPENAI_API_KEY', $stderr);
        Http::assertNothingSent();
    }

    public function test_human_report_escapes_untrusted_text_and_funny_never_enables_ai(): void
    {
        $this->writeFixture('routes/web.php', '<?php');
        $this->commit('<error>not markup</error> token=secret-value');
        [$code, $output] = $this->runCommand(['--funny' => true, '--no-ansi' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('THE DAMAGE REPORT', $output);
        $this->assertStringContainsString('<error>not markup</error>', $output);
        $this->assertStringNotContainsString('secret-value', $output);
        Http::assertNothingSent();
    }

    public function test_successful_ai_is_separate_and_never_reports_excerpts(): void
    {
        $this->history();
        config(['wtf-happened.ai.enabled' => true, 'wtf-happened.ai.api_key' => 'test-key', 'wtf-happened.ai.include_diffs' => true]);
        $statement = ['text' => 'Routes changed.', 'evidence_ids' => ['c1']];
        $summary = ['summary' => $statement, 'highlights' => [$statement], 'potential_risks' => [], 'suggested_next_step' => $statement, 'funny_comment' => null];
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($summary)]]]]])]);
        [$code, $output, $stderr] = $this->runCommand(['--json' => true]);
        $this->assertSame(0, $code, $stderr);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('succeeded', $report['ai']['status']);
        $this->assertStringNotContainsString('<?php', $output);
        $this->assertStringNotContainsString('test-key', $output);
        Http::assertSent(function ($request) {
            $this->assertFalse($request['store']);
            $this->assertArrayNotHasKey('tools', $request->data());
            $this->assertStringContainsString('excerpt', $request['input']);
            $this->assertStringNotContainsString('fixture@example', $request['input']);

            return true;
        });
    }
}

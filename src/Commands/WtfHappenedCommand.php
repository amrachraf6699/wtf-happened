<?php

namespace AmrAchraf\WtfHappened\Commands;

use AmrAchraf\WtfHappened\AI\AiContextBuilder;
use AmrAchraf\WtfHappened\AI\AiProvider;
use AmrAchraf\WtfHappened\AI\AiSummarizer;
use AmrAchraf\WtfHappened\Analysis\ChangeAnalyzer;
use AmrAchraf\WtfHappened\Analysis\PathClassifier;
use AmrAchraf\WtfHappened\Git\GitAnalyzer;
use AmrAchraf\WtfHappened\Reporting\TerminalReporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WtfHappenedCommand extends Command
{
    protected $signature = 'wtf:happened {--days= : Analyze the last N days (default 7)} {--since= : Analyze after an ancestor commit or tag} {--funny : Add evidence-grounded humor} {--no-ai : Force offline Git-only analysis} {--ai : Explicitly enable external AI analysis} {--json : Output one JSON document}';

    protected $description = 'Catch up on local Git changes, with optional AI interpretation';

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $exception) {
            if (! $input->hasParameterOption('--json', true)) {
                throw $exception;
            }
            // Binding errors happen before handle(), but JSON callers still need a JSON envelope.
            $report = $this->emptyReport();
            $report['status'] = 'error';
            $report['completeness']['history'] = false;
            $report['error'] = 'Invalid command arguments. Run php artisan help wtf:happened for supported options.';
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->writeln($report['error']);
            }
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return 2;
        }
    }

    public function handle(GitAnalyzer $git, AiSummarizer $ai, TerminalReporter $reporter): int
    {
        $report = $this->emptyReport();
        $exit = 0;
        try {
            $config = config('wtf-happened');
            if (! is_array($config['analysis']['module_roots']) || ! is_array($config['analysis']['category_rules'])) {
                throw new InvalidArgumentException('analysis.module_roots and analysis.category_rules must be arrays.');
            }
            $classifier = new PathClassifier($config['analysis']['module_roots'], $config['analysis']['category_rules']);
            $analyzer = new ChangeAnalyzer($classifier);
            $builder = new AiContextBuilder($classifier);
            if ($this->option('days') !== null && $this->option('since') !== null) {
                throw new InvalidArgumentException('--days and --since are mutually exclusive.');
            }
            $days = $this->integer($this->option('days') ?? $config['default_days'], 1, 3650, 'days');
            $limit = $this->integer($config['max_commits'], 1, 1000, 'max_commits');
            $enabled = ! $this->option('no-ai') && ($this->option('ai') || $this->boolean($config['ai']['enabled'], 'ai.enabled'));
            if ($enabled) {
                $config['ai']['include_diffs'] = $this->boolean($config['ai']['include_diffs'], 'ai.include_diffs');
                foreach (['max_payload_bytes' => [2000, 40000], 'max_diff_bytes' => [0, 20000], 'timeout_seconds' => [1, 20]] as $key => [$min, $max]) {
                    $config['ai'][$key] = $this->integer($config['ai'][$key], $min, $max, 'ai.'.$key);
                }
            }
            $evidence = $git->analyze(base_path(), $this->option('since') === null ? $days : null, $this->option('since'), $limit);
            $analysis = $analyzer->analyze($evidence);
            $report = array_replace($report, $analysis, ['range' => $evidence['range'], 'scope' => $evidence['scope'], 'warnings' => $evidence['warnings']]);
            $report['completeness']['history'] = $evidence['complete'];
            if ($enabled && $report['statistics']['commits'] === 0) {
                $report['ai']['status'] = 'skipped_empty';
            } elseif ($enabled) {
                try {
                    $provider = (new AiProvider)->settings($config['ai']);
                    $report['ai']['provider'] = $provider['name'];
                    $context = $builder->build($evidence, $analysis, $config['ai'], $git);
                    $report['completeness']['ai_context'] = ! $context['omitted'];
                    if ($context['omitted']) {
                        $report['warnings'][] = 'Some AI context was excluded or truncated by privacy and size limits.';
                    }
                    $this->diagnostic('AI enabled: sending sanitized commit subjects, relative paths and counts to '.$provider['label'].' ('.$provider['base'].')'.($config['ai']['include_diffs'] ? ', with bounded committed PHP excerpts' : ', without source excerpts').'. Redaction cannot guarantee removal of all secrets.');
                    $report['ai']['summary'] = $ai->summarize($context, $config['ai'], (bool) $this->option('funny'));
                    $report['ai']['status'] = 'succeeded';
                    foreach ($context['payload']['evidence'] as $entry) {
                        // Evidence mapping excludes source excerpts even in JSON mode.
                        $report['ai']['evidence'][] = ['id' => $entry['id'], 'subject' => $entry['subject'], 'paths' => array_column($entry['files'], 'path')];
                    }
                } catch (\Throwable $e) {
                    $report['ai']['status'] = 'failed';
                    $report['status'] = 'degraded';
                    $report['error'] = in_array(get_class($e), [\RuntimeException::class, InvalidArgumentException::class], true) ? $e->getMessage() : 'AI analysis failed. Git-only results are available.';
                    $exit = $e instanceof InvalidArgumentException ? 2 : 1;
                }
            }
        } catch (InvalidArgumentException $e) {
            $report['status'] = 'error';
            $report['error'] = $e->getMessage();
            $report['completeness']['history'] = false;
            $exit = 2;
        } catch (\Throwable $e) {
            $report['status'] = 'error';
            $report['error'] = get_class($e) === \RuntimeException::class ? $e->getMessage() : 'Unable to analyze Git history. Check Git installation and repository access.';
            $report['completeness']['history'] = false;
            $exit = 1;
        }
        $report = AiContextBuilder::cleanValue($report);
        if ($report['error'] !== null) {
            $this->diagnostic($report['error']);
        }
        if ($this->option('json')) {
            foreach ($report['warnings'] as $warning) {
                $this->diagnostic($warning);
            }
            $this->output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        } else {
            $reporter->render($report, $this->output, (bool) $this->option('funny'));
        }

        return $exit;
    }

    private function emptyReport(): array
    {
        return [
            'schema_version' => 1, 'status' => 'ok', 'range' => null, 'scope' => '.',
            'completeness' => ['history' => true, 'ai_context' => true],
            'statistics' => ['commits' => 0, 'files' => 0, 'contributors' => 0],
            'changes' => ['files' => [], 'categories' => [], 'modules' => [], 'directories' => [], 'subjects' => []],
            'review_items' => [], 'ai' => ['status' => 'disabled', 'provider' => null, 'summary' => null, 'evidence' => []], 'warnings' => [], 'error' => null,
        ];
    }

    private function integer(mixed $value, int $min, int $max, string $name): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]*$|^0$/D', (string) $value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($name.' must be an integer between '.$min.' and '.$max.'.');
        }

        return (int) $value;
    }

    private function boolean(mixed $value, string $name): bool
    {
        $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($result === null || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException($name.' must be a boolean.');
        }

        return $result;
    }

    private function diagnostic(string $message): void
    {
        $output = $this->output->getOutput();
        $message = OutputFormatter::escape(AiContextBuilder::clean($message));
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        } elseif (! $this->option('json')) {
            $this->output->writeln($message);
        }
    }
}

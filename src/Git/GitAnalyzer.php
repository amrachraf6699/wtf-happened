<?php

namespace AmrAchraf\WtfHappened\Git;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class GitAnalyzer
{
    private string $binary;

    private string $root;

    private string $prefix = '';

    private float $started;

    private int $bytes = 0;

    /** Return internal evidence; author identifiers and absolute root never leave this layer's consumer. */
    public function analyze(string $base, ?int $days, ?string $since, int $limit = 100, ?int $now = null): array
    {
        $binary = (new ExecutableFinder)->find('git');
        if ($binary === null) {
            throw new RuntimeException('Could not execute Git. Install Git and make it available on PATH.');
        }
        $this->binary = $binary;
        $this->started = microtime(true);
        $this->bytes = 0;
        $this->root = $base;
        $now ??= time();
        [$code, $root] = $this->run(['rev-parse', '--show-toplevel'], true);
        if ($code !== 0) {
            throw new RuntimeException('Cannot find a Git working tree for this Laravel application. Install Git and run inside a checked-out project.');
        }
        $this->root = trim($root);
        $realBase = str_replace('\\', '/', realpath($base) ?: $base);
        $normalizedRoot = str_replace('\\', '/', $this->root);
        $comparisonBase = PHP_OS_FAMILY === 'Windows' ? strtolower($realBase) : $realBase;
        $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedRoot) : $normalizedRoot;
        if ($comparisonBase !== $comparisonRoot && ! str_starts_with($comparisonBase, $comparisonRoot.'/')) {
            throw new RuntimeException('Laravel application is outside the resolved Git working tree.');
        }
        $this->prefix = ltrim(substr($realBase, strlen($normalizedRoot)), '/');
        $this->prefix = $this->prefix === '' ? '' : $this->prefix.'/';
        [$headCode, $head] = $this->run(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], true);
        $head = trim($head);
        $result = [
            'range' => ['head' => $headCode === 0 ? $head : null, 'since' => null, 'days' => $days, 'cutoff' => $days === null ? null : gmdate('c', $now - $days * 86400), 'until' => gmdate('c', $now)],
            'scope' => $this->prefix === '' ? '.' : rtrim($this->prefix, '/'),
            'commits' => [], 'warnings' => [], 'complete' => true,
        ];
        if ($since !== null) {
            if ($since === '' || str_starts_with($since, '-') || preg_match('/[\x00-\x20~^:@{}\\\\]/', $since) || str_contains($since, '..')) {
                throw new InvalidArgumentException('--since must be one commit hash or named Git reference.');
            }
            [$code, $resolved] = $this->run(['rev-parse', '--verify', '--quiet', '--end-of-options', $since.'^{commit}'], true);
            if ($code !== 0) {
                throw new InvalidArgumentException('--since could not be resolved locally. Check the commit or tag; shallow clones may lack it.');
            }
            $resolved = trim($resolved);
            if ($headCode !== 0 || $this->run(['merge-base', '--is-ancestor', $resolved, $head], true)[0] !== 0) {
                throw new InvalidArgumentException('--since must be an ancestor of HEAD in the available local history.');
            }
            $result['range']['since'] = $resolved;
        }
        if ($headCode !== 0) {
            if ($this->run(['symbolic-ref', '--quiet', 'HEAD'], true)[0] !== 0) {
                throw new RuntimeException('Cannot resolve HEAD. Check the repository for missing or corrupt objects.');
            }

            return $result;
        }
        $shallow = trim($this->run(['rev-parse', '--is-shallow-repository'])[1]) === 'true';
        $boundaries = [];
        if ($shallow) {
            $result['complete'] = false;
            $result['warnings'][] = 'Shallow repository: only available history is analyzed; boundary changes are omitted.';
            $path = trim($this->run(['rev-parse', '--git-path', 'shallow'])[1]);
            if (! preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $path)) {
                $path = $this->root.'/'.$path;
            }
            $boundaries = is_file($path) ? preg_split('/\s+/', trim(file_get_contents($path))) : [];
        }
        try {
            $args = ['rev-list', '--date-order', '--parents', '--before=@'.$now];
            if ($days !== null) {
                $args[] = '--since-as-filter=@'.($now - $days * 86400);
            }
            $args[] = $since !== null ? $result['range']['since'].'..'.$head : $head;
            $lines = explode("\n", trim($this->run($args)[1]));
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $parts = explode(' ', $line);
                $hash = $parts[0];
                if (in_array($hash, $boundaries, true)) {
                    continue;
                }
                $parent = $parts[1] ?? null;
                $diff = ['diff-tree', '--no-commit-id', '--no-ext-diff', '--no-textconv', '-r', '-z', '--name-status', '-M', '-l1000'];
                array_push($diff, ...($parent === null ? ['--root', $hash] : [$parent, $hash]));
                // Compare the complete tree first so moves across the scope boundary remain visible.
                $changes = $this->parseChanges($this->run($diff)[1]);
                if ($changes === []) {
                    continue;
                }
                if (count($result['commits']) >= $limit) {
                    $result['complete'] = false;
                    $result['warnings'][] = 'Commit limit reached: statistics describe only analyzed commits.';
                    break;
                }
                $meta = explode("\0", $this->run(['show', '--no-patch', '--no-show-signature', '--format=%ct%x00%ae%x00%an%x00%s', $hash])[1], 4);
                $result['commits'][] = [
                    'hash' => $hash, 'parent' => $parent, 'timestamp' => (int) $meta[0],
                    'author' => trim($meta[1]) !== '' ? strtolower(trim($meta[1])) : trim($meta[2]),
                    'subject' => trim($meta[3]), 'changes' => $changes,
                ];
            }
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== 10) {
                throw $exception;
            }
            $result['complete'] = false;
            $result['warnings'][] = 'Git analysis budget reached: statistics describe only completed commits.';
        }

        return $result;
    }

    private function parseChanges(string $raw): array
    {
        $tokens = explode("\0", rtrim($raw, "\0"));
        $changes = [];
        for ($i = 0; $i < count($tokens) && $tokens[$i] !== ''; $i++) {
            $status = $tokens[$i][0];
            $old = $tokens[++$i] ?? '';
            $new = in_array($status, ['R', 'C'], true) ? ($tokens[++$i] ?? '') : $old;
            $oldInside = $this->inside($old);
            $newInside = $this->inside($new);
            if (! $oldInside && ! $newInside) {
                continue;
            }
            // Never expose a sibling application's path for cross-boundary moves.
            $changes[] = [
                'status' => ! $oldInside ? 'A' : (! $newInside ? 'D' : $status),
                'path' => substr($newInside ? $new : $old, strlen($this->prefix)),
                'old_path' => $status === 'R' && $oldInside && $newInside ? substr($old, strlen($this->prefix)) : null,
                '_old' => $old, '_new' => $new,
                '_cross_boundary' => $oldInside !== $newInside,
            ];
        }

        return $changes;
    }

    private function inside(string $path): bool
    {
        return $path !== '' && str_starts_with($path, $this->prefix);
    }

    /** Read only a small committed text patch; callers must first authorize/filter both paths. */
    public function excerpt(array $commit, array $change): ?string
    {
        if ($change['_cross_boundary']) {
            return null;
        }
        foreach ([[$commit['parent'], $change['_old']], [$commit['hash'], $change['_new']]] as [$revision, $path]) {
            if ($revision === null) {
                continue;
            }
            $tree = $this->run(['ls-tree', '-z', $revision, '--', $path])[1];
            if ($tree === '') {
                continue;
            }
            if (! preg_match('/^100(?:644|755) blob ([a-f0-9]+)\t/', $tree, $match)) {
                return null;
            }
            $size = (int) trim($this->run(['cat-file', '-s', $match[1]])[1]);
            if ($size > 262144) {
                return null;
            }
            $blob = $this->run(['cat-file', 'blob', $match[1]])[1];
            if (str_contains($blob, "\0") || preg_match('//u', $blob) !== 1) {
                return null;
            }
        }
        $args = ['diff-tree', '--no-commit-id', '--no-ext-diff', '--no-textconv', '-r', '-p', '--unified=1', '--no-color'];
        array_push($args, ...($commit['parent'] === null ? ['--root', $commit['hash']] : [$commit['parent'], $commit['hash']]));
        array_push($args, '--', $change['_old'], $change['_new']);

        return $this->run($args)[1];
    }

    /** @return array{0:int,1:string} */
    private function run(array $args, bool $allowFailure = false): array
    {
        $remaining = 30 - (microtime(true) - $this->started);
        if ($remaining <= 0 || $this->bytes >= 10485760) {
            throw new RuntimeException('Git budget reached.', 10);
        }
        $env = [];
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'GIT_')) {
                $env[$key] = false;
            }
        }
        $env = array_merge($env, ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_NO_LAZY_FETCH' => '1', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_LITERAL_PATHSPECS' => '1', 'LC_ALL' => 'C']);
        $process = new Process(array_merge([$this->binary, '--no-pager', '-c', 'core.quotePath=false', '-c', 'core.fsmonitor=false'], $args), $this->root, $env, null, min(10, $remaining));
        $output = '';
        $received = 0;
        try {
            $code = $process->run(function (string $type, string $chunk) use (&$output, &$received, $process): void {
                $received += strlen($chunk);
                $this->bytes += strlen($chunk);
                if ($received > 2097152 || $this->bytes > 10485760) {
                    throw new RuntimeException('Git output budget reached.', 10);
                }
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('Git time budget reached.', 10);
        } catch (ExceptionInterface $e) {
            throw new RuntimeException('Could not execute Git. Install Git and make it available on PATH.');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if ($code !== 0 && ! $allowFailure) {
            throw new RuntimeException('Git could not read local history. Check for missing objects or repository corruption; no remote fetch was attempted.');
        }

        return [$code, $output];
    }
}

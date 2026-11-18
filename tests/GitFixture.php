<?php

namespace AmrAchraf\WtfHappened\Tests;

use Symfony\Component\Process\Process;

trait GitFixture
{
    protected string $directory;

    protected function createRepository(): void
    {
        $this->directory = sys_get_temp_dir().'/wtf-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Fixture Developer']);
        $this->git(['config', 'user.email', 'fixture@example.test']);
        $this->git(['config', 'commit.gpgsign', 'false']);
    }

    protected function git(array $arguments, array $env = []): string
    {
        $process = new Process(array_merge(['git', '-c', 'core.autocrlf=false', '-c', 'core.hooksPath=', '-c', 'commit.gpgsign=false'], $arguments), $this->directory, $env);
        $process->mustRun();

        return trim($process->getOutput());
    }

    protected function writeFixture(string $path, string $content): void
    {
        $directory = dirname($this->directory.'/'.$path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($this->directory.'/'.$path, $content);
    }

    protected function commit(string $subject = 'Update routes', ?int $timestamp = null, string $email = 'fixture@example.test'): string
    {
        $this->git(['add', '--all']);
        $date = '@'.($timestamp ?? time() - 60).' +0000';
        $this->git(['commit', '-m', $subject], ['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date, 'GIT_AUTHOR_EMAIL' => $email]);

        return $this->git(['rev-parse', 'HEAD']);
    }

    protected function removeRepository(): void
    {
        // Only remove fixtures created by this trait under the system temporary directory.
        if (! isset($this->directory) || ! str_starts_with($this->directory, sys_get_temp_dir().'/wtf-test-')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0666);
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }
}

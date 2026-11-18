<?php

namespace AmrAchraf\WtfHappened\Reporting;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class TerminalReporter
{
    public function render(array $report, OutputInterface $output, bool $funny): void
    {
        $width = max(30, min(100, (new Terminal)->getWidth() - 4));
        $line = function (string $text = '') use ($output, $width): void {
            $text = str_replace(["\r", "\n", "\t"], ' ', $text);
            $output->writeln(OutputFormatter::escape(wordwrap($text, $width, "\n", true)));
        };
        $heading = function (string $title) use ($output): void {
            $output->writeln('');
            $output->writeln('<fg=cyan;options=bold>'.$title.'</>');
        };
        $heading('WTF HAPPENED?');
        $line('Your code moved on without you.');
        $line('Scope: '.$report['scope']);
        if ($report['range'] !== null) {
            $line($report['range']['since'] !== null
                ? 'Range: after '.substr($report['range']['since'], 0, 12).' through '.substr($report['range']['head'], 0, 12)
                : 'Window: last '.$report['range']['days'].' days');
        }
        $heading('THE DAMAGE REPORT');
        $stats = $report['statistics'];
        $line(sprintf('%d commits | %d changed files | %d contributors%s', $stats['commits'], $stats['files'], $stats['contributors'], $report['completeness']['history'] ? '' : ' (analyzed counts; incomplete history)'));
        if ($stats['commits'] === 0) {
            $line('No matching commits were analyzed.');
        }
        $heading('WHAT CHANGED');
        foreach ($report['changes']['categories'] as $category => $count) {
            $line($category.': '.$count.' '.($count === 1 ? 'file' : 'files').' changed');
        }
        if ($report['changes']['directories']) {
            $line('Main directories: '.implode(', ', array_slice(array_keys($report['changes']['directories']), 0, 3)));
        }
        foreach (array_slice($report['changes']['subjects'], 0, 5) as $subject) {
            $line('Commit: '.$subject);
        }
        if ($report['review_items']) {
            $heading('WORTH REVIEWING');
            foreach (array_slice($report['review_items'], 0, 8) as $review) {
                $line($review['path'].' - '.$review['reason']);
            }
            if (count($report['review_items']) > 8) {
                $line('More review items are available with --json.');
            }
        }
        $heading('AI: '.strtoupper($report['ai']['status']));
        if ($report['ai']['summary'] !== null) {
            $line('AI interpretation; verify against Git evidence.');
            $ai = $report['ai']['summary'];
            $line($ai['summary']['text']);
            foreach ($ai['highlights'] as $item) {
                $line('- '.$item['text']);
            }
            foreach ($ai['potential_risks'] as $item) {
                $line('AI suggests reviewing: '.$item['text']);
            }
            $heading('SUGGESTED NEXT STEP');
            $line($ai['suggested_next_step']['text']);
            if ($ai['funny_comment'] !== null) {
                $line($ai['funny_comment']['text']);
            }
        } elseif ($funny && $stats['commits'] > 0) {
            $line($stats['commits'].' commits while you were away. Git clearly did not take the same vacation.');
        }
        foreach ($report['warnings'] as $warning) {
            $line('Note: '.$warning);
        }
        if ($report['error'] !== null) {
            $line($report['error']);
        }
        $line();
    }
}

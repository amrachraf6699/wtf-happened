<?php

namespace AmrAchraf\WtfHappened\AI;

use AmrAchraf\WtfHappened\Analysis\ChangeAnalyzer;
use AmrAchraf\WtfHappened\Git\GitAnalyzer;

class AiContextBuilder
{
    public static function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|$)/s', '[REDACTED KEY]', $text);
        $text = preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1[REDACTED]@', $text);
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $text);
        $text = preg_replace('/\b(?:sk-(?:proj-)?[A-Za-z0-9_-]{12,}|AKIA[A-Z0-9]{16})\b/', '[REDACTED]', $text);
        $text = preg_replace('/([\w.-]*(?:password|passwd|secret|token|api[_-]?key|credential)[\w.-]*[\x22\x27]?\s*(?:=>|[:=])\s*)(?:[\x22][^\x22\r\n]*[\x22]|[\x27][^\x27\r\n]*[\x27]|[^\s,;]+)/i', '$1[REDACTED]', $text);
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED EMAIL]', $text);
        // Strip terminal control sequences, including OSC hyperlinks and CSI escapes.
        $text = preg_replace('/\x1B\][^\x07]*(?:\x07|\x1B\\\\)|\x1B\[[0-?]*[ -\/]*[@-~]/', '', $text);

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }

    public static function cleanValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[is_string($key) ? self::clean($key) : $key] = self::cleanValue($item);
            }

            return $clean;
        }

        return $value;
    }

    public function allowed(string $path): bool
    {
        return preg_match('~^(app|routes|database|tests)/.+\.php$~i', $path) === 1
            && preg_match('~(?:^|[/_.-])(?:env|config|credentials?|secrets?|keys?|certificates?|vendor|node_modules|generated|cache)(?:[/_.-]|$)~i', $path) !== 1;
    }

    /** @return array{payload:array, ids:array, omitted:bool} */
    public function build(array $evidence, array $analysis, array $config, GitAnalyzer $git): array
    {
        $payload = ['statistics' => $analysis['statistics'], 'history_complete' => $evidence['complete'], 'evidence' => []];
        $omitted = false;
        $candidates = [];
        $analyzer = new ChangeAnalyzer;
        foreach ($evidence['commits'] as $index => $commit) {
            $omitted = $omitted || strlen($commit['subject']) > 1000;
            $id = 'c'.($index + 1);
            $entry = ['id' => $id, 'subject' => self::clean(mb_strcut($commit['subject'], 0, 1000, 'UTF-8')), 'files' => []];
            $payload['evidence'][] = $entry;
            $slot = count($payload['evidence']) - 1;
            if ($this->size($payload) > $config['max_payload_bytes']) {
                array_pop($payload['evidence']);
                $omitted = true;
                break;
            }
            foreach ($commit['changes'] as $change) {
                if (! $this->metadataAllowed($change['path']) || ($change['old_path'] !== null && ! $this->metadataAllowed($change['old_path']))) {
                    $omitted = true;

                    continue;
                }
                $file = self::cleanValue(['path' => $change['path'], 'old_path' => $change['old_path'], 'status' => $change['status'], 'category' => $analyzer->category($change['path'])]);
                $payload['evidence'][$slot]['files'][] = $file;
                if ($this->size($payload) > $config['max_payload_bytes']) {
                    array_pop($payload['evidence'][$slot]['files']);
                    $omitted = true;

                    continue;
                }
                if ($config['include_diffs'] && $this->allowed($change['path']) && ($change['old_path'] === null || $this->allowed($change['old_path']))) {
                    $candidates[] = ['slot' => $slot, 'file' => count($payload['evidence'][$slot]['files']) - 1, 'commit' => $commit, 'change' => $change, 'priority' => $analyzer->reviewReason($change['path']) === null ? 1 : 0];
                }
            }
        }
        usort($candidates, fn ($a, $b) => [$a['priority'], -$a['commit']['timestamp'], $a['change']['path']] <=> [$b['priority'], -$b['commit']['timestamp'], $b['change']['path']]);
        $diffBytes = 0;
        $excerpts = 0;
        foreach ($candidates as $candidate) {
            if ($excerpts >= 10 || $diffBytes >= $config['max_diff_bytes']) {
                $omitted = true;
                break;
            }
            try {
                $patch = $git->excerpt($candidate['commit'], $candidate['change']);
            } catch (\RuntimeException $e) {
                $omitted = true;
                break;
            }
            if ($patch === null || $patch === '') {
                $omitted = true;

                continue;
            }
            $sanitized = self::clean($patch);
            $excerpt = mb_strcut($sanitized, 0, min(2048, $config['max_diff_bytes'] - $diffBytes), 'UTF-8');
            $omitted = $omitted || strlen($excerpt) < strlen($sanitized);
            $payload['evidence'][$candidate['slot']]['files'][$candidate['file']]['excerpt'] = $excerpt;
            if ($this->size($payload) > $config['max_payload_bytes']) {
                unset($payload['evidence'][$candidate['slot']]['files'][$candidate['file']]['excerpt']);
                $omitted = true;

                continue;
            }
            $diffBytes += strlen($excerpt);
            $excerpts++;
        }

        return ['payload' => $payload, 'ids' => array_column($payload['evidence'], 'id'), 'omitted' => $omitted];
    }

    private function metadataAllowed(string $path): bool
    {
        return preg_match('~(?:^|/)(?:\.env(?:\..*)?|id_rsa|id_ed25519|credentials[^/]*|secrets?[^/]*)(?:/|$)|\.(?:pem|key|p12|pfx)$~i', $path) !== 1;
    }

    private function size(array $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

<?php

namespace AmrAchraf\WtfHappened\AI;

use InvalidArgumentException;
use RuntimeException;

/** Wire formats only: consent, HTTP limits and evidence validation remain shared. */
class AiProvider
{
    public const PRESETS = [
        'openai' => ['OpenAI', 'responses', 'https://api.openai.com/v1', 'OPENAI_API_KEY'],
        'anthropic' => ['Anthropic', 'anthropic', 'https://api.anthropic.com/v1', 'ANTHROPIC_API_KEY'],
        'gemini' => ['Google Gemini', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 'GEMINI_API_KEY'],
        'deepseek' => ['DeepSeek', 'chat', 'https://api.deepseek.com/v1', 'DEEPSEEK_API_KEY'],
        'groq' => ['Groq', 'chat', 'https://api.groq.com/openai/v1', 'GROQ_API_KEY'],
        'mistral' => ['Mistral', 'chat', 'https://api.mistral.ai/v1', 'MISTRAL_API_KEY'],
        'xai' => ['xAI', 'chat', 'https://api.x.ai/v1', 'XAI_API_KEY'],
        'openrouter' => ['OpenRouter', 'chat', 'https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY'],
        'together' => ['Together AI', 'chat', 'https://api.together.ai/v1', 'TOGETHER_API_KEY'],
        'fireworks' => ['Fireworks AI', 'chat', 'https://api.fireworks.ai/inference/v1', 'FIREWORKS_API_KEY'],
        'cerebras' => ['Cerebras', 'chat', 'https://api.cerebras.ai/v1', 'CEREBRAS_API_KEY'],
        'ollama' => ['Ollama', 'chat', 'http://localhost:11434/v1', 'OLLAMA_API_KEY'],
        'custom' => ['Custom OpenAI-compatible endpoint', 'chat', '', 'WTF_HAPPENED_AI_API_KEY'],
    ];

    public function settings(array $config): array
    {
        $name = $config['provider'] ?? 'openai';
        if (! is_string($name) || ! isset(self::PRESETS[$name])) {
            throw new InvalidArgumentException('Unknown AI provider. Choose: '.implode(', ', array_keys(self::PRESETS)).'.');
        }
        [$label, $protocol, $base, $keyEnv] = self::PRESETS[$name];
        if (in_array($name, ['custom', 'ollama'], true)) {
            $base = $config['base_url'] ?? $base;
        } elseif (! empty($config['base_url'])) {
            throw new InvalidArgumentException('ai.base_url is only supported with custom or ollama; named providers use their official endpoint.');
        }
        $url = is_string($base) ? parse_url($base) : false;
        $loopback = in_array(strtolower($url['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        if (! $url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! in_array($url['scheme'] ?? '', $loopback ? ['http', 'https'] : ['https'], true)) {
            throw new InvalidArgumentException('AI base URL must use HTTPS (HTTP is allowed only for loopback), without credentials, query or fragment. Include the API prefix, e.g. /v1.');
        }
        $key = $config['api_key'] ?? ($config['provider_keys'][$name] ?? null);
        $model = $config['model'] ?? null;
        if (! is_string($model) || trim($model) === '' || preg_match('/[\x00-\x1f]/', $model)) {
            throw new RuntimeException('AI requires WTF_HAPPENED_AI_MODEL. Choose a model available from '.$label.' or use --no-ai.');
        }
        if ((! is_string($key) || trim($key) === '') && ! ($loopback && in_array($name, ['ollama', 'custom'], true))) {
            throw new RuntimeException('AI requires '.$keyEnv.' or WTF_HAPPENED_AI_API_KEY for '.$label.'. Configure it or use --no-ai.');
        }
        $format = $config['response_format'] ?? 'json_object';
        if (! in_array($format, ['json_object', 'json_schema', 'prompt'], true)) {
            throw new InvalidArgumentException('ai.response_format must be json_object, json_schema or prompt.');
        }

        return compact('name', 'label', 'protocol', 'base', 'key', 'model', 'format');
    }

    /** @return array{url:string,headers:array,body:array} */
    public function request(array $settings, string $instructions, array $input, array $schema): array
    {
        ['protocol' => $protocol, 'base' => $base, 'key' => $key, 'model' => $model] = $settings;
        $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        // All protocols receive the same complete contract, even when the model lacks constrained output.
        $instructions .= ' Return only a JSON object matching this JSON schema: '.json_encode($schema, JSON_THROW_ON_ERROR);
        $headers = $key ? ['Authorization' => 'Bearer '.$key] : [];
        if ($protocol === 'responses') {
            $path = '/responses';
            $body = ['model' => $model, 'store' => false, 'max_output_tokens' => 1800, 'instructions' => $instructions, 'input' => $json,
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'git_catch_up', 'strict' => true, 'schema' => $schema]]];
        } elseif ($protocol === 'anthropic') {
            $path = '/messages';
            $headers = ['x-api-key' => $key, 'anthropic-version' => '2026-10-21'];
            // Prompted JSON works across Claude models; our shared validator enforces the full contract.
            $body = ['model' => $model, 'max_tokens' => 1800, 'system' => $instructions, 'messages' => [['role' => 'user', 'content' => $json]]];
        } elseif ($protocol === 'gemini') {
            $path = '/models/'.rawurlencode(preg_replace('~^models/~', '', $model)).':generateContent';
            $headers = ['x-goog-api-key' => $key];
            $body = ['systemInstruction' => ['parts' => [['text' => $instructions]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $json]]]],
                'generationConfig' => ['maxOutputTokens' => 1800, 'responseMimeType' => 'application/json', 'responseJsonSchema' => $schema]];
        } else {
            $path = '/chat/completions';
            $body = ['model' => $model, 'max_tokens' => 1800, 'stream' => false,
                'messages' => [['role' => 'system', 'content' => $instructions], ['role' => 'user', 'content' => $json]]];
            if ($settings['format'] !== 'prompt') {
                $body['response_format'] = $settings['format'] === 'json_schema'
                    ? ['type' => 'json_schema', 'json_schema' => ['name' => 'git_catch_up', 'strict' => true, 'schema' => $schema]]
                    : ['type' => 'json_object'];
            }
        }

        return ['url' => rtrim($base, '/').$path, 'headers' => $headers, 'body' => $body];
    }

    public function text(array $settings, mixed $body): string
    {
        $label = $settings['label'];
        $fail = fn () => throw new RuntimeException($label.' returned an incomplete, blocked or unsupported response.');
        if (! is_array($body)) {
            $fail();
        }
        $text = '';
        switch ($settings['protocol']) {
            case 'responses':
                if (($body['status'] ?? null) !== 'completed') {
                    $fail();
                }
                foreach ($body['output'] ?? [] as $item) {
                    foreach ($item['content'] ?? [] as $part) {
                        if (($part['type'] ?? null) === 'refusal') {
                            throw new RuntimeException($label.' declined to summarize this input.');
                        }
                        if (($part['type'] ?? null) === 'output_text') {
                            $text .= $part['text'] ?? '';
                        }
                    }
                }
                break;
            case 'anthropic':
                if (($body['stop_reason'] ?? null) !== 'end_turn') {
                    $fail();
                }
                foreach ($body['content'] ?? [] as $part) {
                    if (in_array($part['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                        continue;
                    }
                    if (($part['type'] ?? null) !== 'text') {
                        $fail();
                    }
                    $text .= $part['text'] ?? '';
                }
                break;
            case 'gemini':
                $candidate = $body['candidates'][0] ?? [];
                if (isset($body['promptFeedback']['blockReason']) || ($candidate['finishReason'] ?? null) !== 'STOP') {
                    $fail();
                }
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (! empty($part['thought'])) {
                        continue;
                    }
                    if (! isset($part['text'])) {
                        $fail();
                    }
                    $text .= $part['text'];
                }
                break;
            default:
                $choice = $body['choices'][0] ?? [];
                if (($choice['finish_reason'] ?? null) !== 'stop' || ! empty($choice['message']['refusal']) || ! empty($choice['message']['tool_calls'])) {
                    $fail();
                }
                $text = $choice['message']['content'] ?? '';
                break;
        }
        if (! is_string($text) || trim($text) === '') {
            $fail();
        }

        return $text;
    }
}

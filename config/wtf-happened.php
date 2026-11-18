<?php

use AmrAchraf\WtfHappened\Analysis\PathClassifier;

return [
    'default_days' => 7,
    'max_commits' => 100,
    'analysis' => [
        // Ordered relative glob patterns, case-insensitive. First matching root wins.
        'module_roots' => PathClassifier::ROOTS,
        // First matching full-path rule wins. * matches one segment; ** crosses segments.
        // ['pattern' => 'Modules/*/Gateways/**', 'category' => 'Gateways', 'review' => true],
        'category_rules' => [],
    ],
    'ai' => [
        // Explicit persistent consent: future runs may transmit repository metadata.
        'enabled' => env('WTF_HAPPENED_AI_ENABLED', false),
        'provider' => env('WTF_HAPPENED_AI_PROVIDER', 'openai'),
        'api_key' => env('WTF_HAPPENED_AI_API_KEY'),
        'provider_keys' => [
            'openai' => env('OPENAI_API_KEY'),
            'anthropic' => env('ANTHROPIC_API_KEY'),
            'gemini' => env('GEMINI_API_KEY', env('GOOGLE_API_KEY')),
            'deepseek' => env('DEEPSEEK_API_KEY'),
            'groq' => env('GROQ_API_KEY'),
            'mistral' => env('MISTRAL_API_KEY'),
            'xai' => env('XAI_API_KEY'),
            'openrouter' => env('OPENROUTER_API_KEY'),
            'together' => env('TOGETHER_API_KEY'),
            'fireworks' => env('FIREWORKS_API_KEY'),
            'cerebras' => env('CEREBRAS_API_KEY'),
            'ollama' => env('OLLAMA_API_KEY'),
        ],
        // Only custom and ollama accept endpoint overrides; include the /v1 prefix.
        'base_url' => env('WTF_HAPPENED_AI_BASE_URL'),
        // Chat-compatible providers: json_object, json_schema, or prompt (validated locally).
        'response_format' => env('WTF_HAPPENED_AI_RESPONSE_FORMAT', 'json_object'),
        'model' => env('WTF_HAPPENED_AI_MODEL'),
        // Separate consent for bounded committed PHP excerpts. Redaction is best effort.
        'include_diffs' => env('WTF_HAPPENED_AI_INCLUDE_DIFFS', false),
        'max_payload_bytes' => 40000,
        'max_diff_bytes' => 20000,
        'timeout_seconds' => 20,
    ],
];

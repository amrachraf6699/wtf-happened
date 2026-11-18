<?php

return [
    'default_days' => 7,
    'max_commits' => 100,
    'ai' => [
        // Explicit persistent consent: future runs may transmit repository metadata.
        'enabled' => env('WTF_HAPPENED_AI_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('WTF_HAPPENED_AI_MODEL'),
        // Separate consent for bounded committed PHP excerpts. Redaction is best effort.
        'include_diffs' => env('WTF_HAPPENED_AI_INCLUDE_DIFFS', false),
        'max_payload_bytes' => 40000,
        'max_diff_bytes' => 20000,
        'timeout_seconds' => 20,
    ],
];

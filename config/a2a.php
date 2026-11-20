<?php

declare(strict_types=1);

return [
    'path' => env('A2A_PATH', ''),
    'base_url' => env('A2A_BASE_URL', 'http://127.0.0.1:9999'),
    'name' => env('A2A_NAME', 'Laravel A2A Agent'),
    'description' => env('A2A_DESCRIPTION', 'A2A agent served from Laravel.'),
    'version' => env('A2A_VERSION', '0.1.0'),
    'executor' => env('A2A_EXECUTOR'),
    'agent' => env('A2A_AGENT'),
    'store' => env('A2A_STORE', 'memory'),
    'file_store' => env('A2A_FILE_STORE'),
    'middleware' => [],
    'queue' => [
        'enabled' => (bool) env('A2A_QUEUE', false),
        'connection' => env('A2A_QUEUE_CONNECTION'),
        'name' => env('A2A_QUEUE_NAME', 'a2a'),
        'timeout' => (int) env('A2A_QUEUE_TIMEOUT', 30),
    ],
    'auth' => [
        'enabled' => (bool) env('A2A_AUTH', false),
        'guard' => env('A2A_AUTH_GUARD', 'sanctum'),
        'abilities' => [],
    ],
    'capabilities' => [
        'streaming' => true,
        'push_notifications' => true,
        'extended_agent_card' => true,
    ],
    'agents' => [],
    'card_cache_ttl' => 3600,
    'prune_days' => 14,
    'subscribe_timeout' => (int) env('A2A_SUBSCRIBE_TIMEOUT', 15),
];

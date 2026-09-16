<?php

return [
    'queue' => [
        'connection' => env('WORKOUT_ANALYSIS_QUEUE_CONNECTION', 'redis'),
        'name' => env('WORKOUT_ANALYSIS_QUEUE', 'workout-analysis'),
    ],
    'execution' => [
        'max_attempts' => 3,
        'processing_timeout_seconds' => 120,
        'pending_recovery_delay_seconds' => 60,
        'retry_delays_seconds' => [5, 30],
    ],
    'recovery' => [
        'batch_size' => 100,
        'max_candidates' => 1000,
    ],
];

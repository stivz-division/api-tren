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
    'ai' => [
        'max_attempts' => 3,
        'processing_timeout_seconds' => 120,
        'pending_recovery_delay_seconds' => 60,
        'retry_delays_seconds' => [5, 30],
        'max_input_bytes' => 250000,
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('WORKOUT_ANALYSIS_AI_MODEL', 'gpt-5.4-mini'),
            'connect_timeout_seconds' => 5,
            'timeout_seconds' => 45,
            'max_output_tokens' => 4000,
        ],
    ],
    'history' => [
        'same_program_limit' => 20,
        'other_programs_limit' => 20,
    ],
    'recovery' => [
        'batch_size' => 100,
        'max_candidates' => 1000,
    ],
];

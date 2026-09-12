<?php

return [
    'mutation_lock' => [
        'store' => env('WORKOUT_EXECUTION_LOCK_STORE', 'redis'),
        'lock_seconds' => (int) env('WORKOUT_EXECUTION_LOCK_SECONDS', 60),
        'wait_seconds' => (int) env('WORKOUT_EXECUTION_LOCK_WAIT_SECONDS', 3),
    ],
];

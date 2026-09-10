<?php

return [
    'mutation_lock' => [
        'store' => env('WORKOUT_PLANNING_LOCK_STORE', 'redis'),
        'lock_seconds' => (int) env('WORKOUT_PLANNING_LOCK_SECONDS', 10),
        'wait_seconds' => (int) env('WORKOUT_PLANNING_LOCK_WAIT_SECONDS', 3),
    ],
];

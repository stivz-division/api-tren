<?php

use App\Providers\AppServiceProvider;
use App\WorkoutExecution\Infrastructure\Providers\WorkoutExecutionServiceProvider;
use App\WorkoutPlanning\Infrastructure\Providers\WorkoutPlanningServiceProvider;

return [
    AppServiceProvider::class,
    WorkoutPlanningServiceProvider::class,
    WorkoutExecutionServiceProvider::class,
];

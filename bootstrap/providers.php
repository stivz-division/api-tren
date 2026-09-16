<?php

use App\Providers\AppServiceProvider;
use App\WorkoutAnalysis\Infrastructure\Providers\WorkoutAnalysisServiceProvider;
use App\WorkoutExecution\Infrastructure\Providers\WorkoutExecutionServiceProvider;
use App\WorkoutPlanning\Infrastructure\Providers\WorkoutPlanningServiceProvider;

return [
    AppServiceProvider::class,
    WorkoutAnalysisServiceProvider::class,
    WorkoutExecutionServiceProvider::class,
    WorkoutPlanningServiceProvider::class,
];

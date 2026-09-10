<?php

namespace App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday;

final readonly class GetTrainingProgramForWeekdayInput
{
    public function __construct(
        public private(set) int $userId,
        public private(set) int $weekday,
    ) {}
}

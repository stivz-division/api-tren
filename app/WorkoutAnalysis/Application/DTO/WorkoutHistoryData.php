<?php

namespace App\WorkoutAnalysis\Application\DTO;

final readonly class WorkoutHistoryData
{
    /**
     * @param  list<HistoricalWorkoutData>  $sameProgram
     * @param  list<HistoricalWorkoutData>  $otherPrograms
     */
    public function __construct(
        public private(set) array $sameProgram,
        public private(set) array $otherPrograms,
    ) {}
}

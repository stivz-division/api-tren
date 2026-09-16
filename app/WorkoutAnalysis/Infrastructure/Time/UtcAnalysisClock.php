<?php

namespace App\WorkoutAnalysis\Infrastructure\Time;

use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

final readonly class UtcAnalysisClock implements AnalysisClock
{
    public function now(): DateTimeImmutable
    {
        return CarbonImmutable::now('UTC')->toDateTimeImmutable();
    }
}

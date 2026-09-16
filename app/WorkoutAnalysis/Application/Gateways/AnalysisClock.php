<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use DateTimeImmutable;

interface AnalysisClock
{
    public function now(): DateTimeImmutable;
}

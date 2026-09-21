<?php

namespace App\WorkoutAnalysis\Application\Exceptions;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use RuntimeException;

final class AIProviderFailed extends RuntimeException
{
    public function __construct(public readonly AnalysisFailureCode $failureCode)
    {
        parent::__construct('Не удалось получить заключение ИИ: '.$failureCode->value);
    }
}

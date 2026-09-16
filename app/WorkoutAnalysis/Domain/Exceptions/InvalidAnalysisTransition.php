<?php

namespace App\WorkoutAnalysis\Domain\Exceptions;

use DomainException;

final class InvalidAnalysisTransition extends DomainException
{
    public function __construct()
    {
        parent::__construct('Нельзя повторно запустить выполняющийся анализ.');
    }
}

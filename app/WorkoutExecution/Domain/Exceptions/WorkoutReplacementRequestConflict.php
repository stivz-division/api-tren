<?php

namespace App\WorkoutExecution\Domain\Exceptions;

use DomainException;

final class WorkoutReplacementRequestConflict extends DomainException
{
    public function __construct(string $requestId)
    {
        parent::__construct("Запрос замены {$requestId} уже выполнен с другими параметрами.");
    }
}

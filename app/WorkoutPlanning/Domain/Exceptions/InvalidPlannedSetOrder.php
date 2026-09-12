<?php

namespace App\WorkoutPlanning\Domain\Exceptions;

use DomainException;

final class InvalidPlannedSetOrder extends DomainException
{
    public function __construct()
    {
        parent::__construct('Позиции подходов должны образовывать непрерывный порядок, начиная с одного.');
    }
}

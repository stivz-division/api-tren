<?php

namespace App\WorkoutPlanning\Presentation\Http\Support;

final class WorkingWeightConverter
{
    public static function kilogramsToGrams(int|float|string $kilograms): int
    {
        return (int) round((float) $kilograms * 1000);
    }

    public static function gramsToKilograms(int $grams): int|float
    {
        $kilograms = $grams / 1000;

        return floor($kilograms) === $kilograms
            ? (int) $kilograms
            : $kilograms;
    }
}

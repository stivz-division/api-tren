<?php

namespace App\WorkoutExecution\Presentation\Http\Support;

final class WorkingWeightConverter
{
    public const int MAX_KILOGRAMS = 1_000_000_000;

    public static function kilogramsToGrams(int|float|string $kilograms): int
    {
        [$wholeKilograms, $fractionalKilograms] = array_pad(
            explode('.', (string) $kilograms, 2),
            2,
            '',
        );

        $fractionalGrams = (int) str_pad($fractionalKilograms, 3, '0');

        return ((int) $wholeKilograms * 1000) + $fractionalGrams;
    }

    public static function gramsToKilograms(int $grams): int|float
    {
        $kilograms = $grams / 1000;

        return floor($kilograms) === $kilograms
            ? (int) $kilograms
            : $kilograms;
    }
}

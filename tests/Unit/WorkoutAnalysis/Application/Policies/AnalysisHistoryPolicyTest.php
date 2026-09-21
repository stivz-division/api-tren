<?php

use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;

it('defaults both independent history limits to twenty', function () {
    $policy = new AnalysisHistoryPolicy;

    expect($policy->sameProgramLimit)->toBe(20);
    expect($policy->otherProgramsLimit)->toBe(20);
});

it('accepts independent positive limits above the default window', function () {
    $policy = new AnalysisHistoryPolicy(28, 5);

    expect($policy->sameProgramLimit)->toBe(28);
    expect($policy->otherProgramsLimit)->toBe(5);
});

it('rejects nonpositive limits for either history window', function (int $same, int $other) {
    expect(fn () => new AnalysisHistoryPolicy($same, $other))->toThrow(InvalidArgumentException::class);
})->with(['zero same' => [0, 20], 'negative same' => [-1, 20], 'zero other' => [20, 0], 'negative other' => [20, -1]]);

<?php

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExcludedExerciseSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisContextSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisPayload;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutDeviationResultCodec;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

/** @return array<string, mixed> */
$fixture = static function (string $name): array {
    $contents = file_get_contents(__DIR__.'/../../../Support/WorkoutAnalysis/Fixtures/'.$name.'-v1.json');
    if ($contents === false) {
        throw new LogicException('Нет v1 fixture.');
    }

    return AnalysisPayload::object(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
};

it('reads the genuine version one snapshot result and history context', function () use ($fixture) {
    $snapshots = new CompletedWorkoutSnapshotCodec;
    $results = new WorkoutDeviationResultCodec($snapshots);
    $contexts = new AnalysisContextSnapshotCodec($snapshots, $results);

    $snapshot = $snapshots->decode($fixture('snapshot'), 1);
    $result = $results->decode($fixture('result'), 1, $snapshot);
    $context = $contexts->decode($fixture('context'), 1, $result);

    expect($result->volume->planned)->toBe(500000);
    expect($snapshot->excludedExercises)->toBe([]);
    expect($context->sameProgram->all()[0]->deviations->snapshot->workoutSessionId->value)->toBe(50);
    expect($snapshots->encode($snapshot, 1))->toEqual($fixture('snapshot'));
    expect($results->encode($result, 1))->toEqual($fixture('result'));
});

it('accepts mixed nested versions without canonicalizing them to the latest version', function (int $snapshotVersion, int $resultVersion, int $contextVersion) use ($fixture) {
    $snapshots = new CompletedWorkoutSnapshotCodec;
    $results = new WorkoutDeviationResultCodec($snapshots);
    $contexts = new AnalysisContextSnapshotCodec($snapshots, $results);
    $current = WorkoutAnalysisFixture::result();
    $payload = $fixture('context');
    $window = AnalysisPayload::object($payload['same_program']);
    $entries = AnalysisPayload::list($window['entries']);
    $entry = AnalysisPayload::object($entries[0]);
    $snapshot = $snapshots->decode(AnalysisPayload::object($entry['snapshot']), 1);
    $result = $results->decode(AnalysisPayload::object($entry['deviations']), 1, $snapshot);
    $entry['snapshot'] = $snapshots->encode($snapshot, $snapshotVersion);
    $entry['snapshot_version'] = $snapshotVersion;
    $entry['deviations'] = $results->encode($result, $resultVersion);
    $entry['deviations_version'] = $resultVersion;
    $window['entries'] = [$entry];
    $payload['same_program'] = $window;

    $restored = $contexts->decode($payload, $contextVersion, $current);

    expect($restored->sameProgram->all()[0]->deviations)->toEqual($result);
    $corruptSnapshot = AnalysisPayload::object($entry['snapshot']);
    $corruptSnapshot['unexpected'] = true;
    $entry['snapshot'] = $corruptSnapshot;
    $window['entries'] = [$entry];
    $payload['same_program'] = $window;
    expect(fn () => $contexts->decode($payload, $contextVersion, $current))->toThrow(UnexpectedValueException::class);
})->with([
    'all legacy' => [1, 1, 1],
    'new result old snapshot' => [1, 2, 2],
    'old result new snapshot' => [2, 1, 2],
    'legacy context new entries' => [2, 2, 1],
    'all latest' => [2, 2, 2],
]);

it('round trips version two exclusions and rejects incorrect no comparison flags', function (bool $allExcluded) {
    $snapshots = new CompletedWorkoutSnapshotCodec;
    $results = new WorkoutDeviationResultCodec($snapshots);
    $original = WorkoutAnalysisFixture::result()->snapshot;
    $snapshot = new CompletedWorkoutSnapshot(
        $original->workoutSessionId, $original->userId, $original->trainingProgramId,
        $original->programName, $original->completedAt,
        new ExercisePerformanceCollection(...($allExcluded ? [] : $original->exercises->all())),
        [new ExcludedExerciseSnapshot(
            new ExerciseId(20),
            new ExerciseId(30),
            new ExercisePosition($allExcluded ? 1 : 2),
        )],
    );
    $result = (new WorkoutDeviationCalculator)->calculate($snapshot);
    $snapshotPayload = $snapshots->encode($snapshot);
    $resultPayload = $results->encode($result);

    expect($snapshots->decode($snapshotPayload, 2))->toEqual($snapshot);
    expect($results->decode($resultPayload, 2, $snapshot))->toEqual($result);
    expect(fn () => $snapshots->encode($snapshot, 1))->toThrow(UnexpectedValueException::class);
    expect(fn () => $results->encode($result, 1))->toThrow(UnexpectedValueException::class);
    $resultPayload['has_comparable_exercises'] = $allExcluded;
    expect(fn () => $results->decode($resultPayload, 2, $snapshot))->toThrow(UnexpectedValueException::class);
})->with(['partial' => false, 'all' => true]);

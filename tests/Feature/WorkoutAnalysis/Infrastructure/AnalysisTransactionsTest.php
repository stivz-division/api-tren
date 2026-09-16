<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('holds the user row lock until the outer transaction commits', function (): void {
    $user = User::factory()->create();
    config()->set('database.connections.analysis_contender', DB::connection()->getConfig());
    $contender = DB::connection('analysis_contender');
    $contender->statement("SET lock_timeout = '50ms'");
    DB::beginTransaction();
    try {
        app(AnalysisTransaction::class)->execute(new UserId($user->id), static fn (): string => 'done');

        expect(fn () => $contender->table('users')->where('id', $user->id)->lockForUpdate()->first())
            ->toThrow(QueryException::class, 'lock timeout');

        DB::commit();

        expect($contender->table('users')->where('id', $user->id)->lockForUpdate()->value('id'))->toBe($user->id);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('analysis_contender');
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL row locks.');

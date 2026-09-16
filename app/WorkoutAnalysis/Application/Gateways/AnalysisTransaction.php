<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use Closure;

interface AnalysisTransaction
{
    /**
     * Сериализует изменения анализа пользователя и выполняет callback в транзакции.
     * Блокировка сохраняется до commit/rollback внешней транзакции.
     * Callback не должен автоматически повторяться адаптером.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(UserId $userId, Closure $callback): mixed;

    /**
     * Вызывает callback только после успешного внешнего commit и освобождения блокировки.
     * При rollback callback отбрасывается. Ошибка callback не откатывает сохранённые данные.
     *
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Closure $callback): void;
}

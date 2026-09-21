<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryQuery;

interface WorkoutHistoryProvider
{
    /**
     * Читается внутри AnalysisTransaction на том же соединении с БД, без ожидания чужих Job.
     * Возвращает согласованные снимки обоих окон и доступные готовые дополнения.
     * Только Completed пользователя, кроме текущей сессии, со временем строго меньше completedBefore.
     * Отдельные последние окна той же и всех остальных программ: completedAt DESC, ID DESC перед лимитом.
     * План и факт берутся из истории, без чтения актуального плана. Отсутствующие расчёты/дополнения дают null;
     * готовый пустой результат рекомендаций остаётся объектом. Ошибки чтения не заменяются пустыми окнами.
     */
    public function read(WorkoutHistoryQuery $query): WorkoutHistoryData;
}

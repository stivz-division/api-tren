<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class RecommendationBatch
{
    /** @var list<RecommendationProposal> */
    public array $proposals;

    /** @var list<string> */
    public array $rejectedReasons;

    /**
     * @param  array<int, RecommendationProposal>  $proposals
     * @param  array<int, string>  $rejectedReasons
     */
    public function __construct(
        array $proposals,
        public ?string $noChangeReason = null,
        array $rejectedReasons = [],
        public ?string $model = null,
        public ?string $responseId = null,
        public int $promptVersion = 1,
        public int $schemaVersion = 1,
    ) {
        if (! array_is_list($proposals) || ! array_is_list($rejectedReasons)
            || $promptVersion < 1 || $schemaVersion < 1
            || (($model === null) !== ($responseId === null))
            || ($model !== null && trim($model) === '')
            || ($responseId !== null && trim($responseId) === '')) {
            throw new InvalidArgumentException('Некорректный список или метаданные рекомендаций.');
        }
        foreach ($rejectedReasons as $reason) {
            if (trim($reason) === '') {
                throw new InvalidArgumentException('Причина отклонения не может быть пустой.');
            }
        }
        $this->proposals = $proposals;
        $this->rejectedReasons = $rejectedReasons;
        if ($proposals === [] && $rejectedReasons === [] && ($noChangeReason === null || trim($noChangeReason) === '')) {
            throw new InvalidArgumentException('Отсутствие рекомендаций требует обоснования.');
        }
        if ($proposals !== [] && $noChangeReason !== null) {
            throw new InvalidArgumentException('Обоснование отсутствия изменений несовместимо с рекомендациями.');
        }
    }
}

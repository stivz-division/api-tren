<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutSessionHistoryPageDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class WorkoutSessionHistoryCollection extends ResourceCollection
{
    /** @var class-string<WorkoutSessionResource> */
    public $collects = WorkoutSessionResource::class;

    public function __construct(private readonly WorkoutSessionHistoryPageDTO $page)
    {
        parent::__construct($page->sessions);
    }

    /** @return array<string, array<string, int|string|null>> */
    public function with(Request $request): array
    {
        return [
            'links' => [
                'prev' => $this->urlForCursor($request, $this->page->previousCursor),
                'next' => $this->urlForCursor($request, $this->page->nextCursor),
            ],
            'meta' => [
                'per_page' => $this->page->perPage,
                'prev_cursor' => $this->page->previousCursor,
                'next_cursor' => $this->page->nextCursor,
            ],
        ];
    }

    private function urlForCursor(Request $request, ?string $cursor): ?string
    {
        return $cursor === null
            ? null
            : $request->fullUrlWithQuery(['cursor' => $cursor]);
    }
}

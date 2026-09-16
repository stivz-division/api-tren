<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\MetricDeviationDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read MetricDeviationDTO $resource */
final class CountDeviationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MetricDeviationDTO $value */
        $value = $this->resource;

        return [
            'planned' => $value->planned,
            'actual' => $value->actual,
            'difference' => $value->difference,
            'percentage' => $value->percentage,
        ];
    }
}

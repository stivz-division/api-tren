<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\MetricDeviationDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read MetricDeviationDTO $resource */
final class KilogramDeviationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MetricDeviationDTO $value */
        $value = $this->resource;

        return [
            'planned' => $value->planned / 1000,
            'actual' => $value->actual / 1000,
            'difference' => $value->difference / 1000,
            'percentage' => $value->percentage,
        ];
    }
}

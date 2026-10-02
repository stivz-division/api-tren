<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

final class GetExerciseController extends Controller
{
    #[PathParameter('exercise', type: 'int<1, max>')]
    #[OpenApiResponse(
        type: 'array{data: array{id: int, code: string, name: string, description: string|null, video_url: string|null}}',
    )]
    #[OpenApiResponse(
        status: 404,
        description: 'Exercise not found.',
        type: 'array{message: string}',
    )]
    public function __invoke(Exercise $exercise): JsonResponse
    {
        return response()->json([
            'data' => $exercise->only(['id', 'code', 'name', 'description', 'video_url']),
        ]);
    }
}

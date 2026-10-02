<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

final class ExerciseController extends Controller
{
    #[OpenApiResponse(
        type: 'array{data: list<array{id: int, code: string, name: string, description: string|null, video_url: string|null}>}',
    )]
    public function __invoke(): JsonResponse
    {
        $exercises = Exercise::query()
            ->select(['id', 'code', 'name', 'description', 'video_url'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $exercises,
        ]);
    }
}

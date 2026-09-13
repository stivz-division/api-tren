<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ExerciseController extends Controller
{
    #[OpenApiResponse(
        type: 'array{data: list<array{id: int, code: string, name: string}>}',
    )]
    public function __invoke(): JsonResponse
    {
        $exercises = Exercise::query()
            ->select(['id', 'code', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $exercises,
        ]);
    }
}

<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Tests\TestCase;

$openApiHttpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

$expectedOpenApiOperations = [
    'DELETE /training-programs/{trainingProgramId}',
    'GET /exercises',
    'GET /training-programs',
    'GET /training-programs/weekdays/{weekday}',
    'GET /workout-sessions',
    'GET /workout-sessions/active',
    'POST /auth',
    'POST /training-programs',
    'POST /workout-sessions/{workoutSessionId}/cancel',
    'POST /workout-sessions/{workoutSessionId}/complete',
    'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/complete',
    'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/reopen',
    'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/skip',
    'PUT /training-programs/{trainingProgramId}',
    'PUT /workout-sessions/active',
    'PUT /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/sets',
];

/**
 * @param  array<array-key, mixed>  $value
 * @param  list<array-key>  $path
 */
$openApiValueAt = static function (array $value, array $path): mixed {
    $current = $value;

    foreach ($path as $segment) {
        if ((! is_int($segment) && ! is_string($segment))
            || ! is_array($current)
            || ! array_key_exists($segment, $current)) {
            $pointer = json_encode($path, JSON_THROW_ON_ERROR);

            throw new UnexpectedValueException("OpenAPI value [{$pointer}] is missing.");
        }

        $current = $current[$segment];
    }

    return $current;
};

/**
 * @param  array<array-key, mixed>  $value
 * @param  list<array-key>  $path
 * @return array<array-key, mixed>
 */
$openApiArrayAt = static function (array $value, array $path) use ($openApiValueAt): array {
    $result = $openApiValueAt($value, $path);

    if (! is_array($result)) {
        $pointer = json_encode($path, JSON_THROW_ON_ERROR);

        throw new UnexpectedValueException("OpenAPI value [{$pointer}] must be an array.");
    }

    return $result;
};

/**
 * @param  array<array-key, mixed>  $value
 * @param  list<array-key>  $path
 */
$openApiStringAt = static function (array $value, array $path) use ($openApiValueAt): string {
    $result = $openApiValueAt($value, $path);

    if (! is_string($result)) {
        $pointer = json_encode($path, JSON_THROW_ON_ERROR);

        throw new UnexpectedValueException("OpenAPI value [{$pointer}] must be a string.");
    }

    return $result;
};

/**
 * @param  array<array-key, mixed>  $value
 * @param  list<array-key>  $path
 */
$openApiIntegerAt = static function (array $value, array $path) use ($openApiValueAt): int {
    $result = $openApiValueAt($value, $path);

    if (! is_int($result)) {
        $pointer = json_encode($path, JSON_THROW_ON_ERROR);

        throw new UnexpectedValueException("OpenAPI value [{$pointer}] must be an integer.");
    }

    return $result;
};

/**
 * @param  array<array-key, mixed>  $value
 * @param  list<array-key>  $path
 */
$openApiBooleanAt = static function (array $value, array $path) use ($openApiValueAt): bool {
    $result = $openApiValueAt($value, $path);

    if (! is_bool($result)) {
        $pointer = json_encode($path, JSON_THROW_ON_ERROR);

        throw new UnexpectedValueException("OpenAPI value [{$pointer}] must be a boolean.");
    }

    return $result;
};

/**
 * @param  array<array-key, mixed>  $document
 * @param  array<array-key, mixed>  $value
 * @return array<array-key, mixed>
 */
$resolveOpenApiReference = static function (array $document, array $value) use (
    $openApiArrayAt,
    $openApiStringAt,
): array {
    while (array_key_exists('$ref', $value)) {
        $reference = $openApiStringAt($value, ['$ref']);

        if (! str_starts_with($reference, '#/')) {
            throw new UnexpectedValueException('Only local OpenAPI references are supported by this test.');
        }

        $segments = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($reference, 2)),
        );
        $value = $openApiArrayAt($document, $segments);
    }

    return $value;
};

/**
 * @param  array<array-key, mixed>  $document
 * @return array<array-key, mixed>
 */
$openApiOperation = static function (
    array $document,
    string $method,
    string $path,
) use ($openApiArrayAt): array {
    return $openApiArrayAt($document, ['paths', $path, strtolower($method)]);
};

/**
 * @param  array<array-key, mixed>  $document
 * @param  array<array-key, mixed>  $operation
 * @return array<array-key, mixed>
 */
$openApiRequestSchema = static function (array $document, array $operation) use (
    $openApiArrayAt,
    $resolveOpenApiReference,
): array {
    return $resolveOpenApiReference(
        $document,
        $openApiArrayAt($operation, ['requestBody', 'content', 'application/json', 'schema']),
    );
};

/**
 * @param  array<array-key, mixed>  $document
 * @param  array<array-key, mixed>  $operation
 * @return array<array-key, mixed>
 */
$openApiResponseSchema = static function (
    array $document,
    array $operation,
    string $status,
) use ($openApiArrayAt, $resolveOpenApiReference): array {
    $response = $resolveOpenApiReference(
        $document,
        $openApiArrayAt($operation, ['responses', $status]),
    );

    return $resolveOpenApiReference(
        $document,
        $openApiArrayAt($response, ['content', 'application/json', 'schema']),
    );
};

/**
 * @return array{live: array<array-key, mixed>, tracked: array<array-key, mixed>}
 */
$loadOpenApiDocuments = static function (TestCase $testCase): array {
    $testCase->withoutMiddleware(RestrictedDocsAccess::class);
    $liveDocument = $testCase->getJson('/docs/api.json')
        ->assertOk()
        ->json();
    $trackedJson = file_get_contents(base_path('openapi.json'));

    if (! is_array($liveDocument) || $trackedJson === false) {
        throw new UnexpectedValueException('OpenAPI documents cannot be loaded.');
    }

    $trackedDocument = json_decode($trackedJson, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($trackedDocument)) {
        throw new UnexpectedValueException('Tracked OpenAPI document must be a JSON object.');
    }

    return [
        'live' => $liveDocument,
        'tracked' => $trackedDocument,
    ];
};

it('keeps API documentation unavailable outside the local environment', function (string $uri): void {
    $this->get($uri)->assertForbidden();
})->with([
    'interactive documentation' => ['/docs/api'],
    'OpenAPI document' => ['/docs/api.json'],
]);

it('documents exactly every application API operation with the intended security', function () use (
    $expectedOpenApiOperations,
    $loadOpenApiDocuments,
    $openApiArrayAt,
    $openApiHttpMethods,
    $openApiOperation,
    $openApiStringAt,
): void {
    foreach ($loadOpenApiDocuments($this) as $source => $document) {
        $actualOperations = [];

        foreach ($openApiArrayAt($document, ['paths']) as $path => $pathItem) {
            if (! is_string($path) || ! is_array($pathItem)) {
                throw new UnexpectedValueException('Every OpenAPI path item must be an object keyed by a string path.');
            }

            foreach ($pathItem as $method => $operation) {
                if (is_string($method) && in_array($method, $openApiHttpMethods, true)) {
                    $actualOperations[] = strtoupper($method).' '.$path;
                }
            }
        }

        sort($actualOperations);

        $this->assertSame('3.1.0', $openApiStringAt($document, ['openapi']), "{$source}: OpenAPI version");
        $this->assertSame('Training API', $openApiStringAt($document, ['info', 'title']), "{$source}: API title");
        $this->assertStringEndsWith('/api', $openApiStringAt($document, ['servers', 0, 'url']), "{$source}: API server");
        $this->assertSame($expectedOpenApiOperations, $actualOperations, "{$source}: operation coverage");
        $this->assertSame(
            ['type' => 'http', 'scheme' => 'bearer'],
            $openApiArrayAt($document, ['components', 'securitySchemes', 'http']),
            "{$source}: bearer security scheme",
        );
        $this->assertSame([['http' => []]], $openApiArrayAt($document, ['security']), "{$source}: global security");
        $this->assertSame([], $openApiArrayAt($openApiOperation($document, 'POST', '/auth'), ['security']));

        foreach ($actualOperations as $operationKey) {
            if ($operationKey === 'POST /auth') {
                continue;
            }

            [$method, $path] = explode(' ', $operationKey, 2);
            $responses = $openApiArrayAt($openApiOperation($document, $method, $path), ['responses']);

            $this->assertArrayHasKey('401', $responses, "{$source}: {$operationKey} must document 401");
        }
    }
});

it('documents authentication and request validation contracts', function () use (
    $loadOpenApiDocuments,
    $openApiArrayAt,
    $openApiBooleanAt,
    $openApiIntegerAt,
    $openApiOperation,
    $openApiRequestSchema,
    $openApiStringAt,
): void {
    foreach ($loadOpenApiDocuments($this) as $source => $document) {
        $auth = $openApiOperation($document, 'POST', '/auth');
        $authSchema = $openApiRequestSchema($document, $auth);

        $this->assertTrue($openApiBooleanAt($auth, ['requestBody', 'required']), "{$source}: auth body must be required");
        $this->assertSame(['init_data'], array_keys($openApiArrayAt($authSchema, ['properties'])));
        $this->assertSame(['init_data'], $openApiArrayAt($authSchema, ['required']));
        $this->assertSame('string', $openApiStringAt($authSchema, ['properties', 'init_data', 'type']));

        $storeProgramSchema = $openApiRequestSchema(
            $document,
            $openApiOperation($document, 'POST', '/training-programs'),
        );
        $exerciseSchema = $openApiArrayAt($storeProgramSchema, ['properties', 'exercises', 'items']);
        $setSchema = $openApiArrayAt($exerciseSchema, ['properties', 'sets', 'items']);

        $this->assertSame(1, $openApiIntegerAt($storeProgramSchema, ['properties', 'weekday', 'minimum']));
        $this->assertSame(7, $openApiIntegerAt($storeProgramSchema, ['properties', 'weekday', 'maximum']));
        $this->assertSame(['string', 'null'], $openApiArrayAt($storeProgramSchema, ['properties', 'name', 'type']));
        $this->assertSame(255, $openApiIntegerAt($storeProgramSchema, ['properties', 'name', 'maxLength']));
        $this->assertSame(1, $openApiIntegerAt($storeProgramSchema, ['properties', 'exercises', 'minItems']));
        $this->assertSame(1, $openApiIntegerAt($exerciseSchema, ['properties', 'sets', 'minItems']));
        $this->assertSame(100, $openApiIntegerAt($exerciseSchema, ['properties', 'sets', 'maxItems']));
        $this->assertSame(['repetitions', 'working_weight_kg'], $openApiArrayAt($setSchema, ['required']));

        $updateProgramSchema = $openApiRequestSchema(
            $document,
            $openApiOperation($document, 'PUT', '/training-programs/{trainingProgramId}'),
        );
        $updateProgramProperties = $openApiArrayAt($updateProgramSchema, ['properties']);

        $this->assertSame(['name', 'exercises'], $openApiArrayAt($updateProgramSchema, ['required']));
        $this->assertArrayNotHasKey('weekday', $updateProgramProperties);

        $history = $openApiOperation($document, 'GET', '/workout-sessions');
        $historyParameters = [];

        foreach ($openApiArrayAt($history, ['parameters']) as $parameter) {
            if (! is_array($parameter)) {
                throw new UnexpectedValueException('Every OpenAPI parameter must be an object.');
            }

            $historyParameters[$openApiStringAt($parameter, ['name'])] = $parameter;
        }

        ksort($historyParameters);

        $this->assertSame(['cursor', 'per_page'], array_keys($historyParameters));
        $this->assertSame(15, $openApiIntegerAt($historyParameters, ['per_page', 'schema', 'default']));
        $this->assertSame(1, $openApiIntegerAt($historyParameters, ['per_page', 'schema', 'minimum']));
        $this->assertSame(50, $openApiIntegerAt($historyParameters, ['per_page', 'schema', 'maximum']));
        $this->assertSame(['string', 'null'], $openApiArrayAt($historyParameters, ['cursor', 'schema', 'type']));
        $this->assertSame(2048, $openApiIntegerAt($historyParameters, ['cursor', 'schema', 'maxLength']));

        $startSchema = $openApiRequestSchema(
            $document,
            $openApiOperation($document, 'PUT', '/workout-sessions/active'),
        );
        $this->assertSame(['training_program_id'], $openApiArrayAt($startSchema, ['required']));
        $this->assertSame('integer', $openApiStringAt($startSchema, ['properties', 'training_program_id', 'type']));
        $this->assertSame(1, $openApiIntegerAt($startSchema, ['properties', 'training_program_id', 'minimum']));

        $saveSetsSchema = $openApiRequestSchema(
            $document,
            $openApiOperation(
                $document,
                'PUT',
                '/workout-sessions/{workoutSessionId}/exercises/{exerciseId}/sets',
            ),
        );
        $completeExerciseSchema = $openApiRequestSchema(
            $document,
            $openApiOperation(
                $document,
                'POST',
                '/workout-sessions/{workoutSessionId}/exercises/{exerciseId}/complete',
            ),
        );

        $this->assertSame(['sets'], array_keys($openApiArrayAt($saveSetsSchema, ['properties'])));
        $this->assertSame(['sets'], array_keys($openApiArrayAt($completeExerciseSchema, ['properties'])));
        $this->assertSame(0, $openApiIntegerAt($saveSetsSchema, ['properties', 'sets', 'minItems']));
        $this->assertSame(1, $openApiIntegerAt($completeExerciseSchema, ['properties', 'sets', 'minItems']));
        $this->assertSame(100, $openApiIntegerAt($saveSetsSchema, ['properties', 'sets', 'maxItems']));
        $this->assertSame(100, $openApiIntegerAt($completeExerciseSchema, ['properties', 'sets', 'maxItems']));
    }
});

it('documents route parameters without internal request fields', function () use (
    $loadOpenApiDocuments,
    $openApiArrayAt,
    $openApiBooleanAt,
    $openApiIntegerAt,
    $openApiOperation,
    $openApiStringAt,
): void {
    $operationsWithPathParameters = [
        'DELETE /training-programs/{trainingProgramId}' => ['trainingProgramId'],
        'PUT /training-programs/{trainingProgramId}' => ['trainingProgramId'],
        'GET /training-programs/weekdays/{weekday}' => ['weekday'],
        'POST /workout-sessions/{workoutSessionId}/cancel' => ['workoutSessionId'],
        'POST /workout-sessions/{workoutSessionId}/complete' => ['workoutSessionId'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/complete' => ['workoutSessionId', 'exerciseId'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/reopen' => ['workoutSessionId', 'exerciseId'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/skip' => ['workoutSessionId', 'exerciseId'],
        'PUT /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/sets' => ['workoutSessionId', 'exerciseId'],
    ];
    $operationsWithoutRequestBodies = [
        'GET /exercises',
        'GET /training-programs',
        'DELETE /training-programs/{trainingProgramId}',
        'GET /training-programs/weekdays/{weekday}',
        'GET /workout-sessions',
        'GET /workout-sessions/active',
        'POST /workout-sessions/{workoutSessionId}/cancel',
        'POST /workout-sessions/{workoutSessionId}/complete',
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/reopen',
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/skip',
    ];

    foreach ($loadOpenApiDocuments($this) as $source => $document) {
        foreach ($operationsWithPathParameters as $operationKey => $expectedNames) {
            [$method, $path] = explode(' ', $operationKey, 2);
            $operation = $openApiOperation($document, $method, $path);
            $parameters = $openApiArrayAt($operation, ['parameters']);
            $actualNames = [];

            foreach ($parameters as $parameter) {
                if (! is_array($parameter)) {
                    throw new UnexpectedValueException('Every OpenAPI path parameter must be an object.');
                }

                $actualNames[] = $openApiStringAt($parameter, ['name']);
                $this->assertSame('path', $openApiStringAt($parameter, ['in']));
                $this->assertTrue($openApiBooleanAt($parameter, ['required']));
                $this->assertSame('integer', $openApiStringAt($parameter, ['schema', 'type']));
                $this->assertSame(1, $openApiIntegerAt($parameter, ['schema', 'minimum']));
            }

            $this->assertSame($expectedNames, $actualNames, "{$source}: {$operationKey}");
        }

        $weekday = $openApiOperation($document, 'GET', '/training-programs/weekdays/{weekday}');
        $this->assertSame(7, $openApiIntegerAt($weekday, ['parameters', 0, 'schema', 'maximum']));

        foreach ($operationsWithoutRequestBodies as $operationKey) {
            [$method, $path] = explode(' ', $operationKey, 2);

            $this->assertArrayNotHasKey(
                'requestBody',
                $openApiOperation($document, $method, $path),
                "{$source}: {$operationKey} must not document an internal request body",
            );
        }
    }
});

it('documents success resources and API error responses', function () use (
    $loadOpenApiDocuments,
    $openApiArrayAt,
    $openApiOperation,
    $openApiResponseSchema,
    $openApiStringAt,
    $resolveOpenApiReference,
): void {
    $expectedResponseStatuses = [
        'POST /auth' => ['200', '401', '429'],
        'GET /exercises' => ['200', '401'],
        'GET /training-programs' => ['200', '401'],
        'POST /training-programs' => ['201', '401', '409', '422'],
        'PUT /training-programs/{trainingProgramId}' => ['200', '401', '404', '409', '422'],
        'DELETE /training-programs/{trainingProgramId}' => ['204', '401', '404', '409'],
        'GET /training-programs/weekdays/{weekday}' => ['200', '401', '404', '422'],
        'GET /workout-sessions' => ['200', '401', '422'],
        'GET /workout-sessions/active' => ['200', '401'],
        'PUT /workout-sessions/active' => ['200', '401', '404', '409', '422'],
        'PUT /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/sets' => ['200', '401', '404', '409', '422'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/complete' => ['200', '401', '404', '409', '422'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/skip' => ['200', '401', '404', '409', '422'],
        'POST /workout-sessions/{workoutSessionId}/exercises/{exerciseId}/reopen' => ['200', '401', '404', '409', '422'],
        'POST /workout-sessions/{workoutSessionId}/complete' => ['200', '401', '404', '409', '422'],
        'POST /workout-sessions/{workoutSessionId}/cancel' => ['200', '401', '404', '409', '422'],
    ];

    foreach ($loadOpenApiDocuments($this) as $source => $document) {
        foreach ($expectedResponseStatuses as $operationKey => $expectedStatuses) {
            [$method, $path] = explode(' ', $operationKey, 2);
            $responses = $openApiArrayAt($openApiOperation($document, $method, $path), ['responses']);
            $actualStatuses = array_map(
                static fn (int|string $status): string => (string) $status,
                array_keys($responses),
            );

            foreach ($expectedStatuses as $expectedStatus) {
                $this->assertContains($expectedStatus, $actualStatuses, "{$source}: {$operationKey} response {$expectedStatus}");
            }
        }

        $authSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'POST', '/auth'),
            '200',
        );
        $this->assertSame(['token', 'token_type'], $openApiArrayAt($authSchema, ['required']));
        $this->assertSame('Bearer', $openApiStringAt($authSchema, ['properties', 'token_type', 'const']));

        $exerciseListSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'GET', '/exercises'),
            '200',
        );
        $exerciseSchema = $resolveOpenApiReference(
            $document,
            $openApiArrayAt($exerciseListSchema, ['properties', 'data', 'items']),
        );
        $this->assertSame(['id', 'code', 'name'], array_keys($openApiArrayAt($exerciseSchema, ['properties'])));
        $this->assertSame(['id', 'code', 'name'], $openApiArrayAt($exerciseSchema, ['required']));

        $integerResourceProperties = [
            'TrainingProgramResource' => ['id', 'weekday'],
            'PlannedExerciseResource' => ['exercise_id', 'position'],
            'PlannedSetResource' => ['position', 'repetitions'],
            'WorkoutSessionResource' => ['id', 'training_program_id', 'scheduled_weekday'],
            'WorkoutExerciseResource' => ['exercise_id', 'position'],
            'WorkoutSetResource' => ['position', 'repetitions'],
        ];

        foreach ($integerResourceProperties as $schemaName => $properties) {
            foreach ($properties as $property) {
                $this->assertSame(
                    'integer',
                    $openApiStringAt($document, ['components', 'schemas', $schemaName, 'properties', $property, 'type']),
                );
            }
        }

        foreach (['started_at', 'completed_at', 'cancelled_at'] as $property) {
            $this->assertSame(
                'date-time',
                $openApiStringAt(
                    $document,
                    ['components', 'schemas', 'WorkoutSessionResource', 'properties', $property, 'format'],
                ),
            );
        }

        $this->assertSame(
            ['in_progress', 'completed', 'cancelled'],
            $openApiArrayAt($document, ['components', 'schemas', 'WorkoutSessionResource', 'properties', 'status', 'enum']),
        );
        $this->assertSame(
            ['pending', 'completed', 'skipped'],
            $openApiArrayAt($document, ['components', 'schemas', 'WorkoutExerciseResource', 'properties', 'status', 'enum']),
        );
        $this->assertSame(
            'number',
            $openApiStringAt($document, ['components', 'schemas', 'PlannedSetResource', 'properties', 'working_weight_kg', 'type']),
        );
        $this->assertSame(
            'number',
            $openApiStringAt($document, ['components', 'schemas', 'WorkoutSetResource', 'properties', 'working_weight_kg', 'type']),
        );

        $activeSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'GET', '/workout-sessions/active'),
            '200',
        );
        $hasNullVariant = false;
        $hasWorkoutSessionVariant = false;

        foreach ($openApiArrayAt($activeSchema, ['anyOf']) as $variant) {
            if (! is_array($variant)) {
                throw new UnexpectedValueException('Every active workout response variant must be an object.');
            }

            $dataSchema = $openApiArrayAt($variant, ['properties', 'data']);
            $hasNullVariant = $hasNullVariant
                || (array_key_exists('type', $dataSchema) && $openApiStringAt($dataSchema, ['type']) === 'null');
            $hasWorkoutSessionVariant = $hasWorkoutSessionVariant
                || (array_key_exists('$ref', $dataSchema)
                    && $openApiStringAt($dataSchema, ['$ref']) === '#/components/schemas/WorkoutSessionResource');
        }

        $this->assertTrue($hasNullVariant);
        $this->assertTrue($hasWorkoutSessionVariant);

        $historySchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'GET', '/workout-sessions'),
            '200',
        );
        $historyData = $resolveOpenApiReference(
            $document,
            $openApiArrayAt($historySchema, ['properties', 'data']),
        );
        $this->assertSame(['data', 'links', 'meta'], $openApiArrayAt($historySchema, ['required']));
        $this->assertSame('array', $openApiStringAt($historyData, ['type']));
        $this->assertSame(
            '#/components/schemas/WorkoutSessionResource',
            $openApiStringAt($historyData, ['items', '$ref']),
        );
        $this->assertSame(
            ['string', 'null'],
            $openApiArrayAt($historySchema, ['properties', 'links', 'properties', 'prev', 'type']),
        );
        $this->assertSame(
            ['string', 'null'],
            $openApiArrayAt($historySchema, ['properties', 'meta', 'properties', 'next_cursor', 'type']),
        );

        $deleteOperation = $openApiOperation($document, 'DELETE', '/training-programs/{trainingProgramId}');
        $deleteResponse = $openApiArrayAt($deleteOperation, ['responses', '204']);
        $this->assertArrayNotHasKey('content', $deleteResponse);

        $unauthenticatedSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'GET', '/training-programs'),
            '401',
        );
        $notFoundSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'PUT', '/training-programs/{trainingProgramId}'),
            '404',
        );
        $conflictSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'PUT', '/training-programs/{trainingProgramId}'),
            '409',
        );
        $validationSchema = $openApiResponseSchema(
            $document,
            $openApiOperation($document, 'POST', '/training-programs'),
            '422',
        );

        $this->assertSame(['message'], $openApiArrayAt($unauthenticatedSchema, ['required']));
        $this->assertSame(['code', 'message'], $openApiArrayAt($notFoundSchema, ['required']));
        $this->assertSame(['code', 'message'], $openApiArrayAt($conflictSchema, ['required']));
        $this->assertSame(['message', 'errors'], $openApiArrayAt($validationSchema, ['required']));
    }
});

<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI;

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Application\Gateways\AIProvider;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class OpenAIAnalysisProvider implements AIProvider
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        private Factory $http,
        private Repository $config,
        private WorkoutAnalysisPrompt $prompt,
    ) {}

    public function analyze(WorkoutAnalysisId $analysisId, AnalysisContextSnapshot $context): WorkoutAIResult
    {
        $apiKey = $this->configuredString('openai.api_key');
        $model = $this->configuredString('openai.model');
        $connectTimeout = $this->configuredPositiveInteger('openai.connect_timeout_seconds');
        $timeout = $this->configuredPositiveInteger('openai.timeout_seconds');
        $maxOutputTokens = $this->configuredPositiveInteger('openai.max_output_tokens');
        $maxInputBytes = $this->configuredPositiveInteger('max_input_bytes');

        try {
            $input = $this->prompt->context($context);
        } catch (JsonException) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderRejected);
        }
        $instructions = $this->prompt->instructions();
        if (strlen($input) + strlen($instructions) > $maxInputBytes) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderRejected);
        }

        try {
            $response = $this->http->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'store' => false,
                    'max_output_tokens' => $maxOutputTokens,
                    'instructions' => $instructions,
                    'input' => [['role' => 'user', 'content' => $input]],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'workout_analysis',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable);
        }
        if (! $response->successful()) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderRejected);
        }

        try {
            $envelope = json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
            $data = $this->object($envelope);
            if (($data->error ?? null) !== null) {
                throw new UnexpectedValueException;
            }
            if ($this->containsRefusal($data->output ?? null)) {
                throw new AIProviderFailed(AnalysisFailureCode::AIRefused);
            }
            if (($data->status ?? null) === 'incomplete') {
                throw new AIProviderFailed(AnalysisFailureCode::IncompleteAIResponse);
            }
            $output = $this->list($data->output ?? null);
            $texts = [];
            foreach ($output as $item) {
                $message = $this->object($item);
                if (($message->type ?? null) === 'reasoning') {
                    continue;
                }
                if (($message->type ?? null) !== 'message' || ($message->status ?? null) !== 'completed') {
                    throw new UnexpectedValueException;
                }
                foreach ($this->list($message->content ?? null) as $content) {
                    $part = $this->object($content);
                    if (($part->type ?? null) !== 'output_text' || ($message->role ?? null) !== 'assistant') {
                        throw new UnexpectedValueException;
                    }
                    $texts[] = $this->nonblankString($part->text ?? null);
                }
            }
            if (($data->status ?? null) !== 'completed' || count($texts) !== 1) {
                throw new UnexpectedValueException;
            }
            $result = $this->object(json_decode($texts[0], flags: JSON_THROW_ON_ERROR));
            $this->requireKeys($result, ['current_workout', 'history', 'evidence']);
            $evidence = [];
            foreach ($this->list($result->evidence) as $item) {
                $reference = $this->object($item);
                $this->requireKeys($reference, ['workout_session_id', 'exercise_id']);
                if (! is_int($reference->workout_session_id) || ($reference->exercise_id !== null && ! is_int($reference->exercise_id))) {
                    throw new UnexpectedValueException;
                }
                $evidence[] = new AnalysisEvidenceReference(
                    $analysisId,
                    new WorkoutSessionId($reference->workout_session_id),
                    $reference->exercise_id === null ? null : new ExerciseId($reference->exercise_id),
                );
            }

            return new WorkoutAIResult(
                $analysisId,
                $context,
                $this->nonblankString($result->current_workout),
                $this->nonblankString($result->history),
                $this->nonblankString($data->model ?? null),
                $this->nonblankString($data->id ?? null),
                WorkoutAnalysisPrompt::VERSION,
                self::SCHEMA_VERSION,
                ...$evidence,
            );
        } catch (JsonException|UnexpectedValueException|InvalidArgumentException|InvalidAnalysisContext) {
            throw new AIProviderFailed(AnalysisFailureCode::InvalidAIResponse);
        }
    }

    private function containsRefusal(mixed $output): bool
    {
        if (! is_array($output)) {
            return false;
        }
        foreach ($output as $item) {
            if (! $item instanceof stdClass || ! is_array($item->content ?? null)) {
                continue;
            }
            foreach ($item->content as $part) {
                if ($part instanceof stdClass && ($part->type ?? null) === 'refusal') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['current_workout', 'history', 'evidence'],
            'properties' => [
                'current_workout' => ['type' => 'string'],
                'history' => ['type' => 'string'],
                'evidence' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['workout_session_id', 'exercise_id'],
                    'properties' => [
                        'workout_session_id' => ['type' => 'integer'],
                        'exercise_id' => ['type' => ['integer', 'null']],
                    ],
                ]],
            ],
        ];
    }

    private function configuredString(string $key): string
    {
        $value = $this->config->get('workout-analysis.ai.'.$key);
        if (! is_string($value) || trim($value) === '') {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderRejected);
        }

        return $value;
    }

    private function configuredPositiveInteger(string $key): int
    {
        $value = $this->config->get('workout-analysis.ai.'.$key);
        if (! is_int($value) || $value < 1) {
            throw new AIProviderFailed(AnalysisFailureCode::ProviderRejected);
        }

        return $value;
    }

    private function object(mixed $value): stdClass
    {
        if (! $value instanceof stdClass) {
            throw new UnexpectedValueException;
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException;
        }

        return $value;
    }

    private function nonblankString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException;
        }

        return $value;
    }

    /** @param list<string> $keys */
    private function requireKeys(stdClass $value, array $keys): void
    {
        $actual = array_keys(get_object_vars($value));
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new UnexpectedValueException;
        }
    }
}

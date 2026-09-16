<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class AnalysisPayload
{
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new UnexpectedValueException('Данные анализа должны содержать JSON-объект.');
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Данные анализа содержат некорректный ключ объекта.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Данные анализа должны содержать JSON-массив.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new UnexpectedValueException('Данные анализа должны содержать целое число.');
        }

        return $value;
    }

    public static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Данные анализа должны содержать строку.');
        }

        return $value;
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        $string = self::string($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $string, new DateTimeZone('UTC'));
        if ($date === false || self::formatDate($date) !== $string) {
            throw new UnexpectedValueException('Данные анализа содержат некорректную дату и время в UTC.');
        }

        return $date;
    }

    public static function formatDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** JSON object key order is insignificant; scalar types and list order remain significant. */
    public static function equals(mixed $expected, mixed $actual): bool
    {
        if (is_array($expected) && is_array($actual)) {
            if (count($expected) !== count($actual) || array_is_list($expected) !== array_is_list($actual)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! self::equals($value, $actual[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_float($expected) && is_int($actual)) {
            return $expected === (float) $actual;
        }

        return $expected === $actual;
    }
}

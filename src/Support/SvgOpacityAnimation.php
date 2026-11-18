<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Support;

/** 编辑器移除 animate 后可安全重建的 opacity SMIL 属性。 */
final class SvgOpacityAnimation
{
    public const ATTRIBUTE = 'data-cmf-svg-opacity';

    private const NUMBER = '(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?';

    private const KEYS = ['attributeName', 'values', 'dur', 'begin', 'repeatCount', 'keyTimes', 'fill', 'calcMode', 'keySplines', 'restart'];

    /** @return array<string, string>|null */
    public static function normalize(mixed $input): ?array
    {
        if (is_string($input)) {
            if (strlen($input) > 16384) {
                return null;
            }

            $input = json_decode($input, true, 4);
        }

        if (! is_array($input) || array_is_list($input) || array_diff(array_keys($input), self::KEYS) !== []) {
            return null;
        }

        foreach ($input as $value) {
            if (! is_string($value) || strlen($value) > 4096) {
                return null;
            }
        }

        if (($input['attributeName'] ?? null) !== 'opacity') {
            return null;
        }

        $values = self::numberList($input['values'] ?? '', ';', 32);
        $duration = self::time($input['dur'] ?? '', false);
        $begin = self::time($input['begin'] ?? '0s', true);

        if ($values === null || $duration === null || $begin === null) {
            return null;
        }

        $result = ['attributeName' => 'opacity', 'values' => implode(';', $values), 'dur' => $duration, 'begin' => $begin];

        if (isset($input['repeatCount'])) {
            $repeat = trim($input['repeatCount']);

            if ($repeat !== 'indefinite') {
                $number = self::number($repeat, 0, 1000000);

                if ($number === null || (float) $number <= 0) {
                    return null;
                }

                $repeat = $number;
            }

            $result['repeatCount'] = $repeat;
        }

        if (isset($input['keyTimes'])) {
            $times = self::numberList($input['keyTimes'], ';', 32);

            if ($times === null || count($times) !== count($values) || (float) $times[0] !== 0.0) {
                return null;
            }

            $previous = 0.0;

            foreach ($times as $time) {
                if ((float) $time < $previous) {
                    return null;
                }

                $previous = (float) $time;
            }

            if (($input['calcMode'] ?? 'linear') !== 'discrete' && $previous !== 1.0) {
                return null;
            }

            $result['keyTimes'] = implode(';', $times);
        }

        foreach (['fill' => ['remove', 'freeze'], 'calcMode' => ['linear', 'discrete', 'spline'], 'restart' => ['always', 'whenNotActive', 'never']] as $key => $allowed) {
            if (isset($input[$key])) {
                if (! in_array($input[$key], $allowed, true)) {
                    return null;
                }

                $result[$key] = $input[$key];
            }
        }

        if (($input['calcMode'] ?? 'linear') === 'spline') {
            $groups = explode(';', $input['keySplines'] ?? '');

            if (count($values) < 2 || count($groups) !== count($values) - 1 || ! isset($result['keyTimes'])) {
                return null;
            }

            $splines = [];

            foreach ($groups as $group) {
                $numbers = self::numberList(trim($group), '/[\s,]+/', 4);

                if ($numbers === null || count($numbers) !== 4) {
                    return null;
                }

                $splines[] = implode(' ', $numbers);
            }

            $result['keySplines'] = implode(';', $splines);
        } elseif (isset($input['keySplines'])) {
            return null;
        }

        return $result;
    }

    /** @return list<string>|null */
    private static function numberList(string $input, string $separator, int $maximum): ?array
    {
        $parts = str_starts_with($separator, '/') ? preg_split($separator, $input) : explode($separator, $input);

        if ($parts === false || count($parts) > $maximum) {
            return null;
        }

        $result = [];

        foreach ($parts as $part) {
            $number = self::number(trim($part), 0, 1);

            if ($number === null) {
                return null;
            }

            $result[] = $number;
        }

        return $result;
    }

    private static function number(string $input, float $minimum, float $maximum): ?string
    {
        if (preg_match('/^'.self::NUMBER.'$/D', $input) !== 1) {
            return null;
        }

        $number = (float) $input;

        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            return null;
        }

        return (string) $number;
    }

    private static function time(string $input, bool $isBegin): ?string
    {
        if (preg_match('/^('.($isBegin ? '[+-]?' : '').self::NUMBER.')(ms|s)?$/D', trim($input), $matches) !== 1) {
            return null;
        }

        $seconds = (float) $matches[1] / (($matches[2] ?? '') === 'ms' ? 1000 : 1);

        if (! is_finite($seconds) || (! $isBegin && ($seconds <= 0 || $seconds > 3600))) {
            return null;
        }

        return (string) $seconds.'s';
    }
}

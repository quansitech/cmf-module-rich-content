<?php

declare(strict_types=1);

namespace Quansitech\Cmf\RichContent\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quansitech\Cmf\RichContent\Support\SvgOpacityAnimation;

final class SvgOpacityAnimationTest extends TestCase
{
    public function test_normalizes_safe_spline_attributes_into_a_canonical_string_map(): void
    {
        $input = ['attributeName' => 'opacity', 'values' => ' 0 ; .5 ; 1 ', 'dur' => '1500ms', 'begin' => '-250ms', 'repeatCount' => '2.5', 'keyTimes' => '0;.5;1', 'fill' => 'freeze', 'calcMode' => 'spline', 'keySplines' => '0,0,1,1; .1 .2 .8 .9', 'restart' => 'whenNotActive'];

        $normalized = SvgOpacityAnimation::normalize($input);

        $this->assertSame(['attributeName' => 'opacity', 'values' => '0;0.5;1', 'dur' => '1.5s', 'begin' => '-0.25s', 'repeatCount' => '2.5', 'keyTimes' => '0;0.5;1', 'fill' => 'freeze', 'calcMode' => 'spline', 'restart' => 'whenNotActive', 'keySplines' => '0 0 1 1;0.1 0.2 0.8 0.9'], $normalized);
        $this->assertSame($normalized, SvgOpacityAnimation::normalize(json_encode($normalized, JSON_THROW_ON_ERROR)));
    }

    public function test_accepts_the_numeric_boundaries_and_defaults_begin(): void
    {
        $input = ['attributeName' => 'opacity', 'values' => implode(';', array_fill(0, 32, '1')), 'dur' => '3600000ms', 'repeatCount' => 'indefinite'];

        $normalized = SvgOpacityAnimation::normalize($input);

        $this->assertSame('3600s', $normalized['dur']);
        $this->assertSame('0s', $normalized['begin']);
        $this->assertSame('indefinite', $normalized['repeatCount']);
    }

    #[DataProvider('invalidAttributes')]
    public function test_rejects_unsafe_or_unsupported_animation_attributes(array $changes): void
    {
        $input = array_replace(['attributeName' => 'opacity', 'values' => '0;1', 'dur' => '4s'], $changes);

        $this->assertNull(SvgOpacityAnimation::normalize($input));
    }

    public static function invalidAttributes(): array
    {
        return [
            'target' => [['attributeName' => 'href']],
            'unknown key' => [['onbegin' => 'alert(1)']],
            'URL value' => [['values' => '0;javascript:alert(1)']],
            'negative opacity' => [['values' => '-.1;1']],
            'large opacity' => [['values' => '0;1.01']],
            'nonfinite' => [['values' => '0;1e999']],
            'too many values' => [['values' => implode(';', array_fill(0, 33, '0'))]],
            'empty values' => [['values' => '']],
            'zero duration' => [['dur' => '0s']],
            'long duration' => [['dur' => '3600.1s']],
            'event begin' => [['begin' => 'click']],
            'syncbase begin' => [['begin' => 'other.end']],
            'multiple begin' => [['begin' => '0s;1s']],
            'repeat limit' => [['repeatCount' => '1000001']],
            'negative repeat' => [['repeatCount' => '-1']],
            'invalid times' => [['keyTimes' => '0;.5;1']],
            'unordered times' => [['values' => '0;.5;1', 'keyTimes' => '0;1;.5']],
            'missing last time' => [['keyTimes' => '0;.5']],
            'invalid fill' => [['fill' => 'url(x)']],
            'invalid mode' => [['calcMode' => 'paced']],
            'missing spline' => [['calcMode' => 'spline', 'keyTimes' => '0;1']],
            'invalid spline' => [['calcMode' => 'spline', 'keyTimes' => '0;1', 'keySplines' => '0 0 2 1']],
            'extra spline' => [['keySplines' => '0 0 1 1']],
            'invalid restart' => [['restart' => 'click']],
            'nested value' => [['values' => ['0', '1']]],
            'boolean value' => [['dur' => true]],
        ];
    }

    public function test_rejects_non_objects_and_malformed_json(): void
    {
        foreach ([null, true, 42, 'null', '[]', '{', '{"attributeName":"opacity","values":"0;1","dur":4}', str_repeat('x', 16385)] as $input) {
            $this->assertNull(SvgOpacityAnimation::normalize($input));
        }
    }
}

<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Support;

final class Values
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function integer(mixed $value, int $default = 0): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : $default;
    }
}

<?php
namespace App\Services;
use App\Services\Security\Input;
final class EnvironmentFile
{
    public static function encode(array $values): string
    {
        $lines = [];
        foreach (Input::env($values) as $key => $value) {
            $lines[] =
                $key .
                '="' .
                str_replace(["\\", '"', '$'], ["\\\\", '\\"', '\\$'], $value) .
                '"';
        }
        return implode("\n", $lines) . "\n";
    }
}

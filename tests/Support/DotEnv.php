<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Support;

/**
 * Minimal .env loader for the tests. Variables already set in the real environment win.
 */
final class DotEnv
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if ($name !== '' && getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}

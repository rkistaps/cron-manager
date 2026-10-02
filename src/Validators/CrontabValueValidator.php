<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Validators;

use InvalidArgumentException;

/**
 * Checks values that end up on a crontab line. A newline would end the line early, and cron turns
 * every unescaped "%" into a newline, even inside shell quotes.
 */
final class CrontabValueValidator
{
    public static function assertSingleLine(string $label, string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s may not contain a newline', $label));
        }
    }

    public static function assertLineSafe(string $label, string $value): void
    {
        self::assertSingleLine($label, $value);

        if (str_contains($value, '%')) {
            throw new InvalidArgumentException(sprintf(
                '%s may not contain "%%", which cron turns into a newline. Move the command into a script instead',
                $label,
            ));
        }
    }

    public static function assertAbsolutePath(string $label, string $value): void
    {
        self::assertLineSafe($label, $value);

        if (!str_starts_with($value, '/')) {
            throw new InvalidArgumentException(sprintf('%s must be an absolute path, got "%s"', $label, $value));
        }
    }
}

<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Writers;

use InvalidArgumentException;
use rkistaps\CronManager\Exceptions\CrontabAccessException;
use rkistaps\CronManager\Interfaces\CrontabWriterInterface;

/**
 * A file of its own in /etc/cron.d. Lines there carry a user field, and writing needs root.
 */
final readonly class CronDWriter implements CrontabWriterInterface
{
    // cron skips files in cron.d whose names contain anything else, such as a dot
    private const string FILE_NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(
        private string $appId,
        private string $directory = '/etc/cron.d',
    ) {
        if (preg_match(self::FILE_NAME_PATTERN, $appId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'App id "%s" cannot name a file in %s: cron only reads names made of letters, digits, "-" and "_"',
                $appId,
                $directory,
            ));
        }
    }

    public function path(): string
    {
        return rtrim($this->directory, '/') . '/' . $this->appId;
    }

    public function read(): string
    {
        if (!file_exists($this->path())) {
            return '';
        }

        $content = @file_get_contents($this->path());
        if ($content === false) {
            throw new CrontabAccessException(sprintf('Could not read %s', $this->path()));
        }

        return $content;
    }

    public function write(string $crontab): void
    {
        if ($crontab === '') {
            if (file_exists($this->path()) && !@unlink($this->path())) {
                throw new CrontabAccessException(sprintf('Could not delete %s', $this->path()));
            }

            return;
        }

        // Write next to the target and rename, so cron never reads a half-written file.
        // The leading dot makes cron skip the temporary file.
        $temporary = sprintf('%s/.%s.%s.tmp', rtrim($this->directory, '/'), $this->appId, bin2hex(random_bytes(4)));

        if (@file_put_contents($temporary, $crontab) === false) {
            throw new CrontabAccessException(sprintf('Could not write %s', $temporary));
        }

        // cron ignores files in cron.d that are writable by group or others
        if (!@chmod($temporary, 0644) || !@rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new CrontabAccessException(sprintf('Could not write %s', $this->path()));
        }
    }

    public function requiresUserField(): bool
    {
        return true;
    }
}

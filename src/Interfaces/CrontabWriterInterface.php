<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Interfaces;

use rkistaps\CronManager\Exceptions\CrontabAccessException;

interface CrontabWriterInterface
{
    /**
     * @return string The whole crontab, or "" when there is none
     * @throws CrontabAccessException
     */
    public function read(): string;

    /**
     * Replaces the whole crontab
     *
     * @throws CrontabAccessException
     */
    public function write(string $crontab): void;

    /**
     * Whether every job line needs a user field after the schedule, as in /etc/cron.d
     */
    public function requiresUserField(): bool;
}

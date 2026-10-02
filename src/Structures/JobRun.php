<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

use DateTimeImmutable;
use rkistaps\CronManager\Enums\JobRunStatus;

final readonly class JobRun
{
    public function __construct(
        public string $jobName,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public int $exitCode,
    ) {
    }

    public function durationSeconds(): float
    {
        return (float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u');
    }

    public function status(): JobRunStatus
    {
        return $this->exitCode === 0 ? JobRunStatus::SUCCEEDED : JobRunStatus::FAILED;
    }
}

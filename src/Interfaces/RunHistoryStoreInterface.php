<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Interfaces;

use rkistaps\CronManager\Structures\JobRun;

interface RunHistoryStoreInterface
{
    public function record(JobRun $run): void;

    public function lastRun(string $jobName): ?JobRun;
}

<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

final readonly class SyncPlan
{
    /**
     * @param list<string> $added     Block lines a sync would add, markers included
     * @param list<string> $removed   Block lines a sync would remove, markers included
     * @param list<string> $unchanged Block lines a sync would keep
     * @param bool $blockCreated      The crontab has no block for this app yet
     * @param list<string> $jobsAdded   Names of jobs a sync would add
     * @param list<string> $jobsChanged Names of jobs whose lines a sync would rewrite
     * @param list<string> $jobsRemoved Names of jobs a sync would remove
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $unchanged,
        public bool $handEdited,
        public string $currentCrontab,
        public string $newCrontab,
        public bool $blockCreated = false,
        public array $jobsAdded = [],
        public array $jobsChanged = [],
        public array $jobsRemoved = [],
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->currentCrontab !== $this->newCrontab;
    }
}

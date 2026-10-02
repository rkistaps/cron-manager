<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

final readonly class SyncPlan
{
    /**
     * @param list<string> $added     Block lines a sync would add, markers included
     * @param list<string> $removed   Block lines a sync would remove, markers included
     * @param list<string> $unchanged Block lines a sync would keep
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $unchanged,
        public bool $handEdited,
        public string $currentCrontab,
        public string $newCrontab,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->currentCrontab !== $this->newCrontab;
    }
}

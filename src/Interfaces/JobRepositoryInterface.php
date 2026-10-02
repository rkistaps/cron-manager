<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Interfaces;

use rkistaps\CronManager\Structures\JobDefinition;

interface JobRepositoryInterface
{
    /**
     * @return list<JobDefinition> Every defined job, disabled ones included
     */
    public function all(): array;

    public function get(string $name): ?JobDefinition;
}

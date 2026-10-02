<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Repositories;

use InvalidArgumentException;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Structures\JobDefinition;

final readonly class InMemoryJobRepository implements JobRepositoryInterface
{
    /** @var array<string, JobDefinition> */
    private array $jobs;

    /**
     * @param list<JobDefinition> $jobs
     */
    public function __construct(array $jobs = [])
    {
        $byName = [];
        foreach ($jobs as $job) {
            if (isset($byName[$job->name])) {
                throw new InvalidArgumentException(sprintf('Job "%s" is defined twice', $job->name));
            }
            $byName[$job->name] = $job;
        }

        $this->jobs = $byName;
    }

    public function all(): array
    {
        return array_values($this->jobs);
    }

    public function get(string $name): ?JobDefinition
    {
        return $this->jobs[$name] ?? null;
    }
}

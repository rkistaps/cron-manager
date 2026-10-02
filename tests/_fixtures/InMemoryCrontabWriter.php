<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\_fixtures;

use rkistaps\CronManager\Interfaces\CrontabWriterInterface;

final class InMemoryCrontabWriter implements CrontabWriterInterface
{
    public int $writes = 0;

    public function __construct(
        public string $crontab = '',
        private readonly bool $requiresUserField = false,
    ) {
    }

    public function read(): string
    {
        return $this->crontab;
    }

    public function write(string $crontab): void
    {
        $this->crontab = $crontab;
        $this->writes++;
    }

    public function requiresUserField(): bool
    {
        return $this->requiresUserField;
    }
}

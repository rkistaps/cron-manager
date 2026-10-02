<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Stores;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Enums\JobRunStatus;
use rkistaps\CronManager\Stores\FileRunHistoryStore;
use rkistaps\CronManager\Structures\JobRun;

final class FileRunHistoryStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cron-manager-history-' . bin2hex(random_bytes(4)) . '/nested';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->directory)));
    }

    public function testAJobThatNeverRanHasNoLastRun(): void
    {
        self::assertNull((new FileRunHistoryStore($this->directory))->lastRun('backfill'));
    }

    public function testRecordsAndReadsBackTheLastRun(): void
    {
        $store = new FileRunHistoryStore($this->directory);
        $first = $this->jobRun('backfill', '2026-10-02T03:00:00.000000+00:00', '2026-10-02T03:00:12.250000+00:00', 0);
        $second = $this->jobRun('backfill', '2026-10-03T03:00:00.000000+00:00', '2026-10-03T03:00:01.500000+00:00', 2);

        $store->record($first);
        $store->record($second);
        $store->record($this->jobRun('other', '2026-10-04T00:00:00.000000+00:00', '2026-10-04T00:00:01.000000+00:00', 0));

        $last = $store->lastRun('backfill');
        self::assertNotNull($last);
        self::assertSame('backfill', $last->jobName);
        self::assertEquals($second->startedAt, $last->startedAt);
        self::assertEquals($second->finishedAt, $last->finishedAt);
        self::assertSame(2, $last->exitCode);
        self::assertSame(JobRunStatus::FAILED, $last->status());
        self::assertEqualsWithDelta(1.5, $last->durationSeconds(), 0.000001);
    }

    public function testKeepsOnlyTheNewestRuns(): void
    {
        $store = new FileRunHistoryStore($this->directory, maxRunsPerJob: 3);

        for ($day = 1; $day <= 5; $day++) {
            $store->record($this->jobRun('backfill', "2026-10-0{$day}T03:00:00.000000+00:00", "2026-10-0{$day}T03:00:01.000000+00:00", $day));
        }

        $lines = file($this->directory . '/backfill.jsonl', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        self::assertSame(5, $store->lastRun('backfill')?->exitCode);
    }

    private function jobRun(string $job, string $startedAt, string $finishedAt, int $exitCode): JobRun
    {
        return new JobRun($job, new DateTimeImmutable($startedAt), new DateTimeImmutable($finishedAt), $exitCode);
    }
}

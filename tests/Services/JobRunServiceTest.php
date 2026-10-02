<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Services;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Enums\JobRunStatus;
use rkistaps\CronManager\Exceptions\UnknownJobException;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Services\JobRunService;
use rkistaps\CronManager\Stores\FileRunHistoryStore;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Structures\JobRun;
use RuntimeException;

final class JobRunServiceTest extends TestCase
{
    private string $directory;
    private FileRunHistoryStore $history;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cron-manager-runs-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->history = new FileRunHistoryStore($this->directory . '/history');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testRecordsASuccessfulRun(): void
    {
        $run = $this->service(new JobDefinition('ok', '* * * * *', 'true', $this->directory))->run('ok');

        self::assertSame(0, $run->exitCode);
        self::assertSame(JobRunStatus::SUCCEEDED, $run->status());
        self::assertGreaterThanOrEqual($run->startedAt, $run->finishedAt);
        self::assertEquals($run, $this->history->lastRun('ok'));
    }

    public function testRecordsAFailedRunAndReturnsItsExitCode(): void
    {
        $run = $this->service(new JobDefinition('broken', '* * * * *', 'exit 3', $this->directory))->run('broken');

        self::assertSame(3, $run->exitCode);
        self::assertSame(JobRunStatus::FAILED, $run->status());
        self::assertSame(3, $this->history->lastRun('broken')?->exitCode);
    }

    public function testRunsInTheWorkingDirectoryThroughTheShell(): void
    {
        $command = 'pwd > where.txt && [[ -n "$BASH_VERSION" ]]';

        $run = $this->service(new JobDefinition('where', '* * * * *', $command, $this->directory))->run('where');

        self::assertSame(0, $run->exitCode);
        self::assertSame($this->directory . "\n", file_get_contents($this->directory . '/where.txt'));
    }

    public function testAKilledJobExitsWith128PlusTheSignal(): void
    {
        $run = $this->service(new JobDefinition('killed', '* * * * *', 'kill -TERM $$', $this->directory))->run('killed');

        self::assertSame(128 + 15, $run->exitCode);
    }

    public function testAnUnknownJobIsAnError(): void
    {
        $this->expectException(UnknownJobException::class);

        $this->service()->run('missing');
    }

    public function testABrokenHistoryStoreKeepsTheJobsExitCode(): void
    {
        $brokenStore = new class () implements RunHistoryStoreInterface {
            public function record(JobRun $run): void
            {
                throw new RuntimeException('disk full');
            }

            public function lastRun(string $jobName): ?JobRun
            {
                return null;
            }
        };
        $service = new JobRunService(
            new InMemoryJobRepository([new JobDefinition('broken', '* * * * *', 'exit 4', $this->directory)]),
            $brokenStore,
            new CrontabSettings('test'),
        );

        self::assertSame(4, $service->run('broken')->exitCode);
    }

    private function service(JobDefinition ...$jobs): JobRunService
    {
        return new JobRunService(new InMemoryJobRepository(array_values($jobs)), $this->history, new CrontabSettings('test'));
    }
}

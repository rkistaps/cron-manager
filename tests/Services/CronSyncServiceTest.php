<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Services;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Exceptions\CronManagerException;
use rkistaps\CronManager\Exceptions\HandEditedBlockException;
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Services\CronSyncService;
use rkistaps\CronManager\Structures\CrontabBlock;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Tests\_fixtures\InMemoryCrontabWriter;
use rkistaps\CronManager\Tests\_fixtures\Jobs;

final class CronSyncServiceTest extends TestCase
{
    private const string FOREIGN_BEFORE = "MAILTO=ops@example.com\n# hand-written\n0 1 * * * /usr/bin/backup\n\n";
    private const string FOREIGN_AFTER = "\n# BEGIN cron-manager:other-app sha=0123456789ab\n* * * * * other\n# END cron-manager:other-app\n";

    private string $expectedBlock;

    protected function setUp(): void
    {
        $this->expectedBlock = (string) file_get_contents(__DIR__ . '/../_fixtures/rendered-block.txt');
    }

    public function testSyncsIntoAnEmptyCrontab(): void
    {
        $writer = new InMemoryCrontabWriter();

        $plan = $this->service($writer)->sync();

        self::assertSame($this->expectedBlock, $writer->crontab);
        self::assertSame(1, $writer->writes);
        self::assertSame([], $plan->removed);
        self::assertCount(8, $plan->added);
        self::assertFalse($plan->handEdited);
    }

    public function testSyncsBetweenForeignLinesAndLeavesThemAlone(): void
    {
        // A block written by an earlier sync, before any jobs were defined
        $oldBlock = (new CrontabBlock('the-trader', ['SHELL=/bin/bash']))->render();
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE . $oldBlock . self::FOREIGN_AFTER);

        $plan = $this->service($writer)->sync();

        self::assertSame(self::FOREIGN_BEFORE . $this->expectedBlock . self::FOREIGN_AFTER, $writer->crontab);
        self::assertContains('SHELL=/bin/bash', $plan->unchanged);
        self::assertContains('# END cron-manager:the-trader', $plan->unchanged);
    }

    public function testAppendsToACrontabWithOnlyForeignLines(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE);

        $this->service($writer)->sync();

        self::assertSame(self::FOREIGN_BEFORE . $this->expectedBlock, $writer->crontab);
    }

    public function testAnUpToDateBlockIsNotWritten(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE . $this->expectedBlock . self::FOREIGN_AFTER);

        $plan = $this->service($writer)->sync();

        self::assertSame(0, $writer->writes);
        self::assertFalse($plan->hasChanges());
        self::assertSame([], $plan->added);
        self::assertSame([], $plan->removed);
    }

    public function testSyncingTwiceChangesNothingTheSecondTime(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE);
        $service = $this->service($writer);

        $service->sync();
        $afterFirst = $writer->crontab;
        $second = $service->sync();

        self::assertSame(1, $writer->writes);
        self::assertSame($afterFirst, $writer->crontab);
        self::assertFalse($second->hasChanges());
    }

    public function testRefusesAHandEditedBlockAndSaysWhichLinesDiffer(): void
    {
        $edited = str_replace('*/5 * * * *', '*/1 * * * *', $this->expectedBlock);
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE . $edited);

        try {
            $this->service($writer)->sync();
            self::fail('A hand-edited block was overwritten');
        } catch (HandEditedBlockException $exception) {
            self::assertSame(["*/1 * * * * cd '/var/www/html' && ./run prices/sync"], $exception->removedLines);
            self::assertSame(["*/5 * * * * cd '/var/www/html' && ./run prices/sync"], $exception->addedLines);
            self::assertStringContainsString("- */1 * * * * cd '/var/www/html' && ./run prices/sync", $exception->getMessage());
            self::assertStringContainsString("+ */5 * * * * cd '/var/www/html' && ./run prices/sync", $exception->getMessage());
        }

        self::assertSame(0, $writer->writes);
        self::assertSame(self::FOREIGN_BEFORE . $edited, $writer->crontab);
    }

    public function testOverwritesAHandEditedBlockWithForce(): void
    {
        $edited = str_replace('*/5 * * * *', '*/1 * * * *', $this->expectedBlock);
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE . $edited . self::FOREIGN_AFTER);

        $plan = $this->service($writer)->sync(force: true);

        self::assertTrue($plan->handEdited);
        self::assertSame(self::FOREIGN_BEFORE . $this->expectedBlock . self::FOREIGN_AFTER, $writer->crontab);
    }

    public function testRefusesABlockWhoseOnlyChangeIsTheChecksum(): void
    {
        $edited = (string) preg_replace('/sha=[0-9a-f]{12}/', 'sha=000000000000', $this->expectedBlock);
        $writer = new InMemoryCrontabWriter($edited);

        $this->expectException(HandEditedBlockException::class);
        $this->expectExceptionMessage('only the checksum in the BEGIN line changed');

        $this->service($writer)->sync();
    }

    public function testDiffShowsChangesWithoutWriting(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE);

        $plan = $this->service($writer)->diff();

        self::assertTrue($plan->hasChanges());
        self::assertSame(self::FOREIGN_BEFORE . $this->expectedBlock, $plan->newCrontab);
        self::assertSame(0, $writer->writes);
        self::assertSame(self::FOREIGN_BEFORE, $writer->crontab);
    }

    public function testDiffReportsAHandEditedBlock(): void
    {
        $writer = new InMemoryCrontabWriter(str_replace('./run prices/sync', './run prices/sync --all', $this->expectedBlock));

        $plan = $this->service($writer)->diff();

        self::assertTrue($plan->handEdited);
        self::assertSame(0, $writer->writes);
    }

    public function testRemovingTheBlockLeavesForeignContentByteForByte(): void
    {
        $foreignBefore = self::FOREIGN_BEFORE . "  # indented comment  \n\n\n";
        $writer = new InMemoryCrontabWriter($foreignBefore . $this->expectedBlock . self::FOREIGN_AFTER);

        self::assertTrue($this->service($writer)->remove());
        self::assertSame($foreignBefore . self::FOREIGN_AFTER, $writer->crontab);
    }

    public function testRemovingAMissingBlockWritesNothing(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE);

        self::assertFalse($this->service($writer)->remove());
        self::assertSame(0, $writer->writes);
    }

    public function testRemovingAHandEditedBlockStillWorks(): void
    {
        $writer = new InMemoryCrontabWriter(self::FOREIGN_BEFORE . str_replace('*/5', '*/1', $this->expectedBlock));

        self::assertTrue($this->service($writer)->remove());
        self::assertSame(self::FOREIGN_BEFORE, $writer->crontab);
    }

    public function testDisablingAJobRemovesItsLine(): void
    {
        $writer = new InMemoryCrontabWriter($this->expectedBlock);
        $jobs = array_map(
            static fn (JobDefinition $job): JobDefinition => $job->name === 'prices-sync'
                ? new JobDefinition($job->name, $job->schedule, $job->command, $job->workingDirectory, $job->preventOverlap, $job->logFile, false)
                : $job,
            Jobs::fixed(),
        );

        $plan = (new CronSyncService(new CrontabSettings('the-trader'), new InMemoryJobRepository($jobs), $writer))->sync();

        self::assertContains("*/5 * * * * cd '/var/www/html' && ./run prices/sync", $plan->removed);
        self::assertStringNotContainsString('prices/sync', $writer->crontab);
    }

    public function testAWriterThatNeedsAUserFieldNeedsAUserInTheSettings(): void
    {
        $this->expectException(CronManagerException::class);
        $this->expectExceptionMessage('Set the user in CrontabSettings');

        $this->service(new InMemoryCrontabWriter('', requiresUserField: true))->diff();
    }

    private function service(InMemoryCrontabWriter $writer): CronSyncService
    {
        return new CronSyncService(new CrontabSettings('the-trader'), new InMemoryJobRepository(Jobs::fixed()), $writer);
    }
}

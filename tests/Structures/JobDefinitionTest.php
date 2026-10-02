<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Structures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Structures\Schedule;

final class JobDefinitionTest extends TestCase
{
    public function testKeepsItsSettings(): void
    {
        $job = new JobDefinition('funding-backfill', '0 3 * * *', './run funding/backfill', '/var/www/html');

        self::assertSame('funding-backfill', $job->name);
        self::assertSame('0 3 * * *', $job->schedule->expression);
        self::assertTrue($job->preventOverlap);
        self::assertTrue($job->enabled);
        self::assertNull($job->logFile);
        self::assertNull($job->description);
    }

    public function testAcceptsAScheduleObject(): void
    {
        $schedule = new Schedule('*/5 * * * *');

        self::assertSame($schedule, (new JobDefinition('sync', $schedule, './run sync', '/app'))->schedule);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badNames(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Backfill'],
            'underscore' => ['funding_backfill'],
            'space' => ['funding backfill'],
            'leading dash' => ['-backfill'],
            'dot' => ['funding.backfill'],
            'slash' => ['funding/backfill'],
        ];
    }

    #[DataProvider('badNames')]
    public function testRejectsNamesWithBadCharacters(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job name');

        new JobDefinition($name, '0 3 * * *', './run', '/app');
    }

    public function testRejectsAnInvalidSchedule(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobDefinition('backfill', '0 25 * * *', './run', '/app');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function newlines(): array
    {
        return ['line feed' => ["./run a\n./run b"], 'carriage return' => ["./run a\r./run b"]];
    }

    #[DataProvider('newlines')]
    public function testRejectsACommandWithANewline(string $command): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('command may not contain a newline');

        new JobDefinition('backfill', '0 3 * * *', $command, '/app');
    }

    public function testRejectsACommandWithAPercentSign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('command may not contain "%"');

        new JobDefinition('backfill', '0 3 * * *', 'date +%Y-%m-%d', '/app');
    }

    public function testRejectsAnEmptyCommand(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobDefinition('backfill', '0 3 * * *', '  ', '/app');
    }

    public function testRejectsARelativeWorkingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute path');

        new JobDefinition('backfill', '0 3 * * *', './run', 'var/www');
    }

    public function testRejectsALogFileWithAPercentSign(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobDefinition('backfill', '0 3 * * *', './run', '/app', logFile: 'logs/100%.log');
    }

    public function testRejectsADescriptionWithANewline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobDefinition('backfill', '0 3 * * *', './run', '/app', description: "first\n* * * * * rm -rf /");
    }
}

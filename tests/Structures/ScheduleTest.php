<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Structures;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Structures\Schedule;

final class ScheduleTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function validExpressions(): array
    {
        return [
            'every minute' => ['* * * * *'],
            'step' => ['*/5 * * * *'],
            'range with step' => ['0 9-17/2 * * 1-5'],
            'lists' => ['0,30 3,15 1,15 * *'],
            'names' => ['0 3 * jan-mar mon,wed'],
        ];
    }

    #[DataProvider('validExpressions')]
    public function testAcceptsStandardExpressions(string $expression): void
    {
        self::assertSame($expression, (new Schedule($expression))->expression);
    }

    public function testNormalizesWhitespace(): void
    {
        self::assertSame('0 3 * * *', (new Schedule("  0  3\t* * *  "))->expression);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidExpressions(): array
    {
        return [
            'empty' => [''],
            'four fields' => ['0 3 * *'],
            'six fields' => ['0 0 3 * * *'],
            'macro' => ['@daily'],
            'minute out of range' => ['60 * * * *'],
            'hour out of range' => ['0 24 * * *'],
            'last day of month' => ['0 0 L * *'],
            'nth weekday' => ['0 0 * * 1#2'],
            'question mark' => ['0 0 ? * *'],
            'garbage' => ['every day at 3'],
            'percent' => ['0 3 * * *%'],
        ];
    }

    #[DataProvider('invalidExpressions')]
    public function testRejectsInvalidExpressions(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Schedule($expression);
    }

    public function testGivesTheNextRunTimes(): void
    {
        $times = (new Schedule('0 3 * * *'))->nextRunTimes(3, new DateTimeImmutable('2026-10-02 12:00:00'));

        self::assertSame(
            ['2026-10-03 03:00', '2026-10-04 03:00', '2026-10-05 03:00'],
            array_map(static fn (DateTimeImmutable $time): string => $time->format('Y-m-d H:i'), $times),
        );
    }
}

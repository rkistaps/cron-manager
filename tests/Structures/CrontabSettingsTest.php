<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Structures;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Structures\CrontabSettings;

final class CrontabSettingsTest extends TestCase
{
    public function testHasTheDocumentedDefaults(): void
    {
        $settings = new CrontabSettings('the-trader');

        self::assertSame('/bin/bash', $settings->shell);
        self::assertSame('/usr/local/bin:/usr/bin:/bin', $settings->path);
        self::assertSame('/tmp/cron-manager', $settings->lockDirectory);
        self::assertFalse($settings->recordHistory);
        self::assertNull($settings->user);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badAppIds(): array
    {
        return [
            'empty' => [''],
            'dot' => ['the.trader'],
            'space' => ['the trader'],
            'colon' => ['the:trader'],
            'slash' => ['../trader'],
        ];
    }

    #[DataProvider('badAppIds')]
    public function testRejectsAppIdsWithBadCharacters(string $appId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CrontabSettings($appId);
    }

    public function testRejectsHistoryWithoutARunWrapper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('run wrapper command');

        new CrontabSettings('the-trader', recordHistory: true);
    }

    public function testRejectsARelativeLockDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CrontabSettings('the-trader', lockDirectory: 'locks');
    }

    public function testRejectsAPathWithANewline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CrontabSettings('the-trader', path: "/usr/bin\nMAILTO=someone");
    }
}

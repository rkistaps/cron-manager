<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Writers;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Writers\CronDWriter;

final class CronDWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cron-manager-crond-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/{,.}*[!.]', GLOB_BRACE) ?: []);
        rmdir($this->directory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badFileNames(): array
    {
        return ['dot' => ['the.trader'], 'space' => ['the trader'], 'slash' => ['../trader'], 'empty' => ['']];
    }

    #[DataProvider('badFileNames')]
    public function testRejectsAppIdsCronWouldIgnoreAsFileNames(string $appId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CronDWriter($appId, $this->directory);
    }

    public function testAMissingFileReadsAsEmpty(): void
    {
        self::assertSame('', (new CronDWriter('the-trader', $this->directory))->read());
    }

    public function testWritesTheFileWithModeCronAccepts(): void
    {
        $writer = new CronDWriter('the_trader-2', $this->directory);
        $crontab = "0 3 * * * www-data cd '/app' && ./run backfill\n";

        $writer->write($crontab);

        self::assertSame($this->directory . '/the_trader-2', $writer->path());
        self::assertSame($crontab, $writer->read());
        self::assertSame(0644, fileperms($writer->path()) & 0777);
        self::assertSame(['the_trader-2'], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
    }

    public function testWritingNothingDeletesTheFile(): void
    {
        $writer = new CronDWriter('the-trader', $this->directory);
        $writer->write("* * * * * root true\n");

        $writer->write('');

        self::assertFileDoesNotExist($writer->path());
    }

    public function testNeedsAUserField(): void
    {
        self::assertTrue((new CronDWriter('the-trader', $this->directory))->requiresUserField());
    }
}

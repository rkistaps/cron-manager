<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Writers;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Exceptions\CrontabAccessException;
use rkistaps\CronManager\Writers\UserCrontabWriter;

final class UserCrontabWriterTest extends TestCase
{
    private const string FAKE_CRONTAB = __DIR__ . '/../_fixtures/fake-crontab.sh';

    private string $crontabFile;

    protected function setUp(): void
    {
        chmod(self::FAKE_CRONTAB, 0755);
        $this->crontabFile = sys_get_temp_dir() . '/cron-manager-crontab-' . bin2hex(random_bytes(4));
        putenv('FAKE_CRONTAB_FILE=' . $this->crontabFile);
        putenv('FAKE_CRONTAB_FAIL');
    }

    protected function tearDown(): void
    {
        @unlink($this->crontabFile);
        @unlink($this->crontabFile . '.calls');
        putenv('FAKE_CRONTAB_FILE');
        putenv('FAKE_CRONTAB_FAIL');
    }

    public function testNoCrontabForUserReadsAsEmpty(): void
    {
        self::assertSame('', (new UserCrontabWriter(self::FAKE_CRONTAB))->read());
    }

    public function testWritesAndReadsTheCrontab(): void
    {
        $writer = new UserCrontabWriter(self::FAKE_CRONTAB);
        $crontab = "MAILTO=ops@example.com\n\n0 3 * * * cd '/app' && ./run backfill\n";

        $writer->write($crontab);

        self::assertSame($crontab, $writer->read());
        self::assertSame("-\n-l\n", file_get_contents($this->crontabFile . '.calls'));
    }

    public function testPassesTheUser(): void
    {
        (new UserCrontabWriter(self::FAKE_CRONTAB, 'www-data'))->read();

        self::assertSame("-u www-data -l\n", file_get_contents($this->crontabFile . '.calls'));
    }

    public function testAnyOtherReadFailureIsAnError(): void
    {
        putenv('FAKE_CRONTAB_FAIL=cannot connect to cron daemon');

        $this->expectException(CrontabAccessException::class);
        $this->expectExceptionMessage('cannot connect to cron daemon');

        (new UserCrontabWriter(self::FAKE_CRONTAB))->read();
    }

    public function testAWriteFailureIsAnError(): void
    {
        putenv('FAKE_CRONTAB_FAIL=permission denied');

        $this->expectException(CrontabAccessException::class);
        $this->expectExceptionMessage('permission denied');

        (new UserCrontabWriter(self::FAKE_CRONTAB))->write("* * * * * true\n");
    }

    public function testAMissingBinaryIsAnError(): void
    {
        $this->expectException(CrontabAccessException::class);

        (new UserCrontabWriter('/nonexistent/crontab'))->read();
    }
}

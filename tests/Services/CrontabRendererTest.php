<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Services;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Services\CrontabRenderer;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Tests\_fixtures\Jobs;

final class CrontabRendererTest extends TestCase
{
    public function testRendersTheFixedDefinitionsToTheExpectedBlock(): void
    {
        $block = (new CrontabRenderer())->render(new CrontabSettings('the-trader'), Jobs::fixed());

        self::assertStringEqualsFile(__DIR__ . '/../_fixtures/rendered-block.txt', $block->render());
    }

    public function testRendersOnlyTheEnvironmentWithoutJobs(): void
    {
        $block = (new CrontabRenderer())->render(
            new CrontabSettings('app', shell: '/bin/sh', path: '/usr/bin:/bin'),
            [],
        );

        self::assertSame(['SHELL=/bin/sh', 'PATH=/usr/bin:/bin'], $block->lines);
    }

    public function testCallsTheRunWrapperWhenHistoryIsOn(): void
    {
        $settings = new CrontabSettings('the-trader', runWrapperCommand: './run cron/run', recordHistory: true);
        $job = new JobDefinition('funding-backfill', '0 3 * * *', './run funding/backfill', '/var/www/html', logFile: 'cron.log');

        self::assertSame(
            "0 3 * * * cd '/var/www/html' && mkdir -p '/tmp/cron-manager' && flock -n '/tmp/cron-manager/the-trader-funding-backfill.lock' ./run cron/run --job='funding-backfill' >> 'cron.log' 2>&1",
            (new CrontabRenderer())->renderJobLine($settings, $job),
        );
    }

    public function testPutsTheUserFieldAfterTheSchedule(): void
    {
        $settings = new CrontabSettings('the-trader', user: 'www-data');
        $job = new JobDefinition('sync', '*/5 * * * *', './run sync', '/app', preventOverlap: false);

        self::assertSame(
            "*/5 * * * * www-data cd '/app' && ./run sync",
            (new CrontabRenderer())->renderJobLine($settings, $job),
        );
    }

    public function testRenderedCommandRunsInAShell(): void
    {
        $root = sys_get_temp_dir() . '/cron-manager-render-' . bin2hex(random_bytes(4));
        $workingDirectory = $root . "/it's here";
        mkdir($workingDirectory, 0777, true);

        $settings = new CrontabSettings('render-test', lockDirectory: $root . '/locks that do not exist yet');
        $job = new JobDefinition('pwd', '* * * * *', 'pwd', $workingDirectory, logFile: "out's.log");

        $line = (new CrontabRenderer())->renderJobLine($settings, $job);
        // Drop the five schedule fields; the rest is what cron hands to the shell
        $command = (string) preg_replace('/^(\S+\s+){5}/', '', $line);

        exec('/bin/bash -c ' . escapeshellarg($command), $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertSame($workingDirectory . "\n", file_get_contents($workingDirectory . "/out's.log"));
        self::assertFileExists($root . '/locks that do not exist yet/render-test-pwd.lock');

        exec('rm -rf ' . escapeshellarg($root));
    }

    public function testCreatesTheLogDirectoryBeforeTheShellOpensTheLog(): void
    {
        $root = sys_get_temp_dir() . '/cron-manager-render-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        // Without overlap protection too: the log directory must not depend on the lock directory's mkdir
        foreach ([true, false] as $preventOverlap) {
            $settings = new CrontabSettings('render-test', lockDirectory: $root . '/locks');
            $job = new JobDefinition('pwd', '* * * * *', 'pwd', $root, $preventOverlap, logFile: "logs/o'brien/out.log");

            $line = (new CrontabRenderer())->renderJobLine($settings, $job);
            $command = (string) preg_replace('/^(\S+\s+){5}/', '', $line);

            exec('/bin/bash -c ' . escapeshellarg($command) . ' 2>&1', $output, $exitCode);

            self::assertSame(0, $exitCode, implode("\n", $output));
            self::assertFileExists($root . "/logs/o'brien/out.log");
            exec('rm -rf ' . escapeshellarg($root . '/logs'));
        }

        exec('rm -rf ' . escapeshellarg($root));
    }

    public function testCreatesOnlyTheLogDirectoryWithoutOverlapProtection(): void
    {
        $job = new JobDefinition('sync', '*/5 * * * *', './run sync', '/app', preventOverlap: false, logFile: 'data/cron/sync.log');

        self::assertSame(
            "*/5 * * * * cd '/app' && mkdir -p 'data/cron' && ./run sync >> 'data/cron/sync.log' 2>&1",
            (new CrontabRenderer())->renderJobLine(new CrontabSettings('the-trader'), $job),
        );
    }
}

<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\_fixtures;

use rkistaps\CronManager\Structures\JobDefinition;

/**
 * The fixed definitions behind rendered-block.txt
 */
final class Jobs
{
    /**
     * @return list<JobDefinition>
     */
    public static function fixed(): array
    {
        return [
            new JobDefinition(
                name: 'funding-backfill',
                schedule: '0 3 * * *',
                command: './run funding/backfill',
                workingDirectory: '/var/www/html',
                logFile: 'data/cron/funding-backfill.log',
                description: 'Backfill funding history',
            ),
            new JobDefinition(
                name: 'prices-sync',
                schedule: '*/5  *  * * *',
                command: './run prices/sync',
                workingDirectory: '/var/www/html',
                preventOverlap: false,
            ),
            new JobDefinition(
                name: 'cleanup',
                schedule: '0 0 * * *',
                command: './run cleanup',
                workingDirectory: '/var/www/html',
                enabled: false,
                description: 'Disabled, so not rendered',
            ),
            new JobDefinition(
                name: 'weekly-report',
                schedule: '30 6 * * mon',
                command: 'php report.php',
                workingDirectory: "/srv/o'brien app",
                logFile: "/var/log/o'brien report.log",
            ),
        ];
    }
}

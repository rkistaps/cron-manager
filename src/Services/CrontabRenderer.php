<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Services;

use rkistaps\CronManager\Structures\CrontabBlock;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;

final class CrontabRenderer
{
    /**
     * @param list<JobDefinition> $jobs
     */
    public function render(CrontabSettings $settings, array $jobs): CrontabBlock
    {
        $lines = [
            'SHELL=' . $settings->shell,
            'PATH=' . $settings->path,
        ];

        foreach ($jobs as $job) {
            if (!$job->enabled) {
                continue;
            }

            if ($job->description !== null && trim($job->description) !== '') {
                $lines[] = '# ' . trim($job->description);
            }
            $lines[] = $this->renderJobLine($settings, $job);
        }

        return new CrontabBlock($settings->appId, $lines);
    }

    public function renderJobLine(CrontabSettings $settings, JobDefinition $job): string
    {
        $parts = [$job->schedule->expression];

        if ($settings->user !== null) {
            $parts[] = $settings->user;
        }

        $parts[] = 'cd ' . escapeshellarg($job->workingDirectory) . ' &&';

        if ($job->preventOverlap) {
            // flock creates the lock file but not its directory, and /tmp is emptied on reboot
            $parts[] = 'mkdir -p ' . escapeshellarg($settings->lockDirectory) . ' &&';
            $parts[] = 'flock -n ' . escapeshellarg($this->lockPath($settings, $job));
        }

        $parts[] = $settings->recordHistory && $settings->runWrapperCommand !== null
            ? $settings->runWrapperCommand . ' --job=' . escapeshellarg($job->name)
            : $job->command;

        if ($job->logFile !== null) {
            $parts[] = '>> ' . escapeshellarg($job->logFile) . ' 2>&1';
        }

        return implode(' ', $parts);
    }

    public function lockPath(CrontabSettings $settings, JobDefinition $job): string
    {
        return sprintf('%s/%s-%s.lock', rtrim($settings->lockDirectory, '/'), $settings->appId, $job->name);
    }
}

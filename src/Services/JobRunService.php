<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Services;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use rkistaps\CronManager\Exceptions\CronManagerException;
use rkistaps\CronManager\Exceptions\UnknownJobException;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Structures\JobRun;
use Throwable;

/**
 * The run wrapper: runs one job's command and records the outcome
 */
final readonly class JobRunService
{
    private const int POLL_MICROSECONDS = 50_000;

    public function __construct(
        private JobRepositoryInterface $jobs,
        private RunHistoryStoreInterface $history,
        private CrontabSettings $settings,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return JobRun Its exit code is the command's, so the wrapper can exit with it
     * @throws UnknownJobException
     */
    public function run(string $jobName): JobRun
    {
        $job = $this->jobs->get($jobName)
            ?? throw new UnknownJobException(sprintf('No job named "%s" is defined', $jobName));

        $startedAt = new DateTimeImmutable();
        $exitCode = $this->execute($job);
        $run = new JobRun($job->name, $startedAt, new DateTimeImmutable(), $exitCode);

        // The job already ran: a broken history store must not change the exit code cron sees
        try {
            $this->history->record($run);
        } catch (Throwable $exception) {
            $this->logger->error('Could not record the job run', ['job' => $job->name, 'exception' => $exception]);
        }

        $this->logger->info('Job finished', [
            'job' => $job->name,
            'exit_code' => $exitCode,
            'duration' => $run->durationSeconds(),
        ]);

        return $run;
    }

    private function execute(JobDefinition $job): int
    {
        if (!is_dir($job->workingDirectory)) {
            throw new CronManagerException(sprintf(
                'Job "%s": working directory %s does not exist',
                $job->name,
                $job->workingDirectory,
            ));
        }

        // The job's output goes where the wrapper's goes: the log file named on the crontab line
        $process = proc_open(
            [$this->settings->shell, '-c', $job->command],
            [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
            $job->workingDirectory,
        );
        if (!is_resource($process)) {
            throw new CronManagerException(sprintf('Job "%s": could not start the command', $job->name));
        }

        // proc_get_status reports the exit code exactly once, and tells a signal apart from an exit
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(self::POLL_MICROSECONDS);
            }
        } while ($status['running']);

        proc_close($process);

        return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
    }
}

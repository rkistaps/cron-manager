<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Stores;

use DateTimeImmutable;
use InvalidArgumentException;
use rkistaps\CronManager\Exceptions\CronManagerException;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Structures\JobRun;

/**
 * One JSON-lines file per job in $directory, newest run last, trimmed to the last $maxRunsPerJob runs
 */
final readonly class FileRunHistoryStore implements RunHistoryStoreInterface
{
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private string $directory,
        private int $maxRunsPerJob = 100,
    ) {
        if ($maxRunsPerJob < 1) {
            throw new InvalidArgumentException('A run history must keep at least one run per job');
        }
    }

    public function record(JobRun $run): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new CronManagerException(sprintf('Could not create the run history directory %s', $this->directory));
        }

        $handle = @fopen($this->file($run->jobName), 'c+');
        if ($handle === false) {
            throw new CronManagerException(sprintf('Could not open %s', $this->file($run->jobName)));
        }

        try {
            // Jobs without overlap protection can finish at the same time
            flock($handle, LOCK_EX);

            $lines = $this->lines((string) stream_get_contents($handle));
            $lines[] = json_encode($this->toArray($run), JSON_THROW_ON_ERROR);
            $lines = array_slice($lines, -$this->maxRunsPerJob);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", $lines) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function lastRun(string $jobName): ?JobRun
    {
        $handle = @fopen($this->file($jobName), 'r');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_SH);
            $lines = $this->lines((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $last = end($lines);

        return $last === false ? null : $this->fromJson($last);
    }

    private function file(string $jobName): string
    {
        return rtrim($this->directory, '/') . '/' . $jobName . '.jsonl';
    }

    /**
     * @return list<string>
     */
    private function lines(string $content): array
    {
        return array_values(array_filter(explode("\n", $content), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, string|int|float>
     */
    private function toArray(JobRun $run): array
    {
        return [
            'job' => $run->jobName,
            'started_at' => $run->startedAt->format(self::TIME_FORMAT),
            'finished_at' => $run->finishedAt->format(self::TIME_FORMAT),
            'duration' => round($run->durationSeconds(), 6),
            'exit_code' => $run->exitCode,
            'status' => $run->status()->value,
        ];
    }

    private function fromJson(string $json): JobRun
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (
            !is_array($data)
            || !is_string($data['job'] ?? null)
            || !is_string($data['started_at'] ?? null)
            || !is_string($data['finished_at'] ?? null)
            || !is_int($data['exit_code'] ?? null)
        ) {
            throw new CronManagerException(sprintf('Unreadable run history entry: %s', $json));
        }

        return new JobRun(
            $data['job'],
            $this->parseTime($data['started_at']),
            $this->parseTime($data['finished_at']),
            $data['exit_code'],
        );
    }

    private function parseTime(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value)
            ?: throw new CronManagerException(sprintf('Unreadable time in run history: %s', $value));
    }
}

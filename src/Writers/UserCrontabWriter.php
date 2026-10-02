<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Writers;

use rkistaps\CronManager\Exceptions\CrontabAccessException;
use rkistaps\CronManager\Interfaces\CrontabWriterInterface;

/**
 * The crontab of the user running PHP, or of $user (which needs root), through crontab(1)
 */
final readonly class UserCrontabWriter implements CrontabWriterInterface
{
    public function __construct(
        private string $binary = 'crontab',
        private ?string $user = null,
    ) {
    }

    public function read(): string
    {
        [$exitCode, $stdout, $stderr] = $this->run(['-l'], '');

        if ($exitCode === 0) {
            return $stdout;
        }

        // A user who never had a crontab: crontab(1) fails, but there is nothing to read
        if (preg_match('/^no crontab for /mi', $stderr) === 1) {
            return '';
        }

        throw new CrontabAccessException(sprintf(
            'Reading the crontab failed with exit code %d: %s',
            $exitCode,
            trim($stderr),
        ));
    }

    public function write(string $crontab): void
    {
        [$exitCode, , $stderr] = $this->run(['-'], $crontab);

        if ($exitCode !== 0) {
            throw new CrontabAccessException(sprintf(
                'Writing the crontab failed with exit code %d: %s',
                $exitCode,
                trim($stderr),
            ));
        }
    }

    public function requiresUserField(): bool
    {
        return false;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string} Exit code, stdout, stderr
     */
    private function run(array $arguments, string $input): array
    {
        $command = [$this->binary];
        if ($this->user !== null) {
            array_push($command, '-u', $this->user);
        }
        array_push($command, ...$arguments);

        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new CrontabAccessException(sprintf(
                'Could not start "%s": %s',
                $this->binary,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}

<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

use InvalidArgumentException;
use rkistaps\CronManager\Validators\CrontabValueValidator;

final readonly class JobDefinition
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    public Schedule $schedule;

    public function __construct(
        public string $name,
        Schedule|string $schedule,
        public string $command,
        public string $workingDirectory,
        public bool $preventOverlap = true,
        public ?string $logFile = null,
        public bool $enabled = true,
        public ?string $description = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Job name "%s" may only contain lowercase letters, digits and "-", and must not start with "-"',
                $name,
            ));
        }

        $this->schedule = is_string($schedule) ? new Schedule($schedule) : $schedule;

        if (trim($command) === '') {
            throw new InvalidArgumentException(sprintf('Job "%s": command may not be empty', $name));
        }
        CrontabValueValidator::assertLineSafe(sprintf('Job "%s": command', $name), $command);
        CrontabValueValidator::assertAbsolutePath(sprintf('Job "%s": working directory', $name), $workingDirectory);

        if ($logFile !== null) {
            if ($logFile === '') {
                throw new InvalidArgumentException(sprintf('Job "%s": log file may not be empty, use null instead', $name));
            }
            CrontabValueValidator::assertLineSafe(sprintf('Job "%s": log file', $name), $logFile);
        }

        if ($description !== null) {
            CrontabValueValidator::assertSingleLine(sprintf('Job "%s": description', $name), $description);
        }
    }
}

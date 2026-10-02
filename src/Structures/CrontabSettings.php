<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

use InvalidArgumentException;
use rkistaps\CronManager\Validators\CrontabValueValidator;

final readonly class CrontabSettings
{
    // The app id names the block markers and, with CronDWriter, the file in /etc/cron.d
    public const string APP_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';
    private const string USER_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /**
     * @param string|null $runWrapperCommand Command that runs one job by name, such as "./run cron/run".
     *                                       Rendered as "<command> --job=<name>" when history is on.
     * @param string|null $user              User field rendered on every line. Required by CronDWriter.
     */
    public function __construct(
        public string $appId,
        public string $shell = '/bin/bash',
        public string $path = '/usr/local/bin:/usr/bin:/bin',
        public string $lockDirectory = '/tmp/cron-manager',
        public ?string $runWrapperCommand = null,
        public bool $recordHistory = false,
        public ?string $user = null,
    ) {
        if (preg_match(self::APP_ID_PATTERN, $appId) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'App id "%s" may only contain letters, digits, "-" and "_"',
                $appId,
            ));
        }

        CrontabValueValidator::assertAbsolutePath('SHELL', $shell);
        CrontabValueValidator::assertLineSafe('PATH', $path);
        CrontabValueValidator::assertAbsolutePath('Lock directory', $lockDirectory);

        if ($runWrapperCommand !== null) {
            if (trim($runWrapperCommand) === '') {
                throw new InvalidArgumentException('Run wrapper command may not be empty, use null instead');
            }
            CrontabValueValidator::assertLineSafe('Run wrapper command', $runWrapperCommand);
        }

        if ($recordHistory && $runWrapperCommand === null) {
            throw new InvalidArgumentException('Run history needs a run wrapper command, such as "./run cron/run"');
        }

        if ($user !== null && preg_match(self::USER_PATTERN, $user) !== 1) {
            throw new InvalidArgumentException(sprintf('User "%s" is not a valid user name', $user));
        }
    }
}

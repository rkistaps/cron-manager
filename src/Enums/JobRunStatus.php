<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Enums;

enum JobRunStatus: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}

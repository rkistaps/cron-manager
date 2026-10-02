<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class Schedule
{
    // One field: a comma list of "*", numbers or three-letter names, each with an optional range and step.
    // Rules this rejects but the expression library accepts (?, L, W, #, @daily) are not understood by cron itself.
    private const string ATOM = '(\*|[0-9]+|[a-z]{3})(-([0-9]+|[a-z]{3}))?(/[0-9]+)?';
    private const string FIELD_PATTERN = '~^' . self::ATOM . '(,' . self::ATOM . ')*$~i';

    public string $expression;

    public function __construct(string $expression)
    {
        $fields = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);
        if ($fields === false || count($fields) !== 5) {
            throw new InvalidArgumentException(sprintf('Schedule "%s" must have exactly five fields', $expression));
        }

        foreach ($fields as $field) {
            if (preg_match(self::FIELD_PATTERN, $field) !== 1) {
                throw new InvalidArgumentException(sprintf('Schedule "%s" has an invalid field "%s"', $expression, $field));
            }
        }

        $normalized = implode(' ', $fields);
        if (!CronExpression::isValidExpression($normalized)) {
            throw new InvalidArgumentException(sprintf('Schedule "%s" is not a valid cron expression', $expression));
        }

        $this->expression = $normalized;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function nextRunTimes(int $count, DateTimeInterface $from = new DateTimeImmutable()): array
    {
        $times = [];
        foreach ((new CronExpression($this->expression))->getMultipleRunDates($count, $from) as $time) {
            $times[] = DateTimeImmutable::createFromInterface($time);
        }

        return $times;
    }
}

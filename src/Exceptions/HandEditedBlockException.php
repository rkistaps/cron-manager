<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Exceptions;

final class HandEditedBlockException extends CronManagerException
{
    /**
     * @param list<string> $removedLines Lines in the crontab that sync would replace
     * @param list<string> $addedLines   Lines sync would write instead
     */
    public function __construct(
        public readonly string $appId,
        public readonly array $removedLines,
        public readonly array $addedLines,
    ) {
        parent::__construct($this->buildMessage());
    }

    private function buildMessage(): string
    {
        $message = sprintf(
            'The cron-manager:%s block was edited by hand: its checksum no longer matches. Sync with force to overwrite it.',
            $this->appId,
        );

        if ($this->removedLines === [] && $this->addedLines === []) {
            return $message . "\nEvery line matches what sync would write; only the checksum in the BEGIN line changed.";
        }

        $message .= "\nLines that differ from what sync would write:";
        foreach ($this->removedLines as $line) {
            $message .= "\n- " . $line;
        }
        foreach ($this->addedLines as $line) {
            $message .= "\n+ " . $line;
        }

        return $message;
    }
}

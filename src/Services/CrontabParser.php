<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Services;

use rkistaps\CronManager\Exceptions\MalformedCrontabException;
use rkistaps\CronManager\Structures\CrontabBlock;

/**
 * Finds, replaces and removes one app's block in crontab text. Every other byte is left as it is.
 */
final class CrontabParser
{
    public function find(string $crontab, string $appId): ?CrontabBlock
    {
        $lines = explode("\n", $crontab);
        $location = $this->locate($lines, $appId);
        if ($location === null) {
            return null;
        }

        [$begin, $end, $checksum] = $location;

        return new CrontabBlock($appId, array_slice($lines, $begin + 1, $end - $begin - 1), $checksum);
    }

    /**
     * Puts the block where the app's existing block is, or appends it to the end
     */
    public function replace(string $crontab, CrontabBlock $block): string
    {
        $lines = explode("\n", $crontab);
        $location = $this->locate($lines, $block->appId);

        if ($location === null) {
            if ($crontab === '') {
                return $block->render();
            }

            return $crontab . (str_ends_with($crontab, "\n") ? '' : "\n") . $block->render();
        }

        [$begin, $end] = $location;
        array_splice($lines, $begin, $end - $begin + 1, $block->allLines());

        return implode("\n", $lines);
    }

    public function remove(string $crontab, string $appId): string
    {
        $lines = explode("\n", $crontab);
        $location = $this->locate($lines, $appId);
        if ($location === null) {
            return $crontab;
        }

        [$begin, $end] = $location;
        array_splice($lines, $begin, $end - $begin + 1);

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @return array{int, int, string}|null Index of the BEGIN line, index of the END line, checksum from the BEGIN line
     */
    private function locate(array $lines, string $appId): ?array
    {
        $beginPrefix = CrontabBlock::beginMarkerPrefix($appId);
        $endMarker = CrontabBlock::endMarkerFor($appId);

        $begin = null;
        $end = null;
        $checksum = '';

        foreach ($lines as $index => $line) {
            $line = rtrim($line);

            // "app" must not match the markers of "app-two"
            if ($line === $beginPrefix || str_starts_with($line, $beginPrefix . ' ')) {
                if ($begin !== null) {
                    throw new MalformedCrontabException(sprintf('The crontab has more than one cron-manager:%s block', $appId));
                }
                $begin = $index;
                // A BEGIN line without a readable checksum counts as edited by hand
                $checksum = preg_match('/ sha=([0-9a-f]{12})$/', $line, $matches) === 1 ? $matches[1] : '';
            } elseif ($line === $endMarker) {
                if ($begin === null || $end !== null) {
                    throw new MalformedCrontabException(sprintf('The crontab has an END marker for cron-manager:%s without a BEGIN marker', $appId));
                }
                $end = $index;
            }
        }

        if ($begin === null) {
            return null;
        }

        if ($end === null) {
            throw new MalformedCrontabException(sprintf('The cron-manager:%s block has no END marker', $appId));
        }

        return [$begin, $end, $checksum];
    }
}

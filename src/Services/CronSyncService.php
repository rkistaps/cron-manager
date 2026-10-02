<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use rkistaps\CronManager\Exceptions\CronManagerException;
use rkistaps\CronManager\Exceptions\HandEditedBlockException;
use rkistaps\CronManager\Interfaces\CrontabWriterInterface;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Structures\CrontabBlock;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\SyncPlan;

final readonly class CronSyncService
{
    public function __construct(
        private CrontabSettings $settings,
        private JobRepositoryInterface $jobs,
        private CrontabWriterInterface $writer,
        private CrontabRenderer $renderer = new CrontabRenderer(),
        private CrontabParser $parser = new CrontabParser(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * What a sync would change, without writing
     */
    public function diff(): SyncPlan
    {
        $current = $this->writer->read();

        return $this->plan($current, $this->parser->find($current, $this->settings->appId), $this->desiredBlock());
    }

    /**
     * Writes the block when it differs from the definitions
     *
     * @throws HandEditedBlockException When the block was edited by hand and $force is false
     */
    public function sync(bool $force = false): SyncPlan
    {
        $current = $this->writer->read();
        $existing = $this->parser->find($current, $this->settings->appId);
        $desired = $this->desiredBlock();

        if ($existing !== null && $existing->isHandEdited() && !$force) {
            [$added, $removed] = $this->diffLines($existing->lines, $desired->lines);
            throw new HandEditedBlockException($this->settings->appId, $removed, $added);
        }

        $plan = $this->plan($current, $existing, $desired);

        if ($plan->hasChanges()) {
            $this->writer->write($plan->newCrontab);
            $this->logger->info('Crontab block synced', [
                'app_id' => $this->settings->appId,
                'added' => count($plan->added),
                'removed' => count($plan->removed),
            ]);
        }

        return $plan;
    }

    /**
     * Deletes this app's block and nothing else
     *
     * @return bool Whether there was a block to remove
     */
    public function remove(): bool
    {
        $current = $this->writer->read();
        if ($this->parser->find($current, $this->settings->appId) === null) {
            return false;
        }

        $this->writer->write($this->parser->remove($current, $this->settings->appId));
        $this->logger->info('Crontab block removed', ['app_id' => $this->settings->appId]);

        return true;
    }

    private function desiredBlock(): CrontabBlock
    {
        if ($this->writer->requiresUserField() && $this->settings->user === null) {
            throw new CronManagerException(sprintf(
                '%s needs a user on every line. Set the user in CrontabSettings',
                $this->writer::class,
            ));
        }

        return $this->renderer->render($this->settings, $this->jobs->all());
    }

    private function plan(string $current, ?CrontabBlock $existing, CrontabBlock $desired): SyncPlan
    {
        [$added, $removed, $unchanged] = $this->diffLines($existing?->allLines() ?? [], $desired->allLines());

        return new SyncPlan(
            $added,
            $removed,
            $unchanged,
            $existing?->isHandEdited() ?? false,
            $current,
            $this->parser->replace($current, $desired),
        );
    }

    /**
     * Line diff by longest common subsequence. Blocks are small, so the quadratic table is fine.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return array{list<string>, list<string>, list<string>} Added, removed, unchanged
     */
    private function diffLines(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $common = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $common[$i][$j] = $old[$i] === $new[$j]
                    ? $common[$i + 1][$j + 1] + 1
                    : max($common[$i + 1][$j], $common[$i][$j + 1]);
            }
        }

        $added = [];
        $removed = [];
        $unchanged = [];
        $i = 0;
        $j = 0;
        while ($i < $oldCount && $j < $newCount) {
            if ($old[$i] === $new[$j]) {
                $unchanged[] = $old[$i];
                $i++;
                $j++;
            } elseif ($common[$i + 1][$j] >= $common[$i][$j + 1]) {
                $removed[] = $old[$i++];
            } else {
                $added[] = $new[$j++];
            }
        }
        while ($i < $oldCount) {
            $removed[] = $old[$i++];
        }
        while ($j < $newCount) {
            $added[] = $new[$j++];
        }

        return [$added, $removed, $unchanged];
    }
}

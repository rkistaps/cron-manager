<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Integrations\TheApp;

use DateTimeImmutable;
use InvalidArgumentException;
use rkistaps\CronManager\Exceptions\CronManagerException;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Services\CronSyncService;
use rkistaps\CronManager\Services\JobRunService;
use rkistaps\CronManager\Structures\SyncPlan;
use TheApp\Components\CommandRunner;
use TheApp\Interfaces\CommandConfiguratorInterface;
use TheApp\Interfaces\OutputInterface;

/**
 * Registers the cron commands on rkistaps/the-app's CommandRunner
 */
final readonly class CronCommandConfigurator implements CommandConfiguratorInterface
{
    private const string TIME_FORMAT = 'Y-m-d H:i';
    private const int NEXT_RUNS = 3;

    private string $prefix;

    public function __construct(
        private JobRepositoryInterface $jobs,
        private CronSyncService $syncService,
        private JobRunService $jobRunService,
        private RunHistoryStoreInterface $history,
        string $prefix = 'cron',
    ) {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new InvalidArgumentException('The command prefix may not be empty');
        }
        $this->prefix = $prefix;
    }

    public function configureCommands(CommandRunner $commandRunner): void
    {
        $commandRunner
            ->addCommand($this->prefix . '/list', fn (OutputInterface $output): int => $this->list($output))
            ->addCommand($this->prefix . '/diff', fn (OutputInterface $output): int => $this->diff($output))
            ->addCommand(
                $this->prefix . '/sync',
                fn (OutputInterface $output, bool $force = false): int => $this->sync($output, $force),
            )
            ->addCommand($this->prefix . '/remove', fn (OutputInterface $output): int => $this->remove($output))
            ->addCommand($this->prefix . '/run', fn (OutputInterface $output, string $job): int => $this->run($output, $job))
            ->addCommand($this->prefix . '/status', fn (OutputInterface $output): int => $this->status($output));
    }

    private function list(OutputInterface $output): int
    {
        $jobs = $this->jobs->all();
        if ($jobs === []) {
            $output->writeln('No jobs are defined.');

            return 0;
        }

        $rows = [];
        foreach ($jobs as $job) {
            $nextRuns = $job->enabled
                ? 'next: ' . implode(', ', array_map(
                    static fn (DateTimeImmutable $time): string => $time->format(self::TIME_FORMAT),
                    $job->schedule->nextRunTimes(self::NEXT_RUNS),
                ))
                : 'disabled';
            $rows[] = [$job->name, $job->schedule->expression, $nextRuns];
        }

        $this->writeTable($output, $rows);

        return 0;
    }

    private function diff(OutputInterface $output): int
    {
        $plan = $this->syncService->diff();

        if ($plan->handEdited) {
            $output->error('The block was edited by hand. Sync refuses to overwrite it without --force.');
        }

        if (!$plan->hasChanges()) {
            $output->writeln('The crontab is up to date.');

            return 0;
        }

        $this->writePlan($output, $plan);

        return 0;
    }

    private function sync(OutputInterface $output, bool $force): int
    {
        try {
            $plan = $this->syncService->sync($force);
        } catch (CronManagerException $exception) {
            $output->error($exception->getMessage());

            return 1;
        }

        if (!$plan->hasChanges()) {
            $output->writeln('The crontab is up to date.');

            return 0;
        }

        $this->writePlan($output, $plan);
        $output->writeln(sprintf('Crontab updated: %d added, %d removed.', count($plan->added), count($plan->removed)));

        return 0;
    }

    private function remove(OutputInterface $output): int
    {
        $output->writeln($this->syncService->remove()
            ? 'Removed the block from the crontab.'
            : 'The crontab has no block for this app.');

        return 0;
    }

    private function run(OutputInterface $output, string $job): int
    {
        try {
            return $this->jobRunService->run($job)->exitCode;
        } catch (CronManagerException $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    private function status(OutputInterface $output): int
    {
        $jobs = $this->jobs->all();
        if ($jobs === []) {
            $output->writeln('No jobs are defined.');

            return 0;
        }

        $rows = [];
        foreach ($jobs as $job) {
            $run = $this->history->lastRun($job->name);
            $rows[] = $run === null
                ? [$job->name, 'never run', '', '']
                : [
                    $job->name,
                    $run->startedAt->format('Y-m-d H:i:s'),
                    sprintf('%.1fs', $run->durationSeconds()),
                    sprintf('%s (exit %d)', $run->status()->value, $run->exitCode),
                ];
        }

        $this->writeTable($output, $rows);

        return 0;
    }

    private function writePlan(OutputInterface $output, SyncPlan $plan): void
    {
        foreach ($plan->removed as $line) {
            $output->writeln('- ' . $line);
        }
        foreach ($plan->added as $line) {
            $output->writeln('+ ' . $line);
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function writeTable(OutputInterface $output, array $rows): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $column => $cell) {
                $cells[] = str_pad($cell, $widths[$column]);
            }
            $output->writeln(rtrim(implode('  ', $cells)));
        }
    }
}

<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Integrations\TheApp;

use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Integrations\TheApp\CronCommandConfigurator;
use rkistaps\CronManager\Interfaces\CrontabWriterInterface;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Stores\FileRunHistoryStore;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Tests\_fixtures\InMemoryCrontabWriter;
use TheApp\Components\Output\BufferedOutput;
use TheApp\Factories\AppFactory;

final class CronCommandConfiguratorTest extends TestCase
{
    private string $directory;
    private InMemoryCrontabWriter $writer;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cron-manager-commands-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->writer = new InMemoryCrontabWriter("# foreign\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testListShowsEachJobWithItsNextRuns(): void
    {
        [$exitCode, $output] = $this->console('cron/list');

        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression('/^backfill +0 3 \* \* \* +next: \S+ 03:00, \S+ 03:00, \S+ 03:00$/m', $output);
        self::assertMatchesRegularExpression('/^failing +\* \* \* \* \* +next: /m', $output);
        self::assertMatchesRegularExpression('/^paused +0 0 \* \* \* +disabled$/m', $output);
    }

    public function testDiffShowsChangesWithoutWriting(): void
    {
        [$exitCode, $output] = $this->console('cron/diff');

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('+ # BEGIN cron-manager:test-app sha=', $output);
        self::assertSame(0, $this->writer->writes);
    }

    public function testSyncWritesAndASecondSyncIsUpToDate(): void
    {
        [$exitCode, $output] = $this->console('cron/sync');
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Crontab updated', $output);
        self::assertStringStartsWith("# foreign\n# BEGIN cron-manager:test-app", $this->writer->crontab);

        [$exitCode, $output] = $this->console('cron/sync');
        self::assertSame(0, $exitCode);
        self::assertSame("The crontab is up to date.\n", $output);
        self::assertSame(1, $this->writer->writes);
    }

    public function testSyncRefusesAHandEditedBlockUnlessForced(): void
    {
        $this->console('cron/sync');
        $this->writer->crontab = str_replace('0 3 * * *', '0 4 * * *', $this->writer->crontab);

        [$exitCode, , $errors] = $this->console('cron/sync');
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('edited by hand', $errors);
        self::assertStringContainsString('- 0 4 * * *', $errors);

        [$exitCode] = $this->console('cron/sync', '--force');
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 3 * * *', $this->writer->crontab);
    }

    public function testRemoveDeletesOnlyTheBlock(): void
    {
        $this->console('cron/sync');

        [$exitCode, $output] = $this->console('cron/remove');

        self::assertSame(0, $exitCode);
        self::assertSame("Removed the block from the crontab.\n", $output);
        self::assertSame("# foreign\n", $this->writer->crontab);
    }

    public function testRunReturnsTheJobsExitCodeAndStatusShowsIt(): void
    {
        [$exitCode] = $this->console('cron/run', '--job=failing');
        self::assertSame(7, $exitCode);

        [$exitCode] = $this->console('cron/run', '--job=backfill');
        self::assertSame(0, $exitCode);

        [$exitCode, $output] = $this->console('cron/status');
        self::assertSame(0, $exitCode);
        self::assertMatchesRegularExpression('/^backfill +\S+ \S+ +\d+\.\ds +succeeded \(exit 0\)$/m', $output);
        self::assertMatchesRegularExpression('/^failing +\S+ \S+ +\d+\.\ds +failed \(exit 7\)$/m', $output);
        self::assertMatchesRegularExpression('/^paused +never run$/m', $output);
    }

    public function testRunRejectsAnUnknownJob(): void
    {
        [$exitCode, , $errors] = $this->console('cron/run', '--job=missing');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No job named "missing"', $errors);
    }

    public function testUsesThePrefix(): void
    {
        $container = $this->container();
        $output = new BufferedOutput();

        $exitCode = AppFactory::console($container)
            ->withCommandConfigurators([$container->make(CronCommandConfigurator::class, ['prefix' => 'schedule'])])
            ->withOutput($output)
            ->run(['console.php', 'schedule/list']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('backfill', $output->getOutput());
    }

    /**
     * @return array{int, string, string} Exit code, output, errors
     */
    private function console(string ...$arguments): array
    {
        $output = new BufferedOutput();

        // By class name, so the container autowires the configurator and the services behind it
        $exitCode = AppFactory::console($this->container())
            ->withCommandConfigurators([CronCommandConfigurator::class])
            ->withOutput($output)
            ->run(['console.php', ...$arguments]);

        return [$exitCode, $output->getOutput(), $output->getErrors()];
    }

    private function container(): Container
    {
        return (new ContainerBuilder())
            ->addDefinitions([
                CrontabSettings::class => new CrontabSettings('test-app', lockDirectory: $this->directory . '/locks'),
                JobRepositoryInterface::class => new InMemoryJobRepository([
                    new JobDefinition('backfill', '0 3 * * *', 'true', $this->directory, description: 'Backfill'),
                    new JobDefinition('failing', '* * * * *', 'exit 7', $this->directory),
                    new JobDefinition('paused', '0 0 * * *', 'true', $this->directory, enabled: false),
                ]),
                CrontabWriterInterface::class => $this->writer,
                RunHistoryStoreInterface::class => new FileRunHistoryStore($this->directory . '/history'),
            ])
            ->build();
    }
}

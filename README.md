# cron-manager

Define your application's cron jobs in PHP and keep the system crontab in sync with them.

`rkistaps/cron-manager` renders your job definitions into one marked block of the crontab, rewrites that block when
the definitions change, and can record the outcome of every run.

- [What it does, and what it never touches](#what-it-does-and-what-it-never-touches)
- [Installation](#installation)
- [Defining jobs and syncing](#defining-jobs-and-syncing)
- [Command-line integration with rkistaps/the-app](#command-line-integration-with-rkistapsthe-app)
- [Writers: user crontab or /etc/cron.d](#writers-user-crontab-or-etccrond)
- [Cron inside Docker](#cron-inside-docker)
- [Run history and cron/status](#run-history-and-cronstatus)
- [Things to know](#things-to-know)
- [Development](#development)

## What it does, and what it never touches

The package owns exactly one block of the crontab, marked with the app id:

```
# BEGIN cron-manager:the-trader sha=7809c1319093
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
# job: funding-backfill - Backfill funding history
0 3 * * * cd '/var/www/html' && mkdir -p '/tmp/cron-manager' 'data/cron' && flock -n '/tmp/cron-manager/the-trader-funding-backfill.lock' ./run funding/backfill >> 'data/cron/funding-backfill.log' 2>&1
# END cron-manager:the-trader
```

- **Everything outside the block is left byte for byte as it is:** hand-written lines, comments, blank lines and
  other apps' blocks. Several apps can share one crontab, each with its own app id.
- **Syncing is safe to repeat.** If the block already matches the definitions, nothing is written.
- **Manual edits are detected.** `sha=` is the first 12 hex characters of the sha256 of the block body, the lines
  between the markers, each followed by a newline. If someone edits the block by hand, the checksum no longer
  matches and sync refuses to overwrite it unless you force it. The error lists the lines that differ.
- **You can preview a sync.** `diff()` returns what a sync would change without writing anything.
- **Each job is labelled** with a `# job: <name>` comment above its line, followed by its description when it has
  one. That is how a sync reads back which line belongs to which job, and reports jobs added, changed and removed
  rather than lines. A block written by a version before the labels counts every job as added on its next sync.
- **A new block goes at the end** of the crontab. An existing block is replaced where it is.

## Installation

```bash
composer require rkistaps/cron-manager
```

Requires PHP 8.3 or later, `flock` (from util-linux) for overlap protection, and a cron daemon.

## Defining jobs and syncing

A job is a `JobDefinition`:

| Field | Meaning |
|---|---|
| `name` | Slug matching `^[a-z0-9][a-z0-9-]*$`, unique per app |
| `schedule` | Standard five-field cron expression, such as `*/5 * * * *` or `0 3 * * mon-fri`. Macros such as `@daily` and non-standard rules such as `L`, `W`, `#` and `?` are rejected |
| `command` | The command to run, such as `./run funding/backfill`. Rendered as given. A newline or `%` is rejected |
| `workingDirectory` | Absolute path the job changes into first |
| `preventOverlap` | Default `true`. Skips a run while the previous one still holds its lock |
| `logFile` | Optional. When set, stdout and stderr are appended to it. A relative path is relative to the working directory |
| `enabled` | Default `true`. A disabled job is not rendered, so a sync removes its line |
| `description` | Optional, rendered after the job's name in the `# job:` comment above its line |

```php
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Services\CronSyncService;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Structures\JobDefinition;
use rkistaps\CronManager\Writers\UserCrontabWriter;

$jobs = new InMemoryJobRepository([
    new JobDefinition(
        name: 'funding-backfill',
        schedule: '0 3 * * *',
        command: './run funding/backfill',
        workingDirectory: '/var/www/html',
        logFile: 'data/cron/funding-backfill.log',
        description: 'Backfill funding history',
    ),
    new JobDefinition(
        name: 'prices-sync',
        schedule: '*/5 * * * *',
        command: './run prices/sync',
        workingDirectory: '/var/www/html',
    ),
]);

$settings = new CrontabSettings('the-trader');
$sync = new CronSyncService($settings, $jobs, new UserCrontabWriter());

$plan = $sync->diff();          // What would change, without writing
foreach ($plan->removed as $line) {
    echo "- {$line}\n";
}
foreach ($plan->added as $line) {
    echo "+ {$line}\n";
}
// The same change in jobs: the line lists above also hold the markers and the SHELL and PATH lines
echo implode(', ', $plan->jobsAdded), "\n";     // Also $plan->jobsChanged, $plan->jobsRemoved, $plan->blockCreated

$sync->sync();                  // Writes the block. Throws HandEditedBlockException on a hand-edited block
$sync->sync(force: true);       // Overwrites a hand-edited block
$sync->remove();                // Deletes this app's block and nothing else
```

`CrontabSettings` holds what is shared by every job:

| Setting | Default | Meaning |
|---|---|---|
| `appId` | (required) | Names the block. Letters, digits, `-` and `_` |
| `shell` | `/bin/bash` | Rendered as `SHELL=` |
| `path` | `/usr/local/bin:/usr/bin:/bin` | Rendered as `PATH=` |
| `lockDirectory` | `/tmp/cron-manager` | Lock files are `<lockDirectory>/<app-id>-<job-name>.lock` |
| `runWrapperCommand` | `null` | The command that runs one job by name, such as `./run cron/run`. See [run history](#run-history-and-cronstatus) |
| `recordHistory` | `false` | Call the run wrapper on each line instead of the command |
| `user` | `null` | User field rendered after the schedule. Required by `CronDWriter` |

`InMemoryJobRepository` covers jobs defined in code. To load them from somewhere else, implement
`JobRepositoryInterface`.

### Validation

Definitions are checked when they are constructed, so a bad job fails in your code, not silently in cron:

- invalid schedules, and names with characters other than lowercase letters, digits and `-`;
- commands, paths and settings containing a newline, which would end the crontab line early;
- commands and paths containing `%`, which cron turns into a newline even inside quotes. Move such a command into
  a script.

The working directory, lock path, log path and job name are passed through `escapeshellarg` on the rendered line.
The command is trusted app configuration and is rendered as given.

## Command-line integration with rkistaps/the-app

If your app uses [rkistaps/the-app](https://github.com/rkistaps/the-app), `CronCommandConfigurator` registers the
commands for you:

```bash
composer require rkistaps/the-app
```

| Command | What it does |
|---|---|
| `cron/list` | Shows every defined job with its schedule and next three run times |
| `cron/diff` | Shows what a sync would change, without writing: the lines, then the jobs added, changed and removed |
| `cron/sync [--force]` | Writes the block and says which jobs it added, changed and removed. Refuses on a hand-edited block unless `--force` |
| `cron/remove` | Deletes this app's block and nothing else |
| `cron/run --job=<name>` | The run wrapper. Runs one job and records the outcome |
| `cron/status` | Shows each job's last run: when, how long, exit code |

The configurator gets its services from the container. Define the settings, the jobs, the writer and the history
store:

```php
use DI\ContainerBuilder;
use rkistaps\CronManager\Interfaces\CrontabWriterInterface;
use rkistaps\CronManager\Interfaces\JobRepositoryInterface;
use rkistaps\CronManager\Interfaces\RunHistoryStoreInterface;
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Stores\FileRunHistoryStore;
use rkistaps\CronManager\Structures\CrontabSettings;
use rkistaps\CronManager\Writers\UserCrontabWriter;

$container = (new ContainerBuilder())
    ->addDefinitions([
        CrontabSettings::class => new CrontabSettings(
            'the-trader',
            runWrapperCommand: './run cron/run',
            recordHistory: true,
        ),
        JobRepositoryInterface::class => new InMemoryJobRepository(require __DIR__ . '/config/cron-jobs.php'),
        CrontabWriterInterface::class => new UserCrontabWriter(),
        RunHistoryStoreInterface::class => new FileRunHistoryStore(__DIR__ . '/data/cron/history'),
    ])
    ->build();
```

Then add the configurator to the console app:

```php
use rkistaps\CronManager\Integrations\TheApp\CronCommandConfigurator;
use TheApp\Factories\AppFactory;

$exitCode = AppFactory::console($container)
    ->withCommandConfigurators([
        CronCommandConfigurator::class,
    ])
    ->run($argv);

exit($exitCode);
```

```bash
./run cron/diff
./run cron/sync
```

For a prefix other than `cron`, pass an instance:
`$container->make(CronCommandConfigurator::class, ['prefix' => 'schedule'])`.

A common place for `./run cron/sync` is your deploy script, after the new code is in place.

## Writers: user crontab or /etc/cron.d

A writer reads and writes the crontab. Two are included:

**`UserCrontabWriter`** edits the crontab of the user running PHP through `crontab -l` and `crontab -`. Jobs run as
that user. Use it when the app's user owns its jobs, which is the usual case and needs no root.

```php
new UserCrontabWriter();                      // The current user's crontab
new UserCrontabWriter(user: 'www-data');      // Another user's crontab, through crontab -u. Needs root
new UserCrontabWriter(binary: '/usr/bin/crontab');
```

A user who never had a crontab makes `crontab -l` fail with "no crontab for &lt;user&gt;". That reads as an empty
crontab. Any other failure throws `CrontabAccessException`.

**`CronDWriter`** writes the file `/etc/cron.d/<app-id>`, which the app owns completely. Lines in `/etc/cron.d`
name the user to run as, so set `user` in the settings. Writing there needs root. Use it when the system is set up
as root, for example by a provisioning script or a container entrypoint, and when you don't want to touch any
user's crontab.

```php
$settings = new CrontabSettings('the-trader', user: 'www-data');
$sync = new CronSyncService($settings, $jobs, new CronDWriter('the-trader'));
```

Cron ignores files in `/etc/cron.d` whose names contain anything but letters, digits, `-` and `_`, such as a dot, so
`CronDWriter` rejects such app ids. It writes the file with mode `0644` and replaces it atomically. Removing the
block deletes the file.

## Cron inside Docker

Most PHP images, including the official `php` images, ship without a cron daemon. A crontab with nothing reading
it does nothing. In the container:

1. **Install cron.** On Debian-based images: `apt-get install -y cron`. On Alpine: `crond` is part of BusyBox.
2. **Start the daemon**, usually from the entrypoint, next to or instead of your main process: `cron` on Debian,
   `crond` on Alpine. Use `cron -f` to keep it in the foreground when it is the container's only process.
3. **Sync from inside the container**, after the code is in place. The crontab belongs to the container's user,
   and `UserCrontabWriter` edits the crontab of the user running PHP. If the entrypoint runs as root, jobs run as
   root, unless you use `UserCrontabWriter(user: 'www-data')` or `CronDWriter` with `user: 'www-data'`.
4. **Expect a minimal environment.** Cron doesn't pass the container's environment variables to jobs. Put what the
   jobs need in a file they read, or in the job's command.

The crontab lives in the container's filesystem, so it is gone when the container is recreated. Run the sync on
every start, for example in the entrypoint:

```bash
#!/bin/sh
set -e
./run cron/sync --force
cron
exec php-fpm
```

`--force` belongs here only because the container's crontab is never edited by hand. Don't use it on a shared
server.

## Run history and cron/status

With `recordHistory` on, each line calls the run wrapper instead of the command:

```
0 3 * * * cd '/var/www/html' && mkdir -p '/tmp/cron-manager' 'data/cron' && flock -n '/tmp/cron-manager/the-trader-funding-backfill.lock' ./run cron/run --job='funding-backfill' >> 'data/cron/funding-backfill.log' 2>&1
```

The wrapper, `cron/run` or `JobRunService::run()`, runs the job's command through the configured shell in the job's
working directory and records a `JobRun` with start time, end time, duration and exit code. The job's output goes
to the same log file as before.

**The wrapper's exit code is the job's exit code**, so cron mail and external monitoring still see failures. A job
killed by a signal exits with 128 plus the signal number, as in the shell. If recording the run fails, the error is
logged through the PSR-3 logger and the job's exit code still comes through.

`runWrapperCommand` runs from the job's working directory, like the command it replaces.

`FileRunHistoryStore` keeps one JSON-lines file per job, holding the newest 100 runs by default:

```php
new FileRunHistoryStore('/var/www/html/data/cron/history', maxRunsPerJob: 500);
```

For a database or another store, implement `RunHistoryStoreInterface`.

`cron/status` shows each job's last run:

```
funding-backfill  2026-10-02 03:00:00  12.4s  succeeded (exit 0)
prices-sync       2026-10-02 14:35:00  0.8s   failed (exit 2)
weekly-report     never run
```

## Things to know

- **No timezone support.** Cron uses the system timezone, and so do the run times in `cron/list`. Set the system
  or container timezone if your schedules assume a particular one.
- **`SHELL` and `PATH` lines apply to every line after them**, foreign ones included, in the same crontab. A new
  block goes at the end of the crontab, so this only matters for lines added after it.
- **`flock` runs the command directly, without a shell.** With overlap protection on, a compound command such as
  `a && b` would only hold the lock for `a`. Put compound commands in a script, or turn overlap protection off.
- **A skipped run fails.** When the previous run still holds the lock, `flock -n` exits with code 1, and no run is
  recorded.
- **The lock directory and the log file's directory are created on each run** with `mkdir -p`, since `/tmp` is
  emptied on reboot and neither `flock` nor the shell's `>>` creates directories. Without it, a missing log
  directory makes the shell refuse the line before the job starts, and nothing runs or records the failure.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests never touch the real crontab: `UserCrontabWriter` is pointed at `tests/_fixtures/fake-crontab.sh`. They
need a Unix-like system with `bash` and `flock`.

## License

MIT. See [LICENSE](LICENSE).

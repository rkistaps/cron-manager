<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Repositories\InMemoryJobRepository;
use rkistaps\CronManager\Structures\JobDefinition;

final class InMemoryJobRepositoryTest extends TestCase
{
    public function testIsEmptyByDefault(): void
    {
        $repository = new InMemoryJobRepository();

        self::assertSame([], $repository->all());
        self::assertNull($repository->get('anything'));
    }

    public function testFindsJobsByName(): void
    {
        $first = new JobDefinition('first', '* * * * *', './run first', '/app');
        $second = new JobDefinition('second', '* * * * *', './run second', '/app');
        $repository = new InMemoryJobRepository([$first, $second]);

        self::assertSame([$first, $second], $repository->all());
        self::assertSame($second, $repository->get('second'));
    }

    public function testRejectsTwoJobsWithTheSameName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"first" is defined twice');

        new InMemoryJobRepository([
            new JobDefinition('first', '* * * * *', './run a', '/app'),
            new JobDefinition('first', '0 * * * *', './run b', '/app'),
        ]);
    }
}

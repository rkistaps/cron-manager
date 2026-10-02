<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Services;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Exceptions\MalformedCrontabException;
use rkistaps\CronManager\Services\CrontabParser;
use rkistaps\CronManager\Structures\CrontabBlock;

final class CrontabParserTest extends TestCase
{
    private CrontabParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CrontabParser();
    }

    public function testFindsNothingInACrontabWithoutTheBlock(): void
    {
        self::assertNull($this->parser->find('', 'app'));
        self::assertNull($this->parser->find("MAILTO=me\n* * * * * true\n", 'app'));
    }

    public function testFindsTheBlockWithItsChecksum(): void
    {
        $block = new CrontabBlock('app', ['SHELL=/bin/bash', '* * * * * true']);
        $found = $this->parser->find("# mine\n" . $block->render() . "0 0 * * * other\n", 'app');

        self::assertNotNull($found);
        self::assertSame($block->lines, $found->lines);
        self::assertSame($block->checksum, $found->checksum);
        self::assertFalse($found->isHandEdited());
    }

    public function testDoesNotConfuseAppIdsThatShareAPrefix(): void
    {
        $other = new CrontabBlock('app-two', ['* * * * * two']);

        self::assertNull($this->parser->find($other->render(), 'app'));
        self::assertSame("* * * * * one\n" . $other->render(), $this->parser->replace("* * * * * one\n" . $other->render(), new CrontabBlock('app-two', ['* * * * * two'])));
    }

    public function testABeginLineWithoutAChecksumCountsAsHandEdited(): void
    {
        $found = $this->parser->find("# BEGIN cron-manager:app\n* * * * * true\n# END cron-manager:app\n", 'app');

        self::assertNotNull($found);
        self::assertTrue($found->isHandEdited());
    }

    public function testAppendsTheBlockAfterExistingContent(): void
    {
        $block = new CrontabBlock('app', ['* * * * * true']);

        self::assertSame($block->render(), $this->parser->replace('', $block));
        self::assertSame("* * * * * other\n" . $block->render(), $this->parser->replace("* * * * * other\n", $block));
        self::assertSame("* * * * * other\n" . $block->render(), $this->parser->replace('* * * * * other', $block));
    }

    public function testReplacesTheBlockInPlace(): void
    {
        $old = new CrontabBlock('app', ['* * * * * old']);
        $new = new CrontabBlock('app', ['* * * * * new', '0 * * * * newer']);

        self::assertSame(
            "# before\n\n" . $new->render() . "\n# after\n",
            $this->parser->replace("# before\n\n" . $old->render() . "\n# after\n", $new),
        );
    }

    public function testRemovingLeavesEverythingElseByteForByte(): void
    {
        $before = "MAILTO=ops@example.com\n\n# hand-written, with trailing space \n0 1 * * * /usr/bin/backup\t--all\n";
        $after = "\n   \n# BEGIN cron-manager:other sha=000000000000\n* * * * * other\n# END cron-manager:other\n\n";
        $block = new CrontabBlock('app', ['* * * * * true']);

        self::assertSame($before . $after, $this->parser->remove($before . $block->render() . $after, 'app'));
    }

    public function testRemovingAMissingBlockChangesNothing(): void
    {
        self::assertSame("* * * * * true\n", $this->parser->remove("* * * * * true\n", 'app'));
    }

    public function testRejectsABlockWithoutAnEndMarker(): void
    {
        $this->expectException(MalformedCrontabException::class);

        $this->parser->find("# BEGIN cron-manager:app sha=000000000000\n* * * * * true\n", 'app');
    }

    public function testRejectsTwoBlocksForTheSameApp(): void
    {
        $block = (new CrontabBlock('app', ['* * * * * true']))->render();

        $this->expectException(MalformedCrontabException::class);

        $this->parser->find($block . $block, 'app');
    }

    public function testRejectsAnEndMarkerWithoutABeginMarker(): void
    {
        $this->expectException(MalformedCrontabException::class);

        $this->parser->find("* * * * * true\n# END cron-manager:app\n", 'app');
    }
}

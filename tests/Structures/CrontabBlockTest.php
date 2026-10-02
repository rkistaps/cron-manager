<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Tests\Structures;

use PHPUnit\Framework\TestCase;
use rkistaps\CronManager\Structures\CrontabBlock;

final class CrontabBlockTest extends TestCase
{
    public function testChecksumIsTheStartOfTheBodysSha256(): void
    {
        $block = new CrontabBlock('app', ['SHELL=/bin/bash', '* * * * * true']);

        self::assertSame(substr(hash('sha256', "SHELL=/bin/bash\n* * * * * true\n"), 0, 12), $block->checksum);
        self::assertFalse($block->isHandEdited());
    }

    public function testRendersMarkersAroundTheBody(): void
    {
        $block = new CrontabBlock('app', ['* * * * * true']);

        self::assertSame(
            "# BEGIN cron-manager:app sha={$block->checksum}\n* * * * * true\n# END cron-manager:app\n",
            $block->render(),
        );
    }

    public function testABodyThatNoLongerMatchesItsChecksumIsHandEdited(): void
    {
        $original = new CrontabBlock('app', ['* * * * * true']);

        self::assertTrue((new CrontabBlock('app', ['* * * * * false'], $original->checksum))->isHandEdited());
    }
}

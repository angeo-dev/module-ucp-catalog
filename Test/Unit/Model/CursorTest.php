<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\Cursor;
use PHPUnit\Framework\TestCase;

class CursorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cursor = new Cursor();

        self::assertSame(7, $cursor->decode($cursor->encode(7)));
    }

    public function testInvalidCursorsStartAtPageOne(): void
    {
        $cursor = new Cursor();

        self::assertSame(1, $cursor->decode(null));
        self::assertSame(1, $cursor->decode(''));
        self::assertSame(1, $cursor->decode('!!!'));
        self::assertSame(1, $cursor->decode(rtrim(strtr(base64_encode('{"p":"2"}'), '+/', '-_'), '=')));
        self::assertSame(1, $cursor->decode($cursor->encode(0)));
    }

    public function testPagesPastTheCapAreRefused(): void
    {
        // 2.1.2: a forged cursor must not become a huge SQL OFFSET.
        $cursor = new Cursor();

        self::assertSame(Cursor::MAX_PAGE, $cursor->decode($cursor->encode(Cursor::MAX_PAGE)));
        self::assertSame(1, $cursor->decode($cursor->encode(Cursor::MAX_PAGE + 1)));
        self::assertSame(1, $cursor->decode($cursor->encode(1000000000)));
    }
}

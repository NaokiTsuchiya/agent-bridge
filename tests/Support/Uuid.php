<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use function md5;
use function substr;
use function uniqid;

/** Session identifiers for tests, in the v4 shape the real CLI insists on. */
final class Uuid
{
    /** @return string a fresh identifier, so that one test's session cannot collide with another's */
    public static function random(): string
    {
        $hex = md5(uniqid('', more_entropy: true));
        $a = substr($hex, offset: 0, length: 8);
        $b = substr($hex, offset: 8, length: 4);
        $c = substr($hex, offset: 13, length: 3);
        $d = substr($hex, offset: 17, length: 3);
        $e = substr($hex, offset: 20, length: 12);

        return "{$a}-{$b}-4{$c}-8{$d}-{$e}";
    }
}

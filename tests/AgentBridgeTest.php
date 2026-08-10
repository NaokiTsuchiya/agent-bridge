<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests;

use NaokiTsuchiya\AgentBridge\AgentBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class AgentBridgeTest extends TestCase
{
    /** The package name is duplicated in code and in composer.json; this keeps them equal. */
    #[Test]
    public function packageNameMatchesComposerJson(): void
    {
        $json = file_get_contents(dirname(__DIR__) . '/composer.json');
        self::assertIsString($json);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(AgentBridge::PACKAGE, $composer['name'] ?? null);
    }
}

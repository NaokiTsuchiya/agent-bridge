<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The defaults every Socket Mode connection and its HTTP client used to carry as constructor
 * literals, now carried here instead. Pinned so that moving them into {@see \NaokiTsuchiya\AgentBridge\Di\SlackModule}'s
 * qualifier bindings could not quietly change one.
 */
final class ConnectionSettingsTest extends TestCase
{
    /** The four non-backoff values, unchanged from what the constructors used to default to. */
    #[Test]
    public function keepsTheSameDefaultsTheConstructorsUsedToCarry(): void
    {
        $settings = new ConnectionSettings();

        self::assertSame(1000, $settings->envelopeCapacity);
        self::assertSame(0.001, $settings->frameHandoffTimeout);
        self::assertSame(60.0, $settings->socketSilenceTimeout);
        self::assertSame(60.0, $settings->httpClientTimeout);
    }

    /** The backoff arithmetic, split into its own settings object so as not to overrun this project's parameter limit. */
    #[Test]
    public function keepsTheSameBackoffDefaultsTheConstructorUsedToCarry(): void
    {
        $settings = new BackoffSettings();

        self::assertSame(1.0, $settings->base);
        self::assertSame(30.0, $settings->max);
        self::assertSame(0.5, $settings->jitterRatio);
    }
}

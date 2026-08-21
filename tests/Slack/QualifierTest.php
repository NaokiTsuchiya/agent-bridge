<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Be\Framework\Module\BeModule;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

/**
 * The seven scalars a `Qualifier` attribute stands in for, resolved the way the compiled injector
 * resolves everything else — never read off the attribute's own reflection, which proves nothing
 * about whether Ray.Di can actually build one — and checked against {@see BackoffSettings}'/
 * {@see ConnectionSettings}' defaults, which is where the value now lives.
 */
final class QualifierTest extends TestCase
{
    /** The wait after the first lost connection. */
    #[Test]
    public function backoffBaseIsResolvedFromTheInjector(): void
    {
        self::assertSame(new BackoffSettings()->base, self::injector()->getInstance('', BackoffBase::class));
    }

    /** The ceiling the backoff's doubling stops at. */
    #[Test]
    public function backoffMaxIsResolvedFromTheInjector(): void
    {
        self::assertSame(new BackoffSettings()->max, self::injector()->getInstance('', BackoffMax::class));
    }

    /** How much of the backoff delay may be taken off. */
    #[Test]
    public function backoffJitterRatioIsResolvedFromTheInjector(): void
    {
        self::assertSame(
            new BackoffSettings()->jitterRatio,
            self::injector()->getInstance('', BackoffJitterRatio::class),
        );
    }

    /** How many envelope ids are remembered at once. */
    #[Test]
    public function envelopeCapacityIsResolvedFromTheInjector(): void
    {
        self::assertSame(
            new ConnectionSettings()->envelopeCapacity,
            self::injector()->getInstance('', EnvelopeCapacity::class),
        );
    }

    /** How long a full channel may be waited on when a frame is handed on. */
    #[Test]
    public function frameHandoffTimeoutIsResolvedFromTheInjector(): void
    {
        self::assertSame(
            new ConnectionSettings()->frameHandoffTimeout,
            self::injector()->getInstance('', FrameHandoffTimeout::class),
        );
    }

    /** How long a connection may produce nothing before it is discarded. */
    #[Test]
    public function socketSilenceTimeoutIsResolvedFromTheInjector(): void
    {
        self::assertSame(
            new ConnectionSettings()->socketSilenceTimeout,
            self::injector()->getInstance('', SocketSilenceTimeout::class),
        );
    }

    /** The ceiling on a single socket operation. */
    #[Test]
    public function httpClientTimeoutIsResolvedFromTheInjector(): void
    {
        self::assertSame(
            new ConnectionSettings()->httpClientTimeout,
            self::injector()->getInstance('', HttpClientTimeout::class),
        );
    }

    /**
     * A raw injector over {@see SlackWiringModule} rather than a compiled one: the Slack context has
     * no compiled-injector test helper the way `serve` does ({@see \NaokiTsuchiya\AgentBridge\Di\CompiledServe}),
     * and {@see SlackWiringTest} resolves the same way for the same reason.
     */
    private static function injector(): InjectorInterface
    {
        return new Injector(new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackWiringModule()));
    }
}

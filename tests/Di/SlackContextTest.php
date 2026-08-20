<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Slack\SlackServer;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\CompiledContextInterface;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;

/**
 * What a Slack process compiles, and what it deliberately does not warm up.
 *
 * Read from the module the context composes rather than from compiled scripts: what is decided here
 * is which front end goes into the compile, and no compile is needed to see that. The warmup list is
 * the other half — this context reads three credentials as it is built, so a warmed-up Slack class
 * would turn every start into a connection attempt before the process had said it was up.
 *
 * @internal
 */
final class SlackContextTest extends TestCase
{
    /** The app dir the meta points at; nothing is compiled into it, so it stays empty. */
    private string $appDir = '';

    /** An app dir of its own per case, because a meta is built out of a real directory. */
    #[Override]
    protected function setUp(): void
    {
        $this->appDir = TempDir::make('slack-context');
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->appDir);
    }

    /**
     * The context implements ContextInterface and CompiledContextInterface.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function isAContext(): void
    {
        $context = $this->context();

        self::assertInstanceOf(ContextInterface::class, $context);
        self::assertInstanceOf(CompiledContextInterface::class, $context);
        self::assertInstanceOf(AbstractModule::class, $context());
    }

    /**
     * Be's module is the outer one, and what it was given is the Slack front end's.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function composesTheSlackFrontEndWithBe(): void
    {
        $context = $this->context();
        $container = $context()->getContainer()->getContainer();

        // Ray.Di keys a binding by what it is for and the name it was bound under, joined by a dash;
        // these are bound unnamed, so the name is empty.
        self::assertArrayHasKey(BecomingInterface::class . '-', $container);
        self::assertArrayHasKey(SlackServer::class . '-', $container);
    }

    /**
     * @return SlackContext the context of a Slack process, over an app dir nothing was compiled into
     *
     * @throws InvalidAppMeta
     */
    private function context(): SlackContext
    {
        return new SlackContext(AppMeta::fromAppDir($this->appDir, SlackContext::NAME));
    }
}

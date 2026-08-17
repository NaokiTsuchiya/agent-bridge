<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Be\Framework\BecomingInterface;
use BEAR\Resource\ResourceInterface;
use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Slack\SlackServer;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_pop;
use function explode;
use function implode;
use function str_starts_with;

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
     * Be's module is the outer one, and what it was given is the Slack front end's.
     *
     * The binding of the server is what says which module that was: {@see SlackModule} is the only
     * place it is bound, so a context that composed the command line's would compile a process that
     * has nothing to start. Read through the context's public __invoke() rather than the protected
     * appModule() it wraps, so this does not need Reflection to reach it: Ray.Di's
     * {@see \Ray\Di\AbstractModule::__construct()} merges an installed module's container into its
     * own, so both bindings show up on the outer module returned by __invoke() — Be's own {@see
     * BecomingInterface} binding proves Be composed, and the server binding proves what it wrapped
     * was Slack's module.
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
     * The same three as the command line's context, because everything below the front end is the
     * same application.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function warmsUpTheResourceLayerBeAndTheExecutionLayer(): void
    {
        self::assertSame(
            [ResourceInterface::class, BecomingInterface::class, AgentRunner::class],
            $this->context()->getSavedSingleton(),
        );
    }

    /**
     * And nothing of Slack's, which is the reason the list is written out rather than grown.
     *
     * The namespace is read off the class's own name with string functions rather than Reflection,
     * and written out rather than hardcoded, so that this keeps meaning what it means if the adapter
     * is ever moved.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function warmsUpNothingThatWouldReadACredential(): void
    {
        $slackParts = explode('\\', SocketModeClient::class);
        array_pop($slackParts);
        $slack = implode('\\', $slackParts);

        foreach ($this->context()->getSavedSingleton() as $class) {
            self::assertFalse(str_starts_with($class, $slack), "{$class} is Slack's, and is warmed up.");
        }
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

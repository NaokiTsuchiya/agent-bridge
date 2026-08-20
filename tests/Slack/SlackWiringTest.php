<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Be\Framework\Module\BeModule;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;
use ReflectionClass;
use ReflectionException;
use Swoole\Coroutine\Channel;
use Throwable;

use function dirname;
use function file_get_contents;
use function glob;
use function strpos;
use function substr_count;

/**
 * What a Slack process is wired to, and what putting it in did not cost.
 *
 * The first half resolves from the real module with only the credentials taken out
 * ({@see SlackWiringModule}), because a wiring mistake — a front end nobody bound, two maps where
 * there should be one — is invisible until a message arrives. The second half is about the claim
 * the issue was written to test: that a second front end goes in without the layers below it
 * noticing.
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackWiringTest extends TestCase
{
    /** Where nothing of Slack's may appear, however this front end turned out. */
    private const array LAYERS_BELOW = ['Chat', 'Event', 'Pipeline', 'Runner', 'Thread', 'Worktree'];

    /** The one injector this test process resolves from. */
    private static ?InjectorInterface $injector = null;

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$injector = null;
    }

    /** The pipeline writes to a workspace here, where on the command line it writes to a terminal. */
    #[Test]
    public function answersIntoAWorkspace(): void
    {
        self::assertInstanceOf(SlackEgress::class, self::injector()->getInstance(ChatEgress::class));
    }

    /** One front end per process: a second would hold a second map and answer into nothing. */
    #[Test]
    public function keepsOneFrontEndPerInjector(): void
    {
        $injector = self::injector();

        self::assertSame($injector->getInstance(ChatEgress::class), $injector->getInstance(ChatEgress::class));
    }

    /**
     * The two halves of the front end share the map that joins a thread to its channel.
     *
     * Nothing else can show this: both sides resolve without complaint when the map is not shared,
     * and the first sign of it would be an answer that goes nowhere.
     *
     * @throws Throwable
     */
    #[Test]
    public function readsBackTheChannelTheIngressWroteDown(): void
    {
        $injector = self::injector();
        $ingress = $injector->getInstance(SlackIngress::class);
        $channels = $injector->getInstance(ThreadChannels::class);
        self::assertInstanceOf(SlackIngress::class, $ingress);
        self::assertInstanceOf(ThreadChannels::class, $channels);

        $envelopes = $injector->getInstance(Channel::class);
        self::assertInstanceOf(Channel::class, $envelopes);

        Coro::run(static function () use ($envelopes, $ingress): void {
            $envelopes->push([
                'type' => 'event_callback',
                'event' => [
                    'type' => 'app_mention',
                    'user' => 'U0HUMAN',
                    'channel' => 'C0CHANNEL',
                    'ts' => '1700000001.123456',
                    'text' => 'hello',
                ],
            ]);
            $envelopes->close();

            foreach ($ingress->listen() as $message) {
                self::assertSame('1700000001.123456', $message->nativeId);
            }
        });

        self::assertSame('C0CHANNEL', $channels->channelFor('1700000001.123456'));
    }

    /**
     * The pace of a streamed reply comes from the injector, not from the front end.
     *
     * A value written into the reply itself could not be moved by a deployment that found it wrong
     * for its workspace, and the tier `chat.appendStream` is rated at is why the default cannot go
     * below 600ms: that is 100 calls a minute, which is what the method allows.
     */
    #[Test]
    public function takesThePaceOfAReplyFromTheSettings(): void
    {
        $settings = self::injector()->getInstance(StreamingSettings::class);

        self::assertInstanceOf(StreamingSettings::class, $settings);
        self::assertGreaterThanOrEqual(600, $settings->throttleMilliseconds);
    }

    /** And what that pace is measured against comes from there too. */
    #[Test]
    public function tellsTheTimeWithTheMachinesClock(): void
    {
        self::assertInstanceOf(SystemClock::class, self::injector()->getInstance(ClockInterface::class));
    }

    /** What the process resolves is the server, put together by the module rather than by a command. */
    #[Test]
    public function resolvesTheServerAsOneThing(): void
    {
        self::assertInstanceOf(SlackServer::class, self::injector()->getInstance(SlackServer::class));
    }

    /**
     * The command asks the injector for exactly that one thing.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function asksTheInjectorForOneThing(): void
    {
        self::assertSame(1, substr_count(self::sourceOf(SlackCommand::class), needle: 'getInstance('));
    }

    /** The context the compile writes for and the one the process reads from are the same name. */
    #[Test]
    public function compilesTheContextTheProcessStarts(): void
    {
        $composer = file_get_contents(dirname(__DIR__, levels: 2) . '/composer.json');
        self::assertIsString($composer);

        self::assertSame('slack', SlackContext::NAME);
        self::assertStringContainsString('bootstrap.php \"$PWD\" ' . SlackContext::NAME, $composer);
    }

    /**
     * Nothing below the ports reaches into the Slack adapter.
     *
     * This is the issue's own claim, asserted rather than left to a diff: the execution layer, the
     * events, the pipeline, the thread derivation and the worktrees are supposed to be exactly what
     * they were before a second front end existed, and the way that stops being true is one import
     * at a time.
     *
     * @param string $layer a directory under src/ that the front end must not have reached into
     *
     * @throws ReflectionException
     */
    #[DataProvider('layersBelow')]
    #[Test]
    public function staysOutOfEverythingBelowThePorts(string $layer): void
    {
        $adapter = new ReflectionClass(SlackEgress::class)->getNamespaceName();
        $files = glob(dirname(__DIR__, levels: 2) . "/src/{$layer}/*.php");
        self::assertIsArray($files);
        self::assertNotSame([], $files, "There is nothing under src/{$layer}.");

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertFalse(strpos($source, needle: $adapter), "{$file} reaches into the Slack adapter.");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function layersBelow(): iterable
    {
        foreach (self::LAYERS_BELOW as $layer) {
            yield $layer => [$layer];
        }
    }

    /** The injector of a Slack process, minus the three things it would read from the environment. */
    private static function injector(): InjectorInterface
    {
        if (self::$injector === null) {
            self::$injector = new Injector(new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackWiringModule()));
        }

        return self::$injector;
    }

    /**
     * @param class-string $class
     *
     * @return string the file that class is written in
     *
     * @throws ReflectionException
     */
    private static function sourceOf(string $class): string
    {
        $file = new ReflectionClass($class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }
}

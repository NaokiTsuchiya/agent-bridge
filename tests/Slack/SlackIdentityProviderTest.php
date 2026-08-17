<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use Be\Framework\Module\BeModule;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\SlackModule;
use NaokiTsuchiya\AgentBridge\Slack\SlackException;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentity;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentityProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function getenv;
use function putenv;

/**
 * Where this app's own user id is read from, and what a start-up that has none is told.
 *
 * Every refusal here is met by a person starting a process, so each case asks the message to name
 * the variable: without the name there is nothing to act on, and the two refusals are different
 * actions — one variable to export, or one value to correct. The value itself is asked to stay out
 * of the message, because the same message is read in logs.
 *
 * @internal
 */
final class SlackIdentityProviderTest extends TestCase
{
    /** Shaped like a workspace's bot user id, but made up. */
    private const string ID = 'U0BOT';

    /** What the bot user id variable held before the case ran, put back afterwards. */
    private string|false $before = false;

    /** The variable is process-wide, so a case that changed it must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        $this->before = getenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE);
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->before === false) {
            putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE);

            return;
        }

        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE . "={$this->before}");
    }

    /**
     * The one place this app learns which user it is.
     *
     * @throws SlackException
     */
    #[Test]
    public function readsTheBotUserIdFromTheEnvironment(): void
    {
        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE . '=' . self::ID);

        self::assertSame(self::ID, new SlackIdentityProvider()->get()->botUserId);
    }

    /**
     * A variable nobody exported is the first thing a new deployment gets wrong, and it is a
     * different mistake from a variable holding the wrong thing: nothing was refused, so there is
     * nothing to have refused it.
     */
    #[Test]
    public function namesTheVariableWhenNobodyExportedIt(): void
    {
        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE);

        try {
            new SlackIdentityProvider()->get();
        } catch (SlackException $refusal) {
            self::assertStringContainsString(SlackIdentityProvider::ENVIRONMENT_VARIABLE, $refusal->getMessage());
            self::assertStringContainsString('is not set', $refusal->getMessage());
            self::assertStringContainsString('docs/slack-adapter.md', $refusal->getMessage());
            self::assertNull($refusal->getPrevious());

            return;
        }

        self::fail('A process started without knowing which user it is.');
    }

    /**
     * A value that names nobody is refused as the environment's fault rather than the caller's, the
     * reason it could not be used is carried along instead of being restated, and the value itself
     * stays out of a message that is read in logs.
     *
     * @param string $value what a deployment might have exported
     */
    #[DataProvider('valuesThatNameNobody')]
    #[Test]
    public function namesTheVariableWhenItHoldsSomethingThatNamesNobody(string $value): void
    {
        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE . "={$value}");

        try {
            new SlackIdentityProvider()->get();
        } catch (SlackException $refusal) {
            self::assertStringStartsWith(
                SlackIdentityProvider::ENVIRONMENT_VARIABLE . ' does not name a user.',
                $refusal->getMessage(),
            );
            self::assertInstanceOf(InvalidArgumentException::class, $refusal->getPrevious());
            self::assertStringNotContainsString($value, $refusal->getMessage());

            return;
        }

        self::fail("A value of \"{$value}\" was accepted as a user.");
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatNameNobody(): iterable
    {
        yield 'spaces' => ['   '];
        yield 'a newline' => ["\n"];
        yield 'a tab' => ["\t"];
    }

    /**
     * An empty value is what an unset-looking `FOO=` in a unit file leaves behind, and it is
     * refused as a value rather than as an absence.
     *
     * Kept apart from the cases above because a message is asked there not to repeat what it
     * refused, and every message contains the empty string.
     */
    #[Test]
    public function treatsAnEmptyValueAsAValueRatherThanAnAbsence(): void
    {
        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE . '=');

        try {
            new SlackIdentityProvider()->get();
        } catch (SlackException $refusal) {
            self::assertStringStartsWith(
                SlackIdentityProvider::ENVIRONMENT_VARIABLE . ' does not name a user.',
                $refusal->getMessage(),
            );

            return;
        }

        self::fail('An empty value was accepted as a user.');
    }

    /**
     * The reason this is a provider at all: a process that was started without the variable stops
     * while it is starting, where the mistake is still somebody's to fix.
     *
     * Resolved through the real module rather than by calling the provider, because the refusal has
     * to reach the outside of the injector to end the start-up — one Ray.Di wrapped it in would be
     * an injector error somewhere in a boot log.
     */
    #[Test]
    public function stopsAStartUpThatHasNoBotUserId(): void
    {
        putenv(SlackIdentityProvider::ENVIRONMENT_VARIABLE);
        $injector = new Injector(new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackModule()));

        $this->expectException(SlackException::class);
        $this->expectExceptionMessageMatches('/' . SlackIdentityProvider::ENVIRONMENT_VARIABLE . '/');

        $injector->getInstance(SlackIdentity::class);
    }
}

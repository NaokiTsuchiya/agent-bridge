<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;
use function str_contains;

/**
 * What makes a value an app-level token, where one is read from, and what a refusal may say.
 *
 * @mago-expect lint:too-many-methods
 *
 * @internal
 */
final class SlackAppTokenTest extends TestCase
{
    /** Shaped like a real app-level token, but made up; nothing here reaches Slack. */
    private const string VALID = 'xapp-1-A01234567-0123456789012-abcdef';

    /** Whatever the developer running the suite has exported, put back afterwards. */
    private string|false $original = false;

    /** Read before anything is changed, so that the developer's own export survives the suite. */
    #[Override]
    protected function setUp(): void
    {
        $this->original = getenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);
    }

    /** The variable is process-wide, so a case that changes it has to undo that. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->original === false) {
            putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);

            return;
        }

        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . "={$this->original}");
    }

    /**
     * A token that could be one keeps its value, which is all a caller ever needs from it.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function keepsTheValueItWasGiven(): void
    {
        self::assertSame(self::VALID, new SlackAppToken(self::VALID)->value);
    }

    /**
     * The one place this app reads a token from.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function readsTheTokenFromTheEnvironment(): void
    {
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . '=' . self::VALID);

        self::assertSame(self::VALID, SlackAppTokenFactory::fromEnvironment()->value);
    }

    /**
     * An unset variable is the first thing a new deployment gets wrong, so it says which one it is.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function failsWithTheVariableNameWhenItIsNotSet(): void
    {
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);

        $this->expectException(SocketModeException::class);
        $this->expectExceptionMessageMatches('/SLACK_APP_TOKEN/');

        SlackAppTokenFactory::fromEnvironment();
    }

    /**
     * A variable holding something that is not a token names the variable too: from a caller's
     * side, what is wrong is the environment, not the call.
     *
     * @throws SocketModeException
     */
    #[DataProvider('blankValues')]
    #[DataProvider('valuesThatAreNotAppTokens')]
    #[Test]
    public function failsWithTheVariableNameWhenItHoldsSomethingElse(string $value): void
    {
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . "={$value}");

        $this->expectException(SocketModeException::class);
        $this->expectExceptionMessageMatches('/SLACK_APP_TOKEN/');

        SlackAppTokenFactory::fromEnvironment();
    }

    /**
     * A blank value is not a token, whatever amount of whitespace it is made of.
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('blankValues')]
    #[Test]
    public function refusesABlankValue(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlackAppToken($value);
    }

    /** @return iterable<string, array{string}> */
    public static function blankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a newline' => ["\n"];
    }

    /**
     * Only an app-level token opens a Socket Mode connection; the rest fail before any call is made.
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('valuesThatAreNotAppTokens')]
    #[Test]
    public function refusesAnythingThatIsNotAnAppLevelToken(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/xapp-/');

        new SlackAppToken($value);
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatAreNotAppTokens(): iterable
    {
        yield 'a bot token' => ['xoxb-1-A01234567-0123456789012-abcdef'];
        yield 'the prefix without its dash' => ['xapp'];
        yield 'the prefix in capitals' => ['XAPP-1-A01234567'];
        yield 'a leading space' => [' xapp-1-A01234567'];
        yield 'the prefix in the middle' => ['bearer xapp-1-A01234567'];
    }

    /** A refusal is logged and read by people; the value it refused must not travel with it. */
    #[Test]
    public function keepsTheRefusedValueOutOfTheMessage(): void
    {
        $rejected = 'xoxb-0000-a-value-that-must-not-be-repeated';
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . "={$rejected}");

        try {
            SlackAppTokenFactory::fromEnvironment();
            self::fail('The value is not an app-level token and must be refused.');
        } catch (SocketModeException $exception) {
            self::assertFalse(
                str_contains($exception->getMessage(), $rejected),
                "The message repeated the value it refused: {$exception->getMessage()}",
            );
        }
    }
}

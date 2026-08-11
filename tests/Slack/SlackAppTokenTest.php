<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;
use function str_contains;

/**
 * Where the token comes from, what is refused, and what the refusal is allowed to say.
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
        $this->original = getenv(SlackAppToken::ENVIRONMENT_VARIABLE);
    }

    /** The variable is process-wide, so a case that changes it has to undo that. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->original === false) {
            putenv(SlackAppToken::ENVIRONMENT_VARIABLE);

            return;
        }

        putenv(SlackAppToken::ENVIRONMENT_VARIABLE . "={$this->original}");
    }

    /**
     * The one thing the token is for: the header the API call carries.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function readsTheTokenFromTheEnvironment(): void
    {
        putenv(SlackAppToken::ENVIRONMENT_VARIABLE . '=' . self::VALID);

        self::assertSame('Bearer ' . self::VALID, SlackAppToken::fromEnvironment()->authorizationHeader());
    }

    /**
     * An unset variable is the first thing a new deployment gets wrong, so it says which one it is.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function failsWithTheVariableNameWhenItIsNotSet(): void
    {
        putenv(SlackAppToken::ENVIRONMENT_VARIABLE);

        $this->expectException(SocketModeException::class);
        $this->expectExceptionMessageMatches('/SLACK_APP_TOKEN/');

        SlackAppToken::fromEnvironment();
    }

    /**
     * An exported but empty variable is the same mistake, and must not be taken for a token.
     *
     * @throws SocketModeException
     */
    #[DataProvider('blankValues')]
    #[Test]
    public function refusesABlankValue(string $value): void
    {
        putenv(SlackAppToken::ENVIRONMENT_VARIABLE . "={$value}");

        $this->expectException(SocketModeException::class);
        $this->expectExceptionMessageMatches('/SLACK_APP_TOKEN/');

        SlackAppToken::fromEnvironment();
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
     * @throws SocketModeException
     */
    #[DataProvider('valuesThatAreNotAppTokens')]
    #[Test]
    public function refusesAnythingThatIsNotAnAppLevelToken(string $value): void
    {
        $this->expectException(SocketModeException::class);
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

        try {
            new SlackAppToken($rejected);
            self::fail('The value is not an app-level token and must be refused.');
        } catch (SocketModeException $exception) {
            self::assertFalse(
                str_contains($exception->getMessage(), $rejected),
                "The message repeated the value it refused: {$exception->getMessage()}",
            );
        }
    }
}

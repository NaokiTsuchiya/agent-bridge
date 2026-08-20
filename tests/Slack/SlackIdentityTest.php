<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What can name the user this app is, and what a value that cannot is answered with.
 *
 * The one thing this type decides is whether a value could name somebody, and it is worth deciding
 * because the value is what every incoming post is compared against: an identity of whitespace would
 * match nobody, and the first reply would be answered as if a person had written it.
 *
 * @internal
 */
final class SlackIdentityTest extends TestCase
{
    /** Shaped like a workspace's bot user id, but made up. */
    private const string ID = 'U0BOT';

    /**
     * An identity that could name somebody keeps its value, which is all a caller needs of it.
     *
     * @throws InvalidArgumentException never; the value below names somebody
     */
    #[Test]
    public function keepsTheValueItWasGiven(): void
    {
        self::assertSame(self::ID, new SlackIdentity(self::ID)->botUserId);
    }

    /**
     * Padding is judged but not removed: the id the deployment exported is the id that is compared.
     *
     * Trimming it here would repair the environment silently, and a repaired id that the workspace
     * does not know would then match nobody — which is the very thing the refusal below prevents,
     * arrived at the long way round.
     *
     * @throws InvalidArgumentException never; padding alone does not stop a value naming somebody
     */
    #[Test]
    public function keepsThePaddingOfAValueThatStillNamesSomebody(): void
    {
        $padded = ' ' . self::ID . ' ';

        self::assertSame($padded, new SlackIdentity($padded)->botUserId);
    }

    /**
     * A blank value names nobody, whatever kind of whitespace it is made of.
     *
     * @param string $value what a deployment might have exported
     *
     * @throws InvalidArgumentException always; that is what the case is
     */
    #[DataProvider('valuesThatNameNobody')]
    #[Test]
    public function refusesAValueThatNamesNobody(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlackIdentity($value);
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatNameNobody(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a newline' => ["\n"];
        yield 'a tab' => ["\t"];
    }
}

<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Cli\CliException;
use NaokiTsuchiya\AgentBridge\Cli\StandardOutputEgress;
use NaokiTsuchiya\AgentBridge\Cli\StandardStreamsProvider;
use NaokiTsuchiya\AgentBridge\Tests\Support\RefusingStream;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;

/**
 * What a terminal front end is opened onto, and what is said when it cannot be.
 *
 * Both streams go through one step, so which of the two was refused is the only thing that shows
 * the step is taken for both of them: a provider that looked at the first alone would hand back an
 * egress whose status stream nobody could write to, and that would first be noticed by a status
 * that never appeared. The refusals are reached by taking the `php://` scheme over
 * ({@see RefusingStream}), because a process' own streams open in every other circumstance.
 *
 * @internal
 */
final class StandardStreamsProviderTest extends TestCase
{
    /** Where the answer goes; a private constant of the provider, so named again here. */
    private const string REPLY = 'php://output';

    /** Where what is going on goes; likewise. */
    private const string STATUS = 'php://stderr';

    /** A case that took the scheme over hands it back even where it failed halfway. */
    #[Override]
    protected function tearDown(): void
    {
        RefusingStream::restore();
    }

    /** Nothing can be answered without the stream the answer goes on, and the refusal says so. */
    #[Test]
    public function refusesWhenTheAnswerHasNowhereToGo(): void
    {
        self::assertStringContainsString(self::REPLY, self::refusalWhenRefusing(self::REPLY)->getMessage());
    }

    /** The second stream is opened by the same step, and is missed just as loudly. */
    #[Test]
    public function refusesWhenTheStatusHasNowhereToGo(): void
    {
        self::assertStringContainsString(self::STATUS, self::refusalWhenRefusing(self::STATUS)->getMessage());
    }

    /**
     * And with both streams open the front end is built, which is what makes the refusals above a
     * judgement rather than the only thing the provider does.
     *
     * @throws CliException
     */
    #[Test]
    public function buildsTheFrontEndWhenBothStreamsOpen(): void
    {
        RefusingStream::install([]);

        try {
            $egress = new StandardStreamsProvider()->get();
        } finally {
            RefusingStream::restore();
        }

        self::assertInstanceOf(StandardOutputEgress::class, $egress);
    }

    /**
     * @param string $name the one stream the wrapper will not open
     *
     * @return CliException what the provider refused with
     */
    private static function refusalWhenRefusing(string $name): CliException
    {
        RefusingStream::install([$name]);
        // `fopen()` warns when a wrapper refuses it and the provider does not suppress that; the
        // warning would fail the run on PHPUnit's own handler, and it is not what is observed here.
        set_error_handler(static fn(): bool => true);

        try {
            new StandardStreamsProvider()->get();
        } catch (CliException $refusal) {
            return $refusal;
        } finally {
            restore_error_handler();
            RefusingStream::restore();
        }

        self::fail("\"{$name}\" could not be opened and a front end was built anyway.");
    }
}

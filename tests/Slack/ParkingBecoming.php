<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use Override;
use PHPUnit\Framework\Assert;
use Swoole\Coroutine;
use Throwable;

/**
 * A chain that parks in the middle of answering, and notes both ends of every turn.
 *
 * Parking is what makes "one message does not hold up the next" observable: a caller that answers
 * them one after another cannot start the second before the first has come out, so the order of
 * what is noted here is the answer. A real chain parks too — it waits on a child process — but only
 * by way of one, which no case can afford.
 *
 * @internal
 */
final class ParkingBecoming implements BecomingInterface
{
    /** Long enough to hand the scheduler the other coroutines, short enough not to be a wait. */
    private const float PARK = 0.001;

    /** @var list<string> `<text> in` when a turn began and `<text> out` when it finished, in order */
    public array $record = [];

    /** @var list<IncomingMessage> every message handed over, as the front end made it */
    public array $seen = [];

    /** @param array<string, Throwable> $failures thrown instead of finishing, by the message's text */
    public function __construct(
        private array $failures = [],
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Throwable what the case put against that message's text.
     */
    #[Override]
    public function __invoke(object $input): object
    {
        Assert::assertInstanceOf(IncomingMessage::class, $input);
        $this->seen[] = $input;
        $text = $input->text;
        $this->record[] = "{$text} in";

        Coroutine::sleep(self::PARK);

        $failure = $this->failures[$text] ?? null;
        if ($failure !== null) {
            throw $failure;
        }

        $this->record[] = "{$text} out";

        return $input;
    }
}

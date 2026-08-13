<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use Override;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_shift;

/**
 * A chain that answers with what a case put in front of it.
 *
 * Every other test of the front end drives the real chain; this one exists for the cases that are
 * about what a conversation does with the answers — a turn that failed, a chain that threw — which
 * a real chain only produces by way of a whole failing agent.
 */
final class ScriptedBecoming implements BecomingInterface
{
    /** @var list<IncomingMessage> every message handed over, in order, as the front end made it */
    public array $seen = [];

    /** @param list<object|Throwable> $answers one per message, thrown when it is a Throwable */
    public function __construct(
        private array $answers,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Throwable whatever the case put in this position.
     */
    #[Override]
    public function __invoke(object $input): object
    {
        Assert::assertInstanceOf(IncomingMessage::class, $input);
        $this->seen[] = $input;

        $answer = array_shift($this->answers);
        Assert::assertNotNull($answer, 'The chain was asked more often than the case scripted it.');

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}

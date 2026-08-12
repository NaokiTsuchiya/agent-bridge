<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Cli\Conversation;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Tests\Chat\RecordingChatEgress;
use NaokiTsuchiya\AgentBridge\Tests\Pipeline\StubAgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use UnhandledMatchError;

/**
 * What a conversation does with the answers it gets, apart from any front end or agent.
 *
 * Two of these outcomes a real chain only produces by way of a whole failing agent, and one of
 * them — letting the thread's child go — is invisible from the outside until a process refuses to
 * end. So the chain and the execution layer are stand-ins here, and every other test of the front
 * end drives the real ones.
 *
 * @mago-expect lint:too-many-methods
 */
final class ConversationTest extends TestCase
{
    /** The thread the scripted turns belong to. */
    private const string THREAD = 'cli:my-experiment';

    /**
     * Every message got a turn, and every turn went well.
     *
     * @throws Throwable
     */
    #[Test]
    public function answersEveryMessageThereIs(): void
    {
        $runner = self::runner();

        $result = new Conversation(
            new ScriptedBecoming([
                self::finishedTurn(),
                self::finishedTurn(),
            ]),
            $runner,
        )->answer(self::ingress(2));

        self::assertTrue($result->answered);
        self::assertNull($result->failure);
    }

    /**
     * A turn that finished badly is an answer the reader has seen, not a failure.
     *
     * @throws Throwable
     */
    #[Test]
    public function reportsATurnThatDidNotGoWell(): void
    {
        $runner = self::runner();

        $result = new Conversation(
            new ScriptedBecoming([
                self::unfinishedTurn(),
                self::finishedTurn(),
            ]),
            $runner,
        )->answer(self::ingress(2));

        self::assertFalse($result->answered, 'One bad turn is enough, whichever of them it was.');
        self::assertNull($result->failure);
    }

    /**
     * What stopped the conversation comes back rather than being thrown, because a caller inside a
     * coroutine would never see a throw.
     */
    #[Test]
    public function carriesBackWhatStoppedIt(): void
    {
        $refusal = new InvalidArgumentException('not a thread');
        $runner = self::runner();

        $result = new Conversation(new ScriptedBecoming([$refusal]), $runner)->answer(self::ingress(1));

        self::assertFalse($result->answered);
        self::assertSame($refusal, $result->failure);
    }

    /**
     * The thread's child is let go of once the conversation is over.
     *
     * Without this the pool keeps watching it on a coroutine of its own, and a process that waits
     * for its coroutines never ends.
     *
     * @throws Throwable
     */
    #[Test]
    public function letsTheThreadGoAtTheEnd(): void
    {
        $runner = self::runner();

        new Conversation(new ScriptedBecoming([self::finishedTurn()]), $runner)->answer(self::ingress(1));

        self::assertSame([self::THREAD], $runner->closed);
    }

    /**
     * Even when something went wrong: a child left behind would outlive the conversation.
     *
     * @throws Throwable
     */
    #[Test]
    public function letsTheThreadGoAfterAFailureToo(): void
    {
        $runner = self::runner();

        new Conversation(new ScriptedBecoming([
            self::finishedTurn(),
            new RuntimeException('the agent died'),
        ]), $runner)->answer(self::ingress(2));

        self::assertSame([self::THREAD], $runner->closed);
    }

    /** Nothing to answer is not a failure, and there is no child to give up either. */
    #[Test]
    public function answersNothingAtAll(): void
    {
        $runner = self::runner();

        $result = new Conversation(new ScriptedBecoming([]), $runner)->answer(self::ingress(0));

        self::assertTrue($result->answered);
        self::assertNull($result->failure);
        self::assertSame([], $runner->closed);
    }

    /** @return StubAgentRunner an execution layer that answers one finished turn and remembers */
    private static function runner(): StubAgentRunner
    {
        return new StubAgentRunner([new TurnCompleted(success: true, sessionId: 'session')]);
    }

    /**
     * A turn of the thread these cases use, which reached its completion event.
     *
     * Built rather than mocked: what a conversation reads off it — the thread it belongs to and
     * whether it went well — is what the real stage carries.
     *
     * @throws InvalidArgumentException
     * @throws UnhandledMatchError
     */
    private static function finishedTurn(): CompletedTurn
    {
        return self::turn([new TurnCompleted(success: true, sessionId: 'session')]);
    }

    /**
     * A turn whose agent stopped before saying it was done, which is what `success` is false for.
     *
     * @throws InvalidArgumentException
     * @throws UnhandledMatchError
     */
    private static function unfinishedTurn(): CompletedTurn
    {
        return self::turn([]);
    }

    /**
     * @param list<AgentEvent> $events what the turn's agent says
     *
     * @throws InvalidArgumentException
     * @throws UnhandledMatchError
     */
    private static function turn(array $events): CompletedTurn
    {
        return new CompletedTurn(
            new ThreadWorkspace(new ThreadId(self::THREAD), 'session', '/tmp'),
            'hello',
            new StubAgentRunner($events),
            new RecordingChatEgress(),
        );
    }

    /** @param int $messages how many the front end has */
    private static function ingress(int $messages): ChatIngress
    {
        $said = [];
        for ($i = 0; $i < $messages; $i++) {
            $said[] = new IncomingMessage('cli', 'my-experiment', "message {$i}");
        }

        return new FixedIngress($said);
    }
}

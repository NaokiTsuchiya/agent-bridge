<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use NaokiTsuchiya\AgentBridge\Tests\Runner\FixedWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function realpath;
use function str_contains;
use function strtolower;
use function uniqid;

/**
 * One round trip against the real binary, which is all this group carries for the second runner.
 *
 * The fake settles everything about how this implementation behaves; what a stand-in cannot show is
 * the one-shot invocation itself — that a real `claude` accepts a prompt on its command line, ends
 * on its own, and answers a thread nobody has used before.
 *
 * No exact wording is asserted: the real CLI answers differently every time. The keyword is asked
 * for in the prompt, which is also why the assertion holds when this group is pointed at the fake
 * (it echoes the prompt back).
 */
#[Group('integration')]
final class SpawnCliRunnerSmokeTest extends TestCase
{
    /** The directory the agent is started in, thrown away afterwards. */
    private string $cwd = '';

    /** A directory of this case's own for the agent to run in. */
    #[Override]
    protected function setUp(): void
    {
        $cwd = realpath(TempDir::make('spawn-smoke-cwd'));
        self::assertIsString($cwd);
        $this->cwd = $cwd;
    }

    /** Nothing under the temporary directory outlives the case. */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->cwd);
    }

    /**
     * A thread nobody has used before gets an answer and a finished turn.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function answersOneTurnOnAFreshThread(): void
    {
        $runner = new SpawnCliRunner(
            new FixedWorkingDirectory($this->cwd),
            new ClaudeCliSettings(binary: ClaudeBinary::fromEnvironment()),
        );
        $thread = new ThreadId('smoke:' . uniqid());

        Coro::run(static function () use ($runner, $thread): void {
            $text = '';
            $completed = 0;
            foreach ($runner->send($thread, 'Reply with exactly one word: pineapple') as $event) {
                if ($event instanceof TextDelta) {
                    $text .= $event->text;
                    continue;
                }

                if (!$event instanceof TurnCompleted) {
                    continue;
                }

                $completed++;
                self::assertTrue($event->success, 'The turn must finish without an error.');
            }

            $runner->close($thread);

            self::assertSame(1, $completed, 'Exactly one turn boundary.');
            self::assertTrue(str_contains(strtolower($text), 'pineapple'), "The reply was: {$text}");
        });
    }
}

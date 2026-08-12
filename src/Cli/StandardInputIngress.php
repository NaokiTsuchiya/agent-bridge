<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;

use function explode;
use function fgets;
use function trim;

/**
 * The thread named on the command line, and one message per line of input.
 *
 * A terminal has no threads of its own, so the caller names one — which is also what makes a
 * command line session and a chat thread land in the same worktree and the same Claude Code
 * session. Everything else is the reader typing: a line is a message, and end of input ends the
 * conversation.
 *
 * Nothing here judges the thread id. The parts are handed over as they were given and
 * {@see \NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory} decides whether they name a thread,
 * so that a front end cannot accept an id the rest of the application would refuse.
 *
 * @api
 */
final class StandardInputIngress implements ChatIngress
{
    /** What separates the two parts of a thread id; {@see ThreadId} splits at the same one. */
    private const string SEPARATOR = ':';

    /**
     * @param string   $thread the thread id as it was given, e.g. `cli:my-experiment`
     * @param resource $input  the messages to answer, one per line
     */
    public function __construct(
        private string $thread,
        private mixed $input,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return iterable<IncomingMessage>
     */
    #[Override]
    public function listen(): iterable
    {
        $parts = explode(self::SEPARATOR, $this->thread, limit: 2);
        $platform = $parts[0];
        // Unreachable when the id carries a separator; explode is typed as a non-empty list
        // without the analyzer knowing about the limit, which is what the fallback is for.
        $nativeId = $parts[1] ?? '';

        while (true) {
            $line = fgets($this->input);
            if ($line === false) {
                return;
            }

            $text = trim($line);
            // A blank line is somebody pressing return, not an empty question to answer.
            if ($text === '') {
                continue;
            }

            yield new IncomingMessage($platform, $nativeId, $text);
        }
    }
}

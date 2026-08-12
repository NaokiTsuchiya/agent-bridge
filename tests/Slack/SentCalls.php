<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\StreamChunks;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

use function count;

/**
 * What reached the workspace, read back as the two things a case asks about.
 *
 * Both are a walk over every call with a type check on the way, which is noise in a case about
 * ordering or about a limit — and the same noise twice, since the front end is driven both directly
 * and through the pipeline. The arguments of a call are typed as loosely as Slack's own are, so
 * they are read with {@see Json}, the same way this suite reads anything it did not build.
 *
 * @internal
 */
final class SentCalls
{
    /** @return list<string> the text of every call that carried some, in order */
    public static function texts(FakeSlackApiClient $api): array
    {
        $texts = [];
        foreach ($api->calls as $call) {
            $text = Json::text($call['arguments'], 'markdown_text');

            if ($text === null) {
                continue;
            }

            $texts[] = $text;
        }

        return $texts;
    }

    /** @return list<string> what every task update announced, in order */
    public static function taskTitles(FakeSlackApiClient $api): array
    {
        $titles = [];
        foreach ($api->calls as $call) {
            $chunks = Json::node($call['arguments'], 'chunks');

            // By index rather than by value: the chunks arrive untyped, and every read of one goes
            // through the same typed accessors as the call itself.
            for ($index = 0; $index < count($chunks); $index++) {
                $title = self::titleIn(Json::node($chunks, $index));

                if ($title === null) {
                    continue;
                }

                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param array<array-key, mixed> $chunk one chunk, as it was sent
     *
     * @return string|null what it announces, or null when it is not a task update
     */
    private static function titleIn(array $chunk): ?string
    {
        if (Json::text($chunk, 'type') !== StreamChunks::TASK_UPDATE) {
            return null;
        }

        return Json::text($chunk, 'title');
    }
}

<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;

use function count;

/** Reading what a turn produced, for tests that assert about the whole of it rather than a step. */
final class Events
{
    /**
     * @param iterable<AgentEvent> $events
     *
     * @return list<AgentEvent> every event of the turn, in order
     */
    public static function collect(iterable $events): array
    {
        $collected = [];
        foreach ($events as $event) {
            $collected[] = $event;
        }

        return $collected;
    }

    /**
     * @param list<AgentEvent>         $events
     * @param class-string<AgentEvent> $class
     */
    public static function tally(array $events, string $class): int
    {
        $found = 0;
        foreach ($events as $event) {
            if (!$event instanceof $class) {
                continue;
            }

            $found++;
        }

        return $found;
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return string every fragment of the reply, joined
     */
    public static function text(array $events): string
    {
        $text = '';
        foreach ($events as $event) {
            if (!$event instanceof TextDelta) {
                continue;
            }

            $text .= $event->text;
        }

        return $text;
    }

    /**
     * @param list<AgentEvent> $events
     *
     * @return AgentEvent|null the last one, or null when nothing arrived at all
     */
    public static function last(array $events): ?AgentEvent
    {
        return $events[count($events) - 1] ?? null;
    }
}

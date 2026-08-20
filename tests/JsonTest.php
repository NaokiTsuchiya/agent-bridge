<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * What a value that arrived as JSON is allowed to be read as.
 *
 * Every reader of an untrusted payload — a Socket Mode frame, a Web API body, a line of an agent's
 * output — goes through these three, so a read that is more generous than it looks lets a
 * wrongly-shaped payload through all of them at once. Each case below is a shape that has to keep
 * answering the same way: the absence of a key and a key holding null are the same answer, and a
 * value that merely resembles the wanted type is not it.
 *
 * @internal
 */
final class JsonTest extends TestCase
{
    /**
     * Only an array is an object; everything else `json_decode` can answer with is nothing.
     *
     * A JSON array decodes to an array too and is passed on as one: the callers ask whether they
     * can index the value, not whether Slack wrote braces. An empty object is an answer as much as
     * a filled one, which a truthiness check would lose.
     *
     * @param array<array-key, mixed>|null $expected
     */
    #[DataProvider('decodedValues')]
    #[Test]
    public function readsADecodedValueAsAnObject(string $json, ?array $expected): void
    {
        self::assertSame($expected, Json::asObject(json_decode($json, associative: true)));
    }

    /** @return iterable<string, array{string, array<array-key, mixed>|null}> */
    public static function decodedValues(): iterable
    {
        yield 'an object' => ['{"ok":true}', ['ok' => true]];
        yield 'an array' => ['[1,2]', [1, 2]];
        yield 'an empty object' => ['{}', []];
        yield 'a string' => ['"text"', null];
        yield 'an integer' => ['1', null];
        yield 'a float' => ['1.5', null];
        yield 'a boolean' => ['true', null];
        yield 'null' => ['null', null];
        // What a body truncated by a proxy, or a line cut short by a pipe, decodes to.
        yield 'json that does not parse' => ['{', null];
    }

    /**
     * A string under the key, and null for every other way the key can turn out.
     *
     * The empty string is a string: the callers that treat it as nothing do so themselves, one key
     * at a time, and a read that folded it into null would take that choice away from them. A
     * number is not a string either, however much it looks like one once printed.
     *
     * @param array<array-key, mixed> $node
     */
    #[DataProvider('nodesReadAsText')]
    #[Test]
    public function readsAStringUnderTheKey(array $node, ?string $expected): void
    {
        self::assertSame($expected, Json::text($node, 'key'));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string|null}> */
    public static function nodesReadAsText(): iterable
    {
        yield 'a string' => [['key' => 'value'], 'value'];
        yield 'an empty string' => [['key' => ''], ''];
        yield 'nothing at all' => [[], null];
        yield 'null' => [['key' => null], null];
        yield 'an integer' => [['key' => 1], null];
        yield 'a float' => [['key' => 1.5], null];
        yield 'a boolean' => [['key' => true], null];
        yield 'an array' => [['key' => ['value']], null];
        yield 'only another key' => [['other' => 'value'], null];
    }

    /**
     * An array under the key, and null for every other way the key can turn out.
     *
     * An empty array is an answer: a payload that carries an object with nothing in it is not a
     * payload that carries no object.
     *
     * @param array<array-key, mixed>      $node
     * @param array<array-key, mixed>|null $expected
     */
    #[DataProvider('nodesReadAsObject')]
    #[Test]
    public function readsAnArrayUnderTheKey(array $node, ?array $expected): void
    {
        self::assertSame($expected, Json::object($node, 'key'));
    }

    /** @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>|null}> */
    public static function nodesReadAsObject(): iterable
    {
        yield 'an object' => [['key' => ['ok' => true]], ['ok' => true]];
        yield 'an empty object' => [['key' => []], []];
        yield 'an array' => [['key' => [1, 2]], [1, 2]];
        yield 'nothing at all' => [[], null];
        yield 'null' => [['key' => null], null];
        yield 'a string' => [['key' => 'value'], null];
        yield 'an integer' => [['key' => 1], null];
        yield 'a boolean' => [['key' => true], null];
        yield 'only another key' => [['other' => ['ok' => true]], null];
    }
}

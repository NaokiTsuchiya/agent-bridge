<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Thread;

use function bin2hex;
use function chr;
use function hex2bin;
use function ord;
use function sha1;
use function strtr;
use function substr;

final class ThreadDerivation
{
    /** Changing this cuts every existing thread off from its Claude Code session. */
    public const string NAMESPACE_UUID = '33adc75c-ded9-51f3-b48f-fe0eebd1fcbf';

    /** The Claude Code session a thread resumes into. */
    public static function sessionId(ThreadId $thread): string
    {
        return self::uuidV5($thread->value);
    }

    /** The thread's identifier reduced to characters that are safe in a path and a ref. */
    public static function slug(ThreadId $thread): string
    {
        return strtr($thread->value, [':' => '-', '.' => '-']);
    }

    /** Where the thread's worktree lives, relative to the base repository. */
    public static function worktreePath(ThreadId $thread): string
    {
        return '.worktrees/' . self::slug($thread);
    }

    /** The branch the thread's worktree checks out. */
    public static function branchName(ThreadId $thread): string
    {
        return 'agent/' . self::slug($thread);
    }

    /** RFC 9562 version 5 (SHA-1, name based) UUID under {@see self::NAMESPACE_UUID}. */
    private static function uuidV5(string $name): string
    {
        // hex2bin only refuses an odd length or a non-hex character. self::NAMESPACE_UUID is a
        // fixed 32-digit hex literal (dashes stripped) pinned byte-for-byte by
        // ThreadDerivationTest::namespaceUuidIsTheAgreedConstant, so this never actually returns
        // false; the cast keeps the type checker honest about the signature without making every
        // caller declare an exception that can never be thrown.
        $namespaceBytes = (string) hex2bin(strtr(self::NAMESPACE_UUID, ['-' => '']));

        $hash = sha1($namespaceBytes . $name, binary: true);

        // RFC 9562 section 5.5: the version nibble and the two variant bits are
        // overwritten in place, every other bit stays as hashed.
        $bytes =
            substr($hash, offset: 0, length: 6)
            . chr((ord($hash[6]) & 0x0F) | 0x50)
            . $hash[7]
            . chr((ord($hash[8]) & 0x3F) | 0x80)
            . substr($hash, offset: 9, length: 7);

        $hex = bin2hex($bytes);

        return (
            substr($hex, offset: 0, length: 8)
            . '-'
            . substr($hex, offset: 8, length: 4)
            . '-'
            . substr($hex, offset: 12, length: 4)
            . '-'
            . substr($hex, offset: 16, length: 4)
            . '-'
            . substr($hex, offset: 20, length: 12)
        );
    }
}

<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * The name of a chat platform, as it appears in a thread id.
 *
 * Registration only: what a platform name may contain is decided by {@see ThreadId} and by
 * {@see \NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory}, so that one message can be rejected for
 * one reason in one place. Be looks this class up by the constructor parameter name and writes a
 * notice when it is missing, which the test suite turns into a failure — so a new `#[Input]`
 * parameter name needs a class here next to it.
 *
 * @api
 */
final class Platform {}

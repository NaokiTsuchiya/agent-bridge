<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;

/**
 * The Claude Code session a thread resumes into.
 *
 * Registration only; the value is derived by {@see ThreadDerivation::sessionId()} and never
 * supplied from outside. See {@see Platform} for why the class has to exist at all.
 *
 * @api
 */
final class SessionId {}

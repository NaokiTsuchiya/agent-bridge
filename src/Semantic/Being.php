<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Pipeline\AnsweringTurn;

/**
 * Which of the ways a turn can end it ended in.
 *
 * Registration only: the reason is built by {@see AnsweringTurn} and its type is what picks the
 * final object, so there is nothing left here to check. See {@see Platform} for why the class has
 * to exist at all.
 *
 * @api
 */
final class Being {}

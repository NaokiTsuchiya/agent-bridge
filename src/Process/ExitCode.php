<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Process;

/**
 * What a process of this project tells its caller by ending.
 *
 * @api
 */
enum ExitCode: int
{
    /** Something was attempted, and it went as asked. */
    case Ok = 0;

    /** Something was attempted; unlike the two codes below, this one means it was worth trying. */
    case TurnFailed = 1;

    /** Nothing was attempted: the command line is not one this program takes. */
    case BadInvocation = 2;

    /** Nothing was attempted: this process cannot be brought up. */
    case CannotStart = 3;
}

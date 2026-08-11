<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use RuntimeException;

/**
 * The command line front end cannot do its work.
 *
 * @api
 */
final class CliException extends RuntimeException {}

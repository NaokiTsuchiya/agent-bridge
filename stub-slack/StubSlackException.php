<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use RuntimeException;

/**
 * Everything that can go wrong while standing the stub up: a certificate that could not be
 * generated, a port that could not be reserved, a CLI invocation missing what it needs.
 */
final class StubSlackException extends RuntimeException {}

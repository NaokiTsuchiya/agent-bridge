<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Resource\App;

use BEAR\Resource\ResourceObject;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;

/**
 * The one resource the PoC needs to show that the resource layer runs on a resident process.
 *
 * Resolving `ResourceInterface` from the injector proves nothing on its own — a compiled injector
 * hands one back whether or not a single resource class exists. This is the smallest thing that can
 * be asked for over a URI and answer.
 *
 * @api
 */
final class Health extends ResourceObject
{
    /** @param AgentRunner $runner the execution layer whose live processes are reported */
    public function __construct(
        private AgentRunner $runner,
    ) {}

    /** Answers `app://self/health`. */
    public function onGet(): self
    {
        $this->body = [
            'status' => 'ok',
            'package' => AgentBridge::PACKAGE,
            'processes' => $this->runner->liveProcesses(),
        ];

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Resource\App;

use BEAR\Resource\ResourceObject;
use NaokiTsuchiya\AgentBridge\AgentBridge;

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
    /** Answers `app://self/health`. */
    public function onGet(): self
    {
        $this->body = ['status' => 'ok', 'package' => AgentBridge::PACKAGE];

        return $this;
    }
}

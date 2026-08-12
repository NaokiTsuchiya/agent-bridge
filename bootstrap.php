<?php

declare(strict_types=1);

/**
 * What `vendor/bin/ray-di-compile` loads to find out which context to compile.
 *
 * The autoloader is not required here: the CLI has already found it by the time this file is read,
 * and a runtime caller reaches it through its own. Returning the provider — rather than a context —
 * is the CLI's contract.
 */

use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\RayDiContext\MapContextProvider;

return new MapContextProvider([
    ServeContext::NAME => ServeContext::class,
    SlackContext::NAME => SlackContext::class,
]);

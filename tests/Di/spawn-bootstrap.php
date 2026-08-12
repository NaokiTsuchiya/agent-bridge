<?php

declare(strict_types=1);

/**
 * What the compile CLI loads to write the scripts of the swapped execution layer.
 *
 * The context name is the production one on purpose: a run started with `AGENT_BRIDGE_APP_DIR`
 * pointed here asks for "serve" and gets these scripts, without anything in `src/` or `bin/` being
 * aware that there is more than one answer.
 */

use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Di\SpawnServeContext;
use NaokiTsuchiya\RayDiContext\MapContextProvider;

return new MapContextProvider([ServeContext::NAME => SpawnServeContext::class]);

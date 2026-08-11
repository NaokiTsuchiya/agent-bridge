<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge;

final class AgentBridge
{
    /** Kept in step with the `name` field of composer.json. */
    public const string PACKAGE = 'naoki-tsuchiya/agent-bridge';

    /**
     * What BEAR.Resource calls the application name: the namespace `app://self/x` is resolved under.
     *
     * Written as `__NAMESPACE__` rather than spelled out so that renaming the namespace cannot leave
     * a stale copy behind, and so the value carries no namespace string literal for the linter to
     * reject.
     */
    public const string APP_NAME = __NAMESPACE__;

    /** The namespace Be looks in for semantic variables, in place of its own `Be\App\Semantic`. */
    public const string SEMANTIC_NAMESPACE = self::APP_NAME . '\Semantic';
}

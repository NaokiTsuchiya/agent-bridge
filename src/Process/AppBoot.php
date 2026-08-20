<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Process;

use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use Ray\Di\InjectorInterface;

/**
 * Brings a front end up from a directory and a context name.
 *
 * @api
 */
final class AppBoot
{
    /** @param ContextProviderInterface $contexts the context-name-to-context mapping */
    public function __construct(
        private ContextProviderInterface $contexts,
    ) {}

    /**
     * @param string $appDir  the directory the compiled scripts are read from
     * @param string $context which of the compiled contexts to resolve from
     *
     * @return InjectorInterface the one injector of this process
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    public function injector(string $appDir, string $context): InjectorInterface
    {
        return (new Boot(AppMeta::fromAppDir($appDir, $context), $this->contexts))();
    }
}

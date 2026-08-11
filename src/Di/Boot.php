<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use Ray\Di\InjectorInterface;

use function is_dir;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Brings a process up to the point where it can serve, once.
 *
 * The one thing this exists to guarantee is that `getInjectorInstance()` is called a single time
 * per process. It is not a factory that happens to return an injector: the contract says repeated
 * calls need not give the same instance — and the compiled context does return a new one each time
 * — so warming up against one and then serving from another would leave the warmup behind.
 *
 * @api
 */
final class Boot
{
    /**
     * @param AppMeta                  $meta     must resolve to the same directories the compile
     *                                           command was given, or the scripts are looked for
     *                                           where nothing was written
     * @param ContextProviderInterface $contexts the context-name-to-context mapping
     */
    public function __construct(
        private AppMeta $meta,
        private ContextProviderInterface $contexts,
    ) {}

    /**
     * @return InjectorInterface the one injector of this process, to be kept and reused
     *
     * @throws BootException When the tmp dir cannot be created.
     * @throws ExceptionInterface When the context is unknown or its compile dir is unusable.
     */
    public function __invoke(): InjectorInterface
    {
        $this->createTmpDir();

        $context = $this->contexts->get($this->meta);
        $injector = $context->getInjectorInstance();

        foreach ($context->getSavedSingleton() as $class) {
            $injector->getInstance($class);
        }

        return $injector;
    }

    /**
     * Nothing else creates the tmp dir, and Ray.Di does not complain when it is missing.
     *
     * It falls back to the system temporary directory instead — a directory this process shares
     * with everything else on the machine, chosen silently. Failing here is the point.
     *
     * @throws BootException
     */
    private function createTmpDir(): void
    {
        $tmpDir = $this->meta->tmpDir;

        $exists = is_dir($tmpDir);
        if ($exists) {
            return;
        }

        set_error_handler(static fn(): bool => true);
        try {
            mkdir($tmpDir, permissions: 0o755, recursive: true);
        } finally {
            restore_error_handler();
        }

        // The result of mkdir() is deliberately not what is checked: two processes starting together
        // race here, and the loser's false is not a failure. What matters is whether the directory
        // is there afterwards.
        $created = is_dir($tmpDir);
        if (!$created) {
            throw new BootException(sprintf('Could not create the tmp dir: "%s"', $tmpDir));
        }
    }
}

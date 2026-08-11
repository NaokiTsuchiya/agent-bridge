<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

use function mt_getrandmax;
use function mt_rand;

/**
 * The default randomness for the backoff jitter.
 *
 * @api
 */
final class MtRandomSource implements RandomSourceInterface
{
    /** Divided by `mt_getrandmax() + 1` so that the result never reaches 1.0. */
    #[Override]
    public function fraction(): float
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }
}

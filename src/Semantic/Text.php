<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

/**
 * What a message asks for, in the words of whoever wrote it.
 *
 * Registration only, and deliberately unconstrained: an empty message is a message. See
 * {@see Platform} for why the class has to exist at all.
 *
 * @api
 */
final class Text {}

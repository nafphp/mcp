<?php

declare(strict_types=1);

namespace Naf\MCP\Tools;

use Generator;
use Naf\MCP\Support\Progress;

interface StreamingToolInterface extends ToolInterface
{
    /** Yield progress; return the same result as handle(). Only used when streaming is enabled. @return Generator<int, Progress, mixed, mixed> */
    public function stream(array $args): Generator;
}

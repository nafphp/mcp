<?php

declare(strict_types=1);

namespace Naf\MCP\Tools;

/** A tool that must run on behalf of a linked user, never a service token. */
interface UserToolInterface extends ToolInterface
{
}

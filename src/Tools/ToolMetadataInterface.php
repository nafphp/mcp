<?php

declare(strict_types=1);

namespace Naf\MCP\Tools;

interface ToolMetadataInterface extends ToolInterface
{
    /** Optional MCP title, annotations, outputSchema and _meta fields. */
    public function metadata(): array;
}

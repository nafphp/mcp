<?php

declare(strict_types=1);

use NixPHP\MCP\Support\ToolRegistry;
use function NixPHP\app;

app()->container()->set(ToolRegistry::class, new ToolRegistry());
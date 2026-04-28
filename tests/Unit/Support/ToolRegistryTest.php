<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use NixPHP\MCP\Auth\McpIdentity;
use NixPHP\MCP\Support\ToolRegistry;
use NixPHP\MCP\Tools\ScopedToolInterface;
use NixPHP\MCP\Tools\ToolInterface;
use Tests\NixPHPTestCase;

final class ToolRegistryTest extends NixPHPTestCase
{
    public function testDefinitionsAreAListAndEmptyPropertiesStayAJsonObject(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new DummyTool('plain_tool'));

        $definitions = $registry->definitions();

        $this->assertSame(['plain_tool'], array_column($definitions, 'name'));
        $this->assertArrayHasKey(0, $definitions);
        $this->assertInstanceOf(\stdClass::class, $definitions[0]['inputSchema']['properties']);
    }

    public function testScopedToolsAreFilteredByIdentityScopes(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new DummyTool('plain_tool'));
        $registry->register(new ScopedDummyTool('article_tool', ['articles:read']));

        $limited = new McpIdentity('tok_test', 'Test', ['queue:read']);
        $allowed = new McpIdentity('tok_test', 'Test', ['articles:*']);

        $this->assertSame(['plain_tool'], array_column($registry->definitions($limited), 'name'));
        $this->assertSame(['plain_tool', 'article_tool'], array_column($registry->definitions($allowed), 'name'));
    }
}

class DummyTool implements ToolInterface
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Dummy tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function handle(array $args): array
    {
        return $args;
    }
}

final class ScopedDummyTool extends DummyTool implements ScopedToolInterface
{
    /**
     * @param array<int, string> $scopes
     */
    public function __construct(string $name, private readonly array $scopes)
    {
        parent::__construct($name);
    }

    public function requiredScopes(): array
    {
        return $this->scopes;
    }
}

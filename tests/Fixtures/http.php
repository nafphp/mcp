<?php

declare(strict_types=1);

use Naf\Core\Config;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Support\Progress;
use Naf\MCP\Support\ToolResult;
use Naf\MCP\Tools\StreamingToolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Naf\app;
use function Naf\MCP\tool;

define('BASE_PATH', getenv('NAF_MCP_HTTP_ROOT'));
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configuration                                  = require dirname(__DIR__, 2) . '/src/config.php';
$configuration['mcp']['transport']['streaming'] = ($_GET['stream'] ?? 'on') === 'on';
$configuration['mcp']['oauth']                  = [
    'resource'              => 'https://tools.example.test/mcp',
    'authorization_servers' => ['https://tools.example.test'],
    'scopes_supported'      => ['account:read'],
];
app()->container()->set(Config::class, new Config($configuration));
app()->container()->set(LoggerInterface::class, new NullLogger());
app()->container()->set(AuthenticatorInterface::class, new class implements AuthenticatorInterface {
    public function isEnabled(): bool
    {
        return true;
    }

    public function authenticate(RequestInterface $request): ?McpIdentity
    {
        return $request->getHeaderLine('Authorization') === 'Bearer test-service'
            ? new McpIdentity('service', 'Test', ['mcp:service']) : null;
    }
});
tool()->register(new class implements StreamingToolInterface {
    public function name(): string
    {
        return 'delayed';
    }

    public function description(): string
    {
        return 'Check real incremental delivery.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function handle(array $args): mixed
    {
        return ToolResult::text('complete');
    }

    public function stream(array $args): Generator
    {
        yield new Progress(0, 1, 'Starting');
        usleep(450000);
        yield new Progress(1, 1, 'Finished');

        return $this->handle($args);
    }
});
app()->run();

<?php /** @noinspection PhpClassCanBeReadonlyInspection */

declare(strict_types=1);

namespace NixPHP\MCP\Core;

use NixPHP\MCP\Auth\McpIdentity;
use NixPHP\MCP\Support\ToolRegistry;
use NixPHP\MCP\Support\ToolResult;
use Throwable;
use function NixPHP\log;

class MCPRouter
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $serverName = 'nixphp-mcp',
        private readonly string $serverVersion = '0.1.0',
        private readonly string $protocolVersion = '2025-06-18',
    ) {}

    /**
     * @param array $msg decoded JSON-RPC request
     * @return array|null JSON-RPC response or null for notifications
     */
    public function handle(array $msg, ?McpIdentity $identity = null): ?array
    {
        $id     = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;
        $params = $msg['params'] ?? [];

        // Notification: no id => no JSON-RPC response
        $isNotification = !array_key_exists('id', $msg) || $id === null;

        if (!is_string($method)) {
            return $isNotification ? null : $this->error($id, -32600, 'Invalid Request');
        }

        log()->debug('MCP: Received message');

        try {
            return match ($method) {
                'initialize' => $this->ok($id, [
                    'protocolVersion' => $this->protocolVersion,
                    'capabilities'    => [
                        // MUST be an object in JSON, not []
                        'tools' => (object)[],
                    ],
                    'serverInfo'      => [
                        'name'    => $this->serverName,
                        'version' => $this->serverVersion,
                    ],
                ]),

                // Clients often send this as a notification (no id)
                'notifications/initialized' => $isNotification ? null : $this->ok($id, (object)[]),

                'tools/list' => $this->ok($id, [
                    'tools' => $this->tools->definitions($identity),
                ]),

                'tools/call' => $this->handleToolsCall($id, $params, $identity),

                default => $isNotification ? null : $this->error($id, -32601, 'Method not found'),
            };
        } catch (Throwable $e) {
            // Protocol-level failure (not a tool error). Usually rare.
            log()->error('MCP: Error while routing message: ' . $e->getMessage());
            return $isNotification ? null : $this->error($id, -32000, $e->getMessage());
        }
    }

    /**
     * @param mixed $id
     * @param mixed $params
     *
     * @return array
     */
    private function handleToolsCall(mixed $id, mixed $params, ?McpIdentity $identity): array
    {
        if (!is_array($params)) {
            return $this->ok($id, ToolResult::error('Invalid params for tools/call'));
        }

        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];

        log()->debug('MCP: Prepare call to tool ' . $name);

        if (!is_string($name)) {
            return $this->ok($id, ToolResult::error('Missing tool name'));
        }

        if (!is_array($args)) {
            return $this->ok($id, ToolResult::error('Tool arguments must be an object'));
        }

        try {
            $result = $this->tools->call($name, $args, $identity);
            log()->debug('MCP: Called tool ' . $name);

            return $this->ok($id, ToolResult::json($result));
        } catch (Throwable $e) {
            // Tool-level error: respond as ToolResult with isError=true
            log()->error('MCP: Error while executing tool ' . $name . ': ' . $e->getMessage());

            return $this->ok($id, ToolResult::error($e->getMessage()));
        }
    }

    /**
     * @param mixed $id
     * @param mixed $result
     *
     * @return array
     */
    private function ok(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];
    }

    /**
     * @param mixed  $id
     * @param int    $code
     * @param string $message
     *
     * @return array
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}

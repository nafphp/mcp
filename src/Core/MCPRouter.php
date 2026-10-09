<?php

declare(strict_types=1);

namespace Naf\MCP\Core;

use Generator;
use InvalidArgumentException;
use Naf\MCP\Auth\AuthorizationException;
use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Support\Progress;
use Naf\MCP\Support\ToolRegistry;
use Naf\MCP\Support\ToolResult;
use Naf\MCP\Tools\StreamingToolInterface;
use stdClass;
use Throwable;

use function Naf\log;

class MCPRouter
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $serverName = 'naf-mcp',
        private readonly string $serverVersion = '0.2.5',
        private readonly string $protocolVersion = '2025-11-25',
    ) {
    }

    public function supportedVersions(): array
    {
        return array_values(array_unique([$this->protocolVersion, '2025-06-18', '2025-03-26']));
    }

    /** HTTP authorization must happen before any streaming response is emitted. */
    public function assertAuthorized(array $msg, ?McpIdentity $identity): void
    {
        $params = (array) ($msg['params'] ?? []);
        $name   = $params['name'] ?? null;
        if (($msg['method'] ?? null) === 'tools/call' && is_string($name) && $this->tools->has($name)) {
            $this->tools->authorize($this->tools->getTool($name), $identity);
        }
    }

    public function handle(array $msg, ?McpIdentity $identity = null): ?array
    {
        foreach ($this->messages($msg, $identity, false) as $message) {
            return $message;
        }

        return null;
    }

    /** @return Generator<int, array, mixed, void> */
    public function stream(array $msg, ?McpIdentity $identity = null): Generator
    {
        yield from $this->messages($msg, $identity, true);
    }

    private function messages(array $msg, ?McpIdentity $identity, bool $streaming): Generator
    {
        $id           = $msg['id'] ?? null;
        $notification = !array_key_exists('id', $msg);
        $method       = $msg['method'] ?? null;
        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($method)
            || (!$notification && !is_string($id) && !is_int($id))) {
            yield $this->error(is_string($id) || is_int($id) ? $id : null, -32600, 'Invalid Request');

            return;
        }
        if ($notification) {
            return;
        }
        $params = $msg['params'] ?? new stdClass();
        if (!is_object($params) && (!is_array($params) || ($params !== [] && array_is_list($params)))) {
            yield $this->error($id, -32602, 'Params must be an object.');

            return;
        }
        $params = (array) $params;

        try {
            switch ($method) {
                case 'initialize':
                    $version = $params['protocolVersion'] ?? null;
                    if (!is_string($version)) {
                        throw new InvalidArgumentException('initialize requires protocolVersion.');
                    }
                    yield $this->ok($id, [
                        'protocolVersion' => in_array($version, $this->supportedVersions(), true) ? $version : $this->protocolVersion,
                        'capabilities'    => ['tools' => (object) []],
                        'serverInfo'      => ['name' => $this->serverName, 'version' => $this->serverVersion],
                    ]);
                    break;
                case 'ping':
                    yield $this->ok($id, (object) []);
                    break;
                case 'tools/list':
                    yield $this->ok($id, ['tools' => $this->tools->definitions($identity)]);
                    break;
                case 'tools/call':
                    $name      = $params['name'] ?? null;
                    $arguments = $params['arguments'] ?? new stdClass();
                    if (!is_string($name) || !$this->tools->has($name)) {
                        throw new InvalidArgumentException('Unknown or missing tool name.');
                    }
                    if (!$arguments instanceof stdClass) {
                        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                            throw new InvalidArgumentException('Tool arguments must be an object.');
                        }
                        $arguments = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR));
                    }
                    [$tool, $args] = $this->tools->prepare($name, $arguments, $identity);
                    $meta          = (array) ($params['_meta'] ?? []);
                    $token         = $meta['progressToken'] ?? null;

                    try {
                        if ($streaming && $tool instanceof StreamingToolInterface) {
                            $execution        = $tool->stream($args);
                            $previousProgress = null;
                            foreach ($execution as $progress) {
                                if (!$progress instanceof Progress) {
                                    throw new InvalidArgumentException('Streaming tools must yield Progress objects.');
                                }
                                if ($previousProgress !== null && $progress->progress <= $previousProgress) {
                                    throw new InvalidArgumentException('Progress must increase.');
                                }
                                $previousProgress = $progress->progress;
                                if (is_string($token) || is_int($token)) {
                                    yield $progress->notification($token);
                                }
                            }
                            $result = $execution->getReturn();
                        } else {
                            $result = $tool->handle($args);
                        }
                        yield $this->ok($id, $this->tools->normalizeResult($tool, $result));
                    } catch (Throwable $exception) {
                        log()->error('MCP tool failed: ' . $exception->getMessage());
                        yield $this->ok($id, ToolResult::error('The tool could not complete the request.'));
                    }
                    break;
                default:
                    yield $this->error($id, -32601, 'Method not found');
            }
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            yield $this->error($id, -32602, $exception->getMessage());
        } catch (Throwable $exception) {
            log()->error('MCP protocol failed: ' . $exception->getMessage());
            yield $this->error($id, -32603, 'Internal error');
        }
    }

    private function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

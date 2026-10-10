<?php

declare(strict_types=1);

namespace Naf\MCP\Core;

use JsonException;
use LogicException;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\AuthorizationException;
use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Support\EventStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;

use function Naf\app;
use function Naf\config;
use function Naf\json;
use function Naf\response;

class MCPController
{
    public function __construct(
        private readonly MCPRouter $router,
        private readonly AuthenticatorInterface $authenticator,
    ) {
    }

    public function post(): ResponseInterface
    {
        $request = app()->container()->get(RequestInterface::class);
        if (($error = $this->checkTransport($request)) !== null) {
            return $error;
        }
        $identity = $this->authenticate($request);
        if ($identity === false) {
            return $this->error(401, -32001, 'Unauthorized');
        }
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            return $this->error(415, -32600, 'Content-Type must be application/json.');
        }
        if (!$this->accepts($request, 'application/json') || !$this->accepts($request, 'text/event-stream')) {
            return $this->error(406, -32600, 'Accept must include application/json and text/event-stream.');
        }

        $limit = config('mcp:transport:max_request_bytes', 1048576);
        if (!is_int($limit) || $limit < 1 || $limit >= PHP_INT_MAX) {
            throw new LogicException('mcp:transport:max_request_bytes must be a positive integer below PHP_INT_MAX.');
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $payload = '';
        while (!$body->eof() && strlen($payload) <= $limit) {
            $chunk = $body->read(min(8192, $limit + 1 - strlen($payload)));
            if ($chunk === '') {
                break;
            }
            $payload .= $chunk;
        }
        if (strlen($payload) > $limit) {
            return $this->error(413, -32600, 'MCP request body is too large.');
        }

        try {
            $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(400, -32700, 'Parse error');
        }
        if (!$decoded instanceof stdClass) {
            return $this->error(400, -32600, 'A single JSON-RPC object is required.');
        }
        $message = (array) $decoded;
        if (property_exists($decoded, 'params') && !$decoded->params instanceof stdClass) {
            return $this->error(400, -32602, 'Params must be an object.', $message['id'] ?? null);
        }
        if (($message['method'] ?? null) === 'tools/call' && isset($decoded->params)
            && property_exists($decoded->params, 'arguments') && !$decoded->params->arguments instanceof stdClass) {
            return $this->error(400, -32602, 'Tool arguments must be an object.', $message['id'] ?? null);
        }
        // Client responses have no outstanding server request in this stateless transport.
        if (!isset($message['method']) && ($message['jsonrpc'] ?? null) === '2.0'
            && (is_string($message['id'] ?? null) || is_int($message['id'] ?? null))
            && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
            return response('', 202);
        }

        try {
            $this->router->assertAuthorized($message, $identity);
            if (!array_key_exists('id', $message)) {
                $result = $this->router->handle($message, $identity);

                return $result === null ? response('', 202) : json($result, 400);
            }
            if (config('mcp:transport:streaming', false) === true) {
                return response('', 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache, no-transform',
                    'X-Accel-Buffering' => 'no',
                ])->withBody(new EventStream($this->router->stream($message, $identity)));
            }

            return json($this->router->handle($message, $identity));
        } catch (AuthorizationException $exception) {
            return $this->error($exception->status, -32001, $exception->getMessage(), $message['id'] ?? null);
        }
    }

    public function get(): ResponseInterface
    {
        $request = app()->container()->get(RequestInterface::class);
        if (($error = $this->checkTransport($request)) !== null) {
            return $error;
        }
        if ($this->authenticate($request) === false) {
            return $this->error(401, -32001, 'Unauthorized');
        }

        // Request-scoped streaming does not require a persistent GET channel or sessions.
        return response('', 405, ['Allow' => 'POST']);
    }

    public function metadata(): ResponseInterface
    {
        $resource = config('mcp:oauth:resource');
        $servers  = config('mcp:oauth:authorization_servers', []);
        if (!is_string($resource) || $resource === '' || !is_array($servers) || $servers === []) {
            return response('', 404);
        }
        $document = ['resource' => $resource, 'authorization_servers' => array_values($servers)];
        $scopes   = config('mcp:oauth:scopes_supported');
        if (is_array($scopes)) {
            $document['scopes_supported'] = array_values($scopes);
        }

        return json($document, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    private function checkTransport(RequestInterface $request): ?ResponseInterface
    {
        $origin    = $request->getHeaderLine('Origin');
        $allowed   = (array) config('mcp:transport:allowed_origins', []);
        $publicUrl = config('public_url');
        $public    = is_string($publicUrl) ? parse_url($publicUrl) : false;
        // The Host header cannot establish trust: DNS rebinding can supply both Host and Origin.
        if (is_array($public) && isset($public['scheme'], $public['host'])
            && in_array($public['scheme'], ['http', 'https'], true) && !isset($public['user']) && !isset($public['pass'])) {
            $allowed[] = $public['scheme'] . '://' . $public['host'] . (isset($public['port']) ? ':' . $public['port'] : '');
        }
        if ($origin !== '' && !in_array($origin, $allowed, true)) {
            return $this->error(403, -32000, 'Origin is not allowed.');
        }
        $version = $request->getHeaderLine('MCP-Protocol-Version');
        if ($version !== '' && !in_array($version, $this->router->supportedVersions(), true)) {
            return $this->error(400, -32600, 'Unsupported MCP protocol version.');
        }

        return null;
    }

    private function accepts(RequestInterface $request, string $type): bool
    {
        foreach (explode(',', strtolower($request->getHeaderLine('Accept'))) as $range) {
            $parts   = array_map('trim', explode(';', $range));
            $media   = array_shift($parts);
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }
            if ($quality > 0 && ($media === $type || $media === '*/*')) {
                return true;
            }
        }

        return false;
    }

    private function authenticate(RequestInterface $request): McpIdentity|false|null
    {
        return $this->authenticator->isEnabled() ? ($this->authenticator->authenticate($request) ?? false) : null;
    }

    private function error(int $status, int $code, string $message, mixed $id = null): ResponseInterface
    {
        return json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status, ['Cache-Control' => 'no-store']);
    }
}

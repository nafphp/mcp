<?php

declare(strict_types=1);

namespace NixPHP\MCP\Core;

use NixPHP\MCP\Auth\AuthenticatorInterface;
use NixPHP\MCP\Auth\McpIdentity;
use Psr\Http\Message\RequestInterface;
use function NixPHP\app;
use function NixPHP\json;
use function NixPHP\response;

class MCPController
{
    public function __construct(
        private readonly MCPRouter $router,
        private readonly AuthenticatorInterface $authenticator,
    ) {}

    public function post()
    {
        $req  = app()->container()->get(RequestInterface::class);
        $identity = $this->authenticate($req);
        if ($identity === false) {
            return json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    'code'    => -32001,
                    'message' => 'Unauthorized',
                ],
            ], 401);
        }

        $body = (string)$req->getBody();

        $msg = json_decode($body, true);

        if (!is_array($msg)) { // Parse error
            return json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    'code'    => -32700,
                    'message' => 'Parse error',
                ],
            ])->withStatus(400);
        }

        $response = $this->router->handle($msg, $identity);

        // Notification => 202 empty
        if (null === $response) {
            return response('', 202);
        }

        return json($response);
    }

    public function get()
    {
        $req = app()->container()->get(RequestInterface::class);
        if ($this->authenticate($req) === false) {
            return response('', 401);
        }

        // Streamable HTTP supports optional server-initiated SSE streams via GET.
        // This plugin currently serves request/response JSON-RPC via POST only,
        // so GET must be rejected explicitly instead of pretending to stream.
        return response('', 405, ['Allow' => 'POST']);
    }

    private function authenticate(RequestInterface $request): McpIdentity|false|null
    {
        if (!$this->authenticator->isEnabled()) {
            return null;
        }

        $identity = $this->authenticator->authenticate($request);

        return $identity ?? false;
    }
}

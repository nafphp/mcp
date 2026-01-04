<?php

declare(strict_types=1);

namespace NixPHP\MCP\Core;

use Psr\Http\Message\RequestInterface;
use function NixPHP\app;
use function NixPHP\json;
use function NixPHP\response;

class MCPController
{
    public function __construct(
        private readonly MCPRouter $router
    ) {}

    public function post()
    {
        $req  = app()->container()->get(RequestInterface::class);
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

        $response = $this->router->handle($msg);

        // Notification => 202 empty
        if (null === $response) {
            return response('', 202);
        }

        return json($response);
    }

    public function get()
    {
        // Start simple: don't SSE here (prevents weird connector setup issues)
        return response('', 204);
    }
}

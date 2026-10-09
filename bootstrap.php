<?php

declare(strict_types=1);

use Naf\CLI\Core\AbstractCommand;
use Naf\Core\Event;
use Naf\Exceptions\ConfigurationException;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\BearerTokenAuthenticator;
use Naf\MCP\Auth\DisabledAuthenticator;
use Naf\MCP\Auth\OAuthAuthenticator;
use Naf\MCP\Commands\CreateTokenCommand;
use Naf\MCP\Commands\ListTokensCommand;
use Naf\MCP\Commands\RevokeTokenCommand;
use Naf\MCP\Store\FileTokenStore;
use Naf\MCP\Store\TokenStoreInterface;
use Naf\MCP\Support\ToolRegistry;
use Naf\OAuth\Server\Core\ResourceServer;
use Psr\Http\Message\ResponseInterface;

use function Naf\app;
use function Naf\config;
use function Naf\event;

// The core clears PHP output buffers before this hook. Enable SAPI flushing for SSE only.
event()->listen(Event::RESPONSE_BODY, static function (ResponseInterface $response): void {
    if (strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) === 'text/event-stream') {
        ob_implicit_flush(true);
    }
});

app()->container()->set(ToolRegistry::class, static fn() => new ToolRegistry(config('mcp:auth:discovery_scope')));

app()->container()->set(TokenStoreInterface::class, function () {
    $defaultPath = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/mcp/tokens.json';
    $path        = config('mcp:auth:token_file', $defaultPath);

    return new FileTokenStore(is_string($path) && $path !== '' ? $path : $defaultPath);
});

app()->container()->set(AuthenticatorInterface::class, function () {
    $enabled = (bool) config('mcp:auth:enabled', true);

    if (!$enabled) {
        return new DisabledAuthenticator();
    }

    $driver = config('mcp:auth:driver', 'file');
    if ($driver === 'oauth') {
        if (!app()->hasPlugin('naf/oauth-server') || !class_exists(ResourceServer::class)) {
            throw new ConfigurationException('The MCP OAuth driver requires naf/oauth-server.');
        }

        return new OAuthAuthenticator(app()->container()->get(ResourceServer::class));
    }
    if ($driver !== 'file') {
        throw new ConfigurationException('Unknown MCP authentication driver.');
    }

    return new BearerTokenAuthenticator(
        app()->container()->get(TokenStoreInterface::class),
        true,
    );
});

if (
    (app()->hasPlugin('naf/cli') || function_exists('Naf\CLI\command'))
    && class_exists(AbstractCommand::class)
    && function_exists('Naf\CLI\command')
) {
    \Naf\CLI\command()->add(CreateTokenCommand::class);
    \Naf\CLI\command()->add(ListTokensCommand::class);
    \Naf\CLI\command()->add(RevokeTokenCommand::class);
}

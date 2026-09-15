<?php

declare(strict_types=1);

use Naf\CLI\Core\AbstractCommand;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\BearerTokenAuthenticator;
use Naf\MCP\Auth\DisabledAuthenticator;
use Naf\MCP\Commands\CreateTokenCommand;
use Naf\MCP\Commands\ListTokensCommand;
use Naf\MCP\Commands\RevokeTokenCommand;
use Naf\MCP\Store\FileTokenStore;
use Naf\MCP\Store\TokenStoreInterface;
use Naf\MCP\Support\ToolRegistry;

use function Naf\app;
use function Naf\config;

app()->container()->set(ToolRegistry::class, new ToolRegistry());

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

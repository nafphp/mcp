<?php

declare(strict_types=1);

use NixPHP\MCP\Auth\AuthenticatorInterface;
use NixPHP\MCP\Auth\BearerTokenAuthenticator;
use NixPHP\MCP\Auth\DisabledAuthenticator;
use NixPHP\MCP\Commands\CreateTokenCommand;
use NixPHP\MCP\Commands\ListTokensCommand;
use NixPHP\MCP\Commands\RevokeTokenCommand;
use NixPHP\MCP\Store\FileTokenStore;
use NixPHP\MCP\Store\TokenStoreInterface;
use NixPHP\MCP\Support\ToolRegistry;
use function NixPHP\app;
use function NixPHP\config;

app()->container()->set(ToolRegistry::class, new ToolRegistry());

app()->container()->set(TokenStoreInterface::class, function () {
    $defaultPath = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/mcp/tokens.json';
    $path = config('mcp:auth:token_file', $defaultPath);

    return new FileTokenStore(is_string($path) && $path !== '' ? $path : $defaultPath);
});

app()->container()->set(AuthenticatorInterface::class, function () {
    $enabled = (bool)config('mcp:auth:enabled', true);

    if (!$enabled) {
        return new DisabledAuthenticator();
    }

    return new BearerTokenAuthenticator(
        app()->container()->get(TokenStoreInterface::class),
        true
    );
});

if (
    (app()->hasPlugin('nixphp/cli') || function_exists('NixPHP\CLI\command'))
    && class_exists(\NixPHP\CLI\Core\AbstractCommand::class)
    && function_exists('NixPHP\CLI\command')
) {
    \NixPHP\CLI\command()->add(CreateTokenCommand::class);
    \NixPHP\CLI\command()->add(ListTokensCommand::class);
    \NixPHP\CLI\command()->add(RevokeTokenCommand::class);
}

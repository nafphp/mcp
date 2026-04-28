<?php

declare(strict_types=1);

namespace NixPHP\MCP\Auth;

use Psr\Http\Message\RequestInterface;

interface AuthenticatorInterface
{
    public function isEnabled(): bool;

    public function authenticate(RequestInterface $request): ?McpIdentity;
}

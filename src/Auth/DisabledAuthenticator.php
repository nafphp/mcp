<?php

declare(strict_types=1);

namespace NixPHP\MCP\Auth;

use Psr\Http\Message\RequestInterface;

final class DisabledAuthenticator implements AuthenticatorInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function authenticate(RequestInterface $request): ?McpIdentity
    {
        return null;
    }
}

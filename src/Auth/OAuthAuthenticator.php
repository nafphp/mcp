<?php

declare(strict_types=1);

namespace Naf\MCP\Auth;

use Naf\OAuth\Server\Core\ResourceServer;
use Psr\Http\Message\RequestInterface;

/** Optional naf/oauth-server integration; its resource server verifies audience and token lifecycle. */
final readonly class OAuthAuthenticator implements AuthenticatorInterface
{
    public function __construct(private ResourceServer $server)
    {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function authenticate(RequestInterface $request): ?McpIdentity
    {
        $context = $this->server->context();
        if (!$context->active() || (!$context->isApplication() && $context->user() === null)) {
            return null;
        }

        // Recheck the user's current permissions, rather than copying token scopes blindly.
        $scopes = array_values(array_filter($context->scopes(), $context->can(...)));

        return new McpIdentity(
            (string) $context->clientId(),
            (string) $context->clientId(),
            $scopes,
            !$context->isApplication(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use Generator;
use Naf\Core\Config;
use Naf\MCP\Auth\AuthenticatorInterface;
use Naf\MCP\Auth\DisabledAuthenticator;
use Naf\MCP\Auth\McpIdentity;
use Naf\MCP\Core\MCPController;
use Naf\MCP\Core\MCPRouter;
use Naf\MCP\Support\EventStream;
use Naf\MCP\Support\Progress;
use Naf\MCP\Support\ToolRegistry;
use Naf\MCP\Support\ToolResult;
use Naf\MCP\Tools\ScopedToolInterface;
use Naf\MCP\Tools\StreamingToolInterface;
use Naf\MCP\Tools\ToolMetadataInterface;
use Naf\MCP\Tools\UserToolInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Naf\app;

final class ProtocolTest extends TestCase
{
    private ToolRegistry $tools;
    private MCPRouter $router;
    private McpIdentity $service;
    private McpIdentity $user;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }
        app()->container()->set(Config::class, new Config(require dirname(__DIR__, 2) . '/src/config.php'));
        app()->container()->set(LoggerInterface::class, new NullLogger());
        $this->tools = new ToolRegistry('mcp:service');
        $this->tools->register(new PersonalTool());
        $this->tools->register(new StreamingTool());
        $this->router  = new MCPRouter($this->tools);
        $this->service = new McpIdentity('service', 'Alexa', ['mcp:service']);
        $this->user    = new McpIdentity('user', 'Alexa', ['account:read'], true);
    }

    public function testNegotiationPingAndNotifications(): void
    {
        foreach (['2025-03-26', '2025-06-18', '2025-11-25'] as $version) {
            $reply = $this->router->handle($this->message('initialize', ['protocolVersion' => $version]));
            self::assertSame($version, $reply['result']['protocolVersion']);
        }
        self::assertEquals((object) [], $this->router->handle($this->message('ping'))['result']);
        self::assertNull($this->router->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertSame(-32600, $this->router->handle(['id' => 1])['error']['code']);
    }

    public function testServiceDiscoveryDoesNotGrantUserExecution(): void
    {
        self::assertSame(['personal', 'stream'], array_column($this->tools->definitions($this->service), 'name'));
        $denied = $this->post($this->message('tools/call', ['name' => 'personal', 'arguments' => ['count' => 2]]), $this->service);
        self::assertSame(401, $denied->getStatusCode());
        self::assertFalse($denied->hasHeader('WWW-Authenticate'));
        $allowed = $this->post($this->message('tools/call', ['name' => 'personal', 'arguments' => ['count' => 2]]), $this->user);
        self::assertSame(['count' => 2], json_decode((string) $allowed->getBody(), true)['result']['structuredContent']);
        $limited = new McpIdentity('user', 'Alexa', [], true);
        self::assertSame(403, $this->post($this->message('tools/call', ['name' => 'personal', 'arguments' => ['count' => 2]]), $limited)->getStatusCode());
    }

    public function testInvalidArgumentsAreRejectedBeforeTheHandler(): void
    {
        $reply = $this->post($this->message('tools/call', ['name' => 'personal', 'arguments' => ['count' => 'wrong']]), $this->user);
        self::assertSame(-32602, json_decode((string) $reply->getBody(), true)['error']['code']);
        $reply = $this->post($this->message('tools/call', ['name' => 'personal', 'arguments' => ['count' => 1, 'extra' => true]]), $this->user);
        self::assertSame(-32602, json_decode((string) $reply->getBody(), true)['error']['code']);
    }

    public function testStreamingCanBeEnabledAndDisabledAndPreservesResults(): void
    {
        $request = $this->message('tools/call', ['name' => 'stream', '_meta' => ['progressToken' => 'job-1']]);
        $plain   = $this->post($request, $this->service);
        self::assertStringStartsWith('application/json', $plain->getHeaderLine('Content-Type'));
        self::assertSame('complete', json_decode((string) $plain->getBody(), true)['result']['content'][0]['text']);
        app()->container()->set(Config::class, new Config(['mcp' => ['transport' => ['streaming' => true]]]));
        $stream = $this->post($request, $this->service);
        self::assertSame('text/event-stream', $stream->getHeaderLine('Content-Type'));
        $body = $stream->getBody();
        self::assertInstanceOf(EventStream::class, $body);
        $first = $body->read(8192);
        self::assertStringContainsString('notifications/progress', $first);
        self::assertStringContainsString('job-1', $first);
        self::assertStringNotContainsString('complete', $first);
        $remaining = $body->getContents();
        self::assertStringContainsString('complete', $remaining);
        self::assertTrue($body->eof());
        self::assertFalse($body->isSeekable());
        self::assertSame('', $body->read(1));
    }

    public function testHttpBoundaryRejectsUnauthenticatedOriginVersionAndMediaFailures(): void
    {
        self::assertSame(401, $this->post($this->message('ping'), null)->getStatusCode());
        self::assertSame(403, $this->post($this->message('ping'), $this->service, ['Origin' => 'https://evil.test'])->getStatusCode());
        self::assertSame(403, $this->post($this->message('ping'), $this->service, ['Origin' => 'https://tools.example.test'])->getStatusCode());
        self::assertSame(400, $this->post($this->message('ping'), $this->service, ['MCP-Protocol-Version' => 'future'])->getStatusCode());
        self::assertSame(415, $this->post($this->message('ping'), $this->service, ['Content-Type' => 'text/plain'])->getStatusCode());
        self::assertSame(406, $this->post($this->message('ping'), $this->service, ['Accept' => 'application/json'])->getStatusCode());
        $reply = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->service);
        self::assertSame(202, $reply->getStatusCode());
        self::assertSame('', (string) $reply->getBody());
        self::assertSame(202, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], $this->service)->getStatusCode());
        self::assertSame(400, $this->post($this->message('tools/call', ['name' => 'stream', 'arguments' => []]), $this->service)->getStatusCode());
        app()->container()->set(Config::class, new Config(['public_url' => 'https://tools.example.test']));
        self::assertSame(200, $this->post($this->message('ping'), $this->service, ['Origin' => 'https://tools.example.test'])->getStatusCode());
    }

    public function testOutputSchemasAreAdvertisedAndEnforcedWithoutLeakingFailures(): void
    {
        $tool = new class implements ToolMetadataInterface {
            public function name(): string
            {
                return 'schema';
            }

            public function description(): string
            {
                return 'Return validated structured data.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function metadata(): array
            {
                return ['outputSchema' => ['type' => 'object', 'required' => ['number'], 'properties' => ['number' => ['type' => 'integer']]]];
            }

            public function handle(array $args): mixed
            {
                return ['number' => 'invalid'];
            }
        };
        $this->tools->register($tool);
        self::assertArrayHasKey('outputSchema', $this->tools->definitions($this->service)[2]);
        $reply = $this->router->handle($this->message('tools/call', ['name' => 'schema']), $this->service);
        self::assertTrue($reply['result']['isError']);
        self::assertSame('The tool could not complete the request.', $reply['result']['content'][0]['text']);
    }

    public function testRequestBodyLimitIncludesUnknownSizeStreamsAndExactBoundary(): void
    {
        app()->container()->set(Config::class, new Config(['mcp' => ['transport' => ['max_request_bytes' => 128]]]));
        $controller = new MCPController($this->router, new DisabledAuthenticator());
        foreach ([128 => 200, 129 => 413] as $length => $status) {
            $payload = str_pad(json_encode($this->message('ping')), $length);
            $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'], $payload);
            app()->container()->set(RequestInterface::class, $request);
            self::assertSame($status, $controller->post()->getStatusCode());
        }
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, str_repeat('x', 1024));
        rewind($resource);
        $stream = new class ($resource) extends Stream {
            public int $bytesRead = 0;

            public function getSize(): ?int
            {
                return null;
            }

            public function read($length): string
            {
                $chunk = parent::read(min(7, $length));
                $this->bytesRead += strlen($chunk);

                return $chunk;
            }
        };
        app()->container()->set(RequestInterface::class, $request->withBody($stream)->withoutHeader('Content-Length'));
        self::assertSame(413, $controller->post()->getStatusCode());
        self::assertSame(129, $stream->bytesRead);
    }

    private function message(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => (object) $params];
    }

    private function post(array $message, ?McpIdentity $identity, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('POST', 'https://tools.example.test/mcp', $headers + [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        ], json_encode($message, JSON_THROW_ON_ERROR));
        app()->container()->set(RequestInterface::class, $request);
        $authenticator = new class ($identity) implements AuthenticatorInterface {
            public function __construct(private readonly ?McpIdentity $identity)
            {
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function authenticate(RequestInterface $request): ?McpIdentity
            {
                return $this->identity;
            }
        };

        return (new MCPController($this->router, $authenticator))->post();
    }
}

final class PersonalTool implements UserToolInterface, ScopedToolInterface
{
    public function name(): string
    {
        return 'personal';
    }

    public function description(): string
    {
        return 'Personal test data.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'required' => ['count']];
    }

    public function requiredScopes(): array
    {
        return ['account:read'];
    }

    public function handle(array $args): mixed
    {
        return $args;
    }
}

final class StreamingTool implements StreamingToolInterface
{
    public function name(): string
    {
        return 'stream';
    }

    public function description(): string
    {
        return 'Streaming test data.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function handle(array $args): mixed
    {
        return ToolResult::text('complete');
    }

    public function stream(array $args): Generator
    {
        yield new Progress(0, 1, 'Starting');
        yield new Progress(1, 1, 'Finished');

        return $this->handle($args);
    }
}

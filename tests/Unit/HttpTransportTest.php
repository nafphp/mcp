<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    public function testARealHostAuthenticatesAndDeliversProgressBeforeCompletion(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $root = sys_get_temp_dir() . '/naf-mcp-http-' . bin2hex(random_bytes(6));
        mkdir($root);
        $process = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-S', $address, dirname(__DIR__) . '/Fixtures/http.php'], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a'],
        ], $pipes, null, ['NAF_MCP_HTTP_ROOT' => $root] + getenv());
        self::assertIsResource($process);

        try {
            $socket = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $socket = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if (is_resource($socket)) {
                    break;
                }
                usleep(20000);
            }
            self::assertIsResource($socket, (string) file_get_contents($root . '/server.log'));
            stream_set_timeout($socket, 3);
            $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'delayed', '_meta' => ['progressToken' => 'job-1'],
            ]]);
            $started = microtime(true);
            fwrite($socket, "POST /mcp HTTP/1.1\r\nHost: $address\r\nAuthorization: Bearer test-service\r\nAccept: application/json, text/event-stream\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            $received = '';
            while (($line = fgets($socket)) !== false) {
                $received .= $line;
                if (str_contains($line, 'notifications/progress')) {
                    break;
                }
            }
            $firstEventAt = microtime(true) - $started;
            self::assertStringContainsString('200 OK', $received, (string) file_get_contents($root . '/server.log'));
            self::assertStringContainsString('text/event-stream', $received);
            self::assertStringContainsString('notifications/progress', $received);
            self::assertLessThan(0.35, $firstEventAt, 'Progress was buffered until the tool completed.');
            self::assertStringContainsString('complete', stream_get_contents($socket));
            self::assertGreaterThan(0.4, microtime(true) - $started);
            fclose($socket);

            $context = stream_context_create(['http' => ['ignore_errors' => true]]);
            file_get_contents('http://' . $address . '/mcp', false, $context);
            self::assertStringContainsString('401', $http_response_header[0]);
            $metadata = json_decode(file_get_contents('http://' . $address . '/.well-known/oauth-protected-resource', false, $context), true);
            self::assertSame('https://tools.example.test/mcp', $metadata['resource']);
        } finally {
            proc_terminate($process);
            proc_close($process);
            foreach (glob($root . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($root);
        }
    }
}

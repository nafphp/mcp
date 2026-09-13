<div style="text-align: center;" align="center">

![NAF](assets/naf-logo-small-square.png)

[![NAF MCP Plugin](https://github.com/nafphp/mcp/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/mcp/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/mcp

> **Model Context Protocol (MCP) server implementation for NAF (Tools-first).**

This plugin turns your NAF application into an **MCP server** that exposes
**Tools** to AI clients such as ChatGPT.

> 🧩 Part of the official NAF plugin collection.

---

## 📦 Features

* JSON-RPC 2.0 compliant MCP endpoint
* Tool discovery via `tools/list`
* Tool execution via `tools/call`
* JSON Schema–driven tool descriptions
* Action-based tools (single tool, multiple behaviors)
* Long-running tools supported (blocking by design)
* No queues, no workers, no background state
* Simple, debuggable request flow

> ⚠️ This plugin currently operates in **Tools-only mode**.
> MCP Resources are currently **not supported**.

---

## 📥 Installation

```bash
composer require naf/mcp
```

The plugin auto-registers an MCP endpoint at:

```
POST /mcp
```

The endpoint uses MCP Streamable HTTP in its minimal request/response form:

* JSON-RPC messages are sent via `POST /mcp`
* responses are returned as `application/json`
* server-initiated SSE streams are not opened yet
* `GET /mcp` returns `405 Method Not Allowed`

---

## Authentication

The endpoint is protected by Bearer token authentication by default. Tokens are
stored file-based, so apps can use MCP without introducing database tables.

Default token file:

```
storage/mcp/tokens.json
```

App configuration may override or disable this:

```php
return [
    'mcp' => [
        'auth' => [
            'enabled' => true,
            'driver' => 'file',
            'token_file' => BASE_PATH . '/storage/mcp/tokens.json',
        ],
    ],
];
```

For internal or local-only projects authentication can be opened explicitly:

```php
'mcp' => [
    'auth' => [
        'enabled' => false,
    ],
],
```

Create a token from application code:

```php
use function Naf\MCP\tokens;

$created = tokens()->create('Local AI client', ['*']);

echo $created->plainToken; // shown once, only the hash is stored
```

If `naf/cli` is installed, the plugin registers token commands
automatically:

```bash
vendor/bin/nix mcp:token:create "Local AI client" --scope "*"
vendor/bin/nix mcp:token:list
vendor/bin/nix mcp:token:revoke tok_...
```

Clients send the token as:

```http
Authorization: Bearer mcp_...
```

### Tool Scopes

Tools may opt into scope checks by implementing `ScopedToolInterface`:

```php
use Naf\MCP\Tools\ScopedToolInterface;
use Naf\MCP\Tools\ToolInterface;

final class ArticleSearchTool implements ToolInterface, ScopedToolInterface
{
    public function requiredScopes(): array
    {
        return ['articles:read'];
    }

    // ToolInterface methods...
}
```

Supported scope patterns:

* `*`
* exact scopes such as `articles:read`
* prefix wildcards such as `articles:*`

---

## Core Concept: Tools

Tools represent **actions**.

They:

* accept structured input (JSON Schema)
* execute application logic
* return structured results
* may read/write data internally

Examples:

* calculate a folder size
* analyze files
* summarize structured data

---

## How the model interacts with your app (`initialize`)

On connection, the MCP server announces **capabilities**, not concrete tools.

```json
{
  "jsonrpc": "2.0",
  "method": "initialize",
  "params": {}
}
```

Response (simplified):

```json
{
  "result": {
    "protocolVersion": "2025-06-18",
    "capabilities": {
      "tools": {}
    },
    "serverInfo": {
      "name": "naf-mcp",
      "version": "0.1.0"
    }
  }
}
```

> `capabilities.tools` signals that this server supports MCP tools.

---

## Tool Discovery (`tools/list`)

Clients explicitly request the available tools:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list"
}
```

Response:

```json
{
  "result": {
    "tools": [
      {
        "name": "get_folder_size",
        "description": "Returns the size of a folder.",
        "inputSchema": { "... JSON Schema ..." }
      }
    ]
  }
}
```

---

## Example Tool: Folder Size

### PHP Tool Implementation

```php
use Naf\MCP\Support\Schema;
use Naf\MCP\Tools\ToolInterface;

final class GetFolderSize implements ToolInterface
{
    public function name(): string
    {
        return 'get_folder_size';
    }

    public function description(): string
    {
        return 'Returns the size of a folder.';
    }

    public function inputSchema(): array
    {
        return Schema::object()
            ->description($this->description())
            ->additionalProperties(false)
            ->prop('path', Schema::string()->description('Relative folder path'))
            ->required('path')
            ->toArray();
    }

    public function handle(array $args): array
    {
        $path = (string)$args['path'];

        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            $bytes += $file->getSize();
        }

        return [
            'path'  => $path,
            'bytes' => $bytes,
            'human' => round($bytes / 1024 / 1024, 1) . ' MB',
        ];
    }
}
```

---

## How the model is calling the tool (`tools/call`)

### JSON-RPC Request

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "get_folder_size",
    "arguments": {
      "path": "var/log"
    }
  }
}
```

### Response

```json
{
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\n  \"path\": \"var/log\",\n  \"bytes\": 25500000,\n  \"human\": \"25.5 MB\"\n}"
      }
    ],
    "isError": false
  }
}
```

---

## Action-Based Tools (Optional Pattern)

Tools may expose multiple behaviors via an `action` parameter:

```json
{
  "action": "analyze|summary|details"
}
```

This allows grouping related operations into a single tool
while keeping schemas explicit.

This pattern is optional but recommended for more complex tools.

---

## Storage & Filesystem Access

The plugin ships with a `FilesystemStore` utility.

Important notes:

* `FilesystemStore` is **internal**
* it is **not exposed via MCP**
* it is **not a Resource API**

Its purpose is to provide:

* safe, sandboxed filesystem access
* path traversal protection
* size limits
* predictable storage layout

Typical usage inside a tool:

```php
$this->store->read('tools/get_folder_size/cache.json');
$this->store->write('tools/get_folder_size/cache.json', $json);
```

Storage root (default):

```
{app_dir}/storage/
```

---

## About MCP Resources

This plugin currently **does not expose MCP Resources**
(`resources/read`, `resources/list`, `resources/write`).

Reasoning:

* Tools already cover most required use cases
* Resources add conceptual overhead
* Most MCP clients primarily use tools

Resources may be added later as an extension.

---

## Requirements

* PHP ≥ 8.3
* `naf/framework` ≥ 0.1.0

---

## 📄 License

MIT License.

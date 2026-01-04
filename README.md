<div style="text-align: center;" align="center">

![Logo](https://nixphp.github.io/docs/assets/nixphp-logo-small-square.png)

[![NixPHP MCP Plugin](https://github.com/nixphp/mcp/actions/workflows/php.yml/badge.svg)](https://github.com/nixphp/mcp/actions/workflows/php.yml)

</div>

[← Back to NixPHP](https://github.com/nixphp/framework)

---

# nixphp/mcp

> **Model Context Protocol (MCP) server implementation for NixPHP (Tools-first).**

This plugin turns your NixPHP application into an **MCP server** that exposes
**Tools** to AI clients such as ChatGPT.

> 🧩 Part of the official NixPHP plugin collection.

---

## 📦 Features

* JSON-RPC 2.0 compliant MCP endpoint
* Tool discovery via `tools/list`
* Tool execution via `tools/call`
* JSON Schema–driven input validation
* Action-based tools (single tool, multiple behaviors)
* Long-running tools supported (blocking by design)
* No queues, no workers, no background state
* Simple, debuggable request flow

> ⚠️ This plugin currently operates in **Tools-only mode**.
> MCP Resources are currently **not supported**.

---

## 📥 Installation

```bash
composer require nixphp/mcp
```

The plugin auto-registers an MCP endpoint at:

```
POST /mcp
```

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
      "name": "nixphp-mcp",
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
use NixPHP\MCP\Support\Schema;
use NixPHP\MCP\Tools\ToolInterface;

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
        "text": {
          "path": "var/log",
          "bytes": 25500000,
          "human": "25.5 MB"
        }
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

* PHP ≥ 8.1
* `nixphp/framework` ≥ 0.1.2

---

## 📄 License

MIT License.
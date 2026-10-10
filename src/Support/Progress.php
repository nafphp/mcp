<?php

declare(strict_types=1);

namespace Naf\MCP\Support;

use InvalidArgumentException;

final readonly class Progress
{
    public function __construct(public float $progress, public ?float $total = null, public ?string $message = null)
    {
        if (!is_finite($progress) || $progress < 0 || ($total !== null && (!is_finite($total) || $total < $progress))) {
            throw new InvalidArgumentException('Progress must be finite, non-negative and no greater than total.');
        }
    }

    public function notification(string|int $token): array
    {
        $params = ['progressToken' => $token, 'progress' => $this->progress];
        if ($this->total !== null) {
            $params['total'] = $this->total;
        }
        if ($this->message !== null) {
            $params['message'] = $this->message;
        }

        return ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => $params];
    }
}

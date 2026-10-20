<?php

namespace App\Services\System;

/** Ephemeral per-request profiler; never records SQL statements, bindings, inputs or user identities. */
final class RequestTraceContext
{
    private int $queryCount = 0;
    private float $queryMilliseconds = 0.0;
    private bool $complete = false;

    public function query(float $milliseconds): void
    {
        if ($this->complete) {
            return;
        }

        ++$this->queryCount;
        $this->queryMilliseconds += max(0, $milliseconds);
    }

    public function finish(): array
    {
        $this->complete = true;

        return [
            'db_query_count' => $this->queryCount,
            'db_duration_ms' => round($this->queryMilliseconds, 2),
        ];
    }
}

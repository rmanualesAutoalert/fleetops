<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;

final class QueryProfile
{
    public int $count = 0;

    public float $milliseconds = 0;

    private bool $active = true;

    public function record(QueryExecuted $query): void
    {
        if ($this->active) {
            $this->count++;
            $this->milliseconds += $query->time;
        }
    }

    public function stop(): void
    {
        $this->active = false;
    }
}

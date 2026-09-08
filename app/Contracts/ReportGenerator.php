<?php

namespace App\Contracts;

interface ReportGenerator
{
    /** @return array<int, array{branch_id: int, month: string, jobs: int, revenue: string}> */
    public function generate(): array;
}

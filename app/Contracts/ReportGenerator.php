<?php

namespace App\Contracts;

interface ReportGenerator
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function generate(): array;
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'summary_date', 'jobs', 'revenue'])]
class DailyBranchSummary extends Model
{
    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'jobs' => 'integer',
            'revenue' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

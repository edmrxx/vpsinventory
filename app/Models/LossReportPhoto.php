<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo kept as evidence of a loss (phase 33). The file is on the private
 * disk (`App\Support\LossPhoto`), served only through the report's own route
 * to somebody who may read the report.
 */
class LossReportPhoto extends Model
{
    protected $fillable = ['loss_report_id', 'path', 'original_name'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(LossReport::class, 'loss_report_id');
    }
}

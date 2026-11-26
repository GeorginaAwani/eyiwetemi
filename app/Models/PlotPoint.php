<?php

namespace App\Models;

use Database\Factories\PlotPointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plot_thread_id', 'work_item_id', 'text', 'position'])]
class PlotPoint extends Model
{
    /** @use HasFactory<PlotPointFactory> */
    use HasFactory;

    /** @return BelongsTo<PlotThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(PlotThread::class, 'plot_thread_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }
}

<?php

namespace App\Support;

use App\Enums\WorkKind;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

class LibraryCounts
{
    /**
     * How many works each Library tab holds. A collection counts once, under its kind.
     *
     * @return array{all: int, novel: int, story: int, poem: int, essay: int}
     */
    public static function get(): array
    {
        $rows = Work::query()
            ->listed()
            ->select(DB::raw("case when kind = '".WorkKind::Collection->value."' then collection_kind else kind end as tab"), DB::raw('count(*) as total'))
            ->groupBy('tab')
            ->pluck('total', 'tab');

        $counts = [
            'novel' => (int) ($rows['novel'] ?? 0),
            'story' => (int) ($rows['story'] ?? 0),
            'poem' => (int) ($rows['poem'] ?? 0),
            'essay' => (int) ($rows['essay'] ?? 0),
        ];

        return ['all' => array_sum($counts), ...$counts];
    }
}

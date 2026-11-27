<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\StatsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's stats page: the page itself, opened on the range it is asked for, and the JSON it fetches when the range
 * changes.
 */
class StatsController extends Controller
{
    /** The metrics the chart can plot, the first one shown by default. */
    private const METRICS = ['reads', 'readers', 'finished'];

    /**
     * The stats page. `?r=` picks the range (7, 30, 12 or all; 30 when missing or unknown), `?m=` the metric the chart
     * plots, and `?open=` the work whose panel is open.
     */
    public function show(Request $request): Response
    {
        $metric = $this->text($request, 'm');

        return Inertia::render('Admin/Stats', [
            'stats' => StatsPage::props($this->text($request, 'r') ?? ''),
            'metric' => in_array($metric, self::METRICS, true) ? $metric : 'reads',
            'open' => $this->text($request, 'open') ?? '',
        ]);
    }

    /**
     * The stats for the range in `?r=`, so the page can switch range without a page load.
     */
    public function range(Request $request): JsonResponse
    {
        return response()->json(['stats' => StatsPage::props($this->text($request, 'r') ?? '')]);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}

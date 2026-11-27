<?php

namespace App\Http\Controllers;

use App\Http\Requests\CountRequest;
use App\Support\ReadCounter;
use Illuminate\Http\Response;

class CountController extends Controller
{
    /**
     * A reader's hit from the reading site: counted in the daily totals, and always answered with nothing.
     */
    public function __invoke(CountRequest $request, ReadCounter $counter): Response
    {
        $counter->record($request, $request->validated());

        return response()->noContent();
    }
}

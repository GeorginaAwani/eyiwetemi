<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Support\SiteSearch;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    /**
     * The header search: works, series, chapters and notes that match the words typed, as JSON. Public, for guests too.
     */
    public function __invoke(SearchRequest $request): JsonResponse
    {
        return response()->json((new SiteSearch((string) $request->validated('q')))->toArray());
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\LibraryPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    /**
     * The Library, opened on the tab in the address (?tab=novel), or All.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Library', (new LibraryPage)->toArray((string) $request->query('tab', 'all')));
    }

    /**
     * Show more: the next rows of a tab after the ones already on the page.
     */
    public function rows(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['required', Rule::in(LibraryPage::TABS)],
            'shown' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        return response()->json((new LibraryPage)->more($data['tab'], (int) $data['shown']));
    }
}

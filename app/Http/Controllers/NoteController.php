<?php

namespace App\Http\Controllers;

use App\Support\NotesPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NoteController extends Controller
{
    /**
     * Notes, newest first, by month.
     */
    public function index(): Response
    {
        return Inertia::render('Notes', (new NotesPage)->toArray());
    }

    /**
     * Older notes: the next batch after the last note on the page.
     */
    public function older(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => ['required', 'integer', 'min:1']]);

        return response()->json((new NotesPage)->after((int) $data['after']));
    }
}

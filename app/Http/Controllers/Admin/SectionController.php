<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's sections. Step 1 builds the shell only: each section opens with its menu item current and an empty page,
 * filled in by its own build step.
 */
class SectionController extends Controller
{
    public function desk(): Response
    {
        return Inertia::render('Admin/Section', ['section' => 'desk']);
    }

    public function show(string $section): Response
    {
        return Inertia::render('Admin/Section', ['section' => $section]);
    }
}

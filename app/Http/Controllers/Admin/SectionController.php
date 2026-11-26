<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DeskPage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's sections: the desk, and the others, which open empty until their own build step fills them in.
 */
class SectionController extends Controller
{
    public function desk(): Response
    {
        return Inertia::render('Admin/Desk', DeskPage::props());
    }

    public function show(string $section): Response
    {
        return Inertia::render('Admin/Section', ['section' => $section]);
    }
}

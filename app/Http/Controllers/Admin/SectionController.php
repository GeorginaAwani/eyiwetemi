<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DeskPage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin's desk, the first screen after sign-in.
 */
class SectionController extends Controller
{
    public function desk(): Response
    {
        return Inertia::render('Admin/Desk', DeskPage::props());
    }
}

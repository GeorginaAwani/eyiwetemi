<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The reading room. Its sections arrive with the Home build; for now it shows the shared layout.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Home');
    }
}

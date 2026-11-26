<?php

namespace App\Http\Controllers;

use App\Support\HomePage;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The reading room: the hero and its pile, About me, then a section per kind.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Home', (new HomePage)->toArray());
    }
}

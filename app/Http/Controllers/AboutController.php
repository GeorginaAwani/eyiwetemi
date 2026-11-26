<?php

namespace App\Http\Controllers;

use App\Support\AboutPage;
use Inertia\Inertia;
use Inertia\Response;

class AboutController extends Controller
{
    /**
     * About me.
     */
    public function __invoke(): Response
    {
        $about = (new AboutPage)->toArray();

        abort_if($about === null, 404);

        return Inertia::render('About', $about);
    }
}

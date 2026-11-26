<?php

namespace App\Http\Controllers;

use App\Support\InPrintPage;
use Inertia\Inertia;
use Inertia\Response;

class InPrintController extends Controller
{
    /**
     * In print: books and where to buy them, then readings (#readings opens that tab).
     */
    public function __invoke(): Response
    {
        $page = (new InPrintPage)->toArray();

        abort_if($page === null, 404);

        return Inertia::render('InPrint', $page);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\SitePageKey;
use App\Support\ContentsPage;
use Inertia\Inertia;
use Inertia\Response;

class TermsController extends Controller
{
    /**
     * Terms: her sections with contents beside them, laid out like Privacy.
     */
    public function __invoke(): Response
    {
        $props = ContentsPage::props(SitePageKey::Terms);

        abort_if($props === null, 404);

        return Inertia::render('Terms', $props);
    }
}

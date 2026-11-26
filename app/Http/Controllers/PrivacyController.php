<?php

namespace App\Http\Controllers;

use App\Enums\SitePageKey;
use App\Support\ContentsPage;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * Privacy: her sections with contents beside them.
     */
    public function __invoke(): Response
    {
        $props = ContentsPage::props(SitePageKey::Privacy);

        abort_if($props === null, 404);

        return Inertia::render('Privacy', $props);
    }
}

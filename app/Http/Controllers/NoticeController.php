<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\Response;

class NoticeController extends Controller
{
    /**
     * A reader closed the notice line. Counted only while the notice is still up; an answer either way, so the id tells nothing.
     */
    public function closed(int $notice): Response
    {
        Notice::query()->live()->whereKey($notice)->increment('closed_count');

        return response()->noContent();
    }
}

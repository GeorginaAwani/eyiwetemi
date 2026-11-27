<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\SiteSetting;
use App\Models\Work;
use App\Support\ImageStore;
use App\Support\NotePublisher;
use App\Support\NotesAdmin;
use App\Support\Prose;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The diary: her notes as drafts she saves as she types, and what she sends out of them (publish, update, schedule,
 * undo, delete), plus the mark for the hearts the desk has seen. Each note answers with its new state.
 */
class NoteController extends Controller
{
    /** Pictures in notes: no wider or taller than this, as in the writing screen. */
    private const IMAGE_SIDE = 1600;

    public function index(Request $request): InertiaResponse
    {
        $open = $request->integer('n');

        return Inertia::render('Admin/Notes', NotesAdmin::props(
            $open > 0 ? $open : null,
            (string) $request->string('w')->trim(),
        ));
    }

    /**
     * A new, empty note: a draft that tells followers when it goes out.
     */
    public function store(): JsonResponse
    {
        $note = Note::query()->create([
            'status' => PublishStatus::Draft,
            'draft_body' => Prose::empty(),
            'draft_text' => '',
            'notify_followers' => true,
        ]);

        return response()->json(['note' => NotesAdmin::note($note)], 201);
    }

    /**
     * Autosave of the draft: the words, the work it is about, the picture and whether followers hear of it.
     */
    public function update(Request $request, Note $note): JsonResponse
    {
        $data = $request->validate([
            'body' => ['sometimes', 'array'],
            'body.type' => ['required_with:body', Rule::in(['doc'])],
            'work' => ['sometimes', 'nullable', 'string', Rule::exists('works', 'slug')],
            'photo' => ['sometimes', 'nullable', 'integer', Rule::exists('media', 'id')],
            'quiet' => ['sometimes', 'boolean'],
        ]);

        $draft = ['updated_at' => now()];

        if (array_key_exists('body', $data)) {
            $body = NotesAdmin::cleanBody($request->input('body'));
            $draft += ['draft_body' => $body, 'draft_text' => Prose::text($body)];
        }

        if (array_key_exists('work', $data)) {
            $draft['draft_work_id'] = $data['work'] === null ? null : Work::query()->where('slug', $data['work'])->value('id');
        }

        if (array_key_exists('photo', $data)) {
            $draft['draft_media_id'] = $data['photo'];
        }

        if (array_key_exists('quiet', $data)) {
            $draft['notify_followers'] = ! $data['quiet'];
        }

        $note->forceFill($draft)->save();

        return response()->json(['note' => NotesAdmin::note($note->refresh())]);
    }

    /**
     * A picture in a note, stored like the pictures in essays and stories.
     */
    public function photo(Request $request, Note $note): JsonResponse
    {
        $request->validate(
            ['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360']],
            ['image.mimes' => 'That isn’t a picture we can use. Try a JPG, PNG or WebP.', 'image.max' => 'That picture is too big. Try one under 15 MB.'],
        );

        $media = ImageStore::store($request->file('image'), 'images', self::IMAGE_SIDE, $request->user(), 'image');
        $note->forceFill(['draft_media_id' => $media->id, 'updated_at' => now()])->save();

        return response()->json(['note' => NotesAdmin::note($note->refresh())], 201);
    }

    /**
     * Sends the draft out now, or updates what is out.
     */
    public function publish(Note $note): JsonResponse
    {
        $this->refuseIfEmpty($note);
        NotePublisher::publish($note);

        return response()->json(['note' => NotesAdmin::note($note->refresh())]);
    }

    /**
     * Sends the draft out at a day and time (WAT).
     */
    public function schedule(Request $request, Note $note): JsonResponse
    {
        abort_if($note->status === PublishStatus::Published, 422);

        $data = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['day'].' '.$data['time'], (string) config('eyiwetemi.timezone'));

        if ($at->lte(now())) {
            throw ValidationException::withMessages(['day' => 'Pick a time that hasn’t passed.']);
        }

        $this->refuseIfEmpty($note);
        NotePublisher::schedule($note, $at->utc());

        return response()->json(['note' => NotesAdmin::note($note->refresh())]);
    }

    public function unschedule(Note $note): JsonResponse
    {
        if ($note->status === PublishStatus::Scheduled) {
            NotePublisher::unschedule($note);
        }

        return response()->json(['note' => NotesAdmin::note($note->refresh())]);
    }

    /**
     * Takes a note that went out in the last two minutes back to a draft.
     */
    public function undo(Note $note): JsonResponse
    {
        if (! NotePublisher::canUndo($note)) {
            throw ValidationException::withMessages(['undo' => 'Only a note sent in the last '.NotePublisher::UNDO_MINUTES.' minutes can be taken back.']);
        }

        NotePublisher::undo($note);

        return response()->json(['note' => NotesAdmin::note($note->refresh())]);
    }

    public function destroy(Note $note): Response
    {
        DB::transaction(function () use ($note): void {
            $note->likes()->delete();
            $note->delete();
        });

        return response()->noContent();
    }

    /**
     * The desk has looked at the hearts now: the count of new ones starts again from here.
     */
    public function seen(): Response
    {
        SiteSetting::put('desk.hearts_seen_at', now()->toIso8601String());

        return response()->noContent();
    }

    private function refuseIfEmpty(Note $note): void
    {
        if (NotePublisher::isEmpty($note)) {
            throw ValidationException::withMessages(['body' => 'There’s nothing to publish yet.']);
        }
    }
}

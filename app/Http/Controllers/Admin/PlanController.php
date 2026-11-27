<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResearchKind;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CharacterLink;
use App\Models\Media;
use App\Models\PlotPoint;
use App\Models\PlotThread;
use App\Models\ResearchItem;
use App\Models\ResearchTie;
use App\Models\User;
use App\Models\Work;
use App\Models\WorldEntry;
use App\Models\WorldGroup;
use App\Support\ImageStore;
use App\Support\PlanPage;
use App\Support\SafeFetch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Plan tab's edits: characters and their links, world groups and entries, plot threads and their points, research
 * and the plan's notes. Every change answers with the whole plan, so the tab redraws from one source.
 */
class PlanController extends Controller
{
    /** The only tags a plan's notes keep; every attribute goes. */
    private const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'li'];

    /** Longest notes text kept. */
    private const MAX_HTML = 20000;

    /** Uploaded research files live under this folder; only those are deleted with their item. */
    private const RESEARCH_FOLDER = 'research';

    /** Pictures in research are made no bigger than this on their longest side. */
    private const RESEARCH_IMAGE_SIDE = 2000;

    public function storeCharacter(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'typedAs' => ['nullable', 'string', 'max:120'],
        ]);
        $name = trim($data['name']);

        Character::query()->create([
            'work_id' => $work->id,
            'name' => $name,
            'typed_as' => $this->nullable($data['typedAs'] ?? null) ?? Str::ascii($name),
            'position' => (int) $work->characters()->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    public function updateCharacter(Request $request, Work $work, Character $character): JsonResponse
    {
        abort_unless($character->work_id === $work->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'typedAs' => ['sometimes', 'nullable', 'string', 'max:120'],
            'aliases' => ['sometimes', 'nullable', 'array', 'max:10'],
            'aliases.*' => ['nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HTML],
        ]);

        if (array_key_exists('name', $data)) {
            $character->name = trim($data['name']);
        }

        if (array_key_exists('typedAs', $data)) {
            $character->typed_as = $this->nullable($data['typedAs']);
        }

        if (array_key_exists('aliases', $data)) {
            $character->aliases = $this->aliases($data['aliases'] ?? []);
        }

        if (array_key_exists('role', $data)) {
            $character->role = $this->nullable($data['role']);
        }

        if (array_key_exists('notes', $data)) {
            $character->notes = ['html' => $this->html($data['notes'] ?? '')];
        }

        $character->save();

        return $this->plan($work);
    }

    /**
     * Gone with her research ties and her points of view (the chapters keep their place, without a narrator).
     */
    public function destroyCharacter(Work $work, Character $character): JsonResponse
    {
        abort_unless($character->work_id === $work->id, 404);

        DB::transaction(function () use ($character): void {
            $this->forgetTies('c', $character->id);
            $character->delete();
        });

        return $this->plan($work);
    }

    /**
     * A link is kept both ways, with the same label; a pair that is already linked is left as it is.
     */
    public function storeLink(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'character' => ['required', 'integer', Rule::exists('characters', 'id')->where('work_id', $work->id)],
            'to' => ['required', 'integer', 'different:character', Rule::exists('characters', 'id')->where('work_id', $work->id)],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $from = (int) $data['character'];
        $to = (int) $data['to'];
        $label = $this->nullable($data['label'] ?? null);

        DB::transaction(function () use ($from, $to, $label): void {
            foreach ([[$from, $to], [$to, $from]] as [$character, $other]) {
                CharacterLink::query()->firstOrCreate(
                    ['character_id' => $character, 'other_character_id' => $other],
                    ['label' => $label],
                );
            }
        });

        return $this->plan($work, 201);
    }

    public function updateLink(Request $request, Work $work, CharacterLink $link): JsonResponse
    {
        $link->loadMissing('character:id,work_id');
        abort_unless($link->character?->work_id === $work->id, 404);

        $data = $request->validate(['label' => ['present', 'nullable', 'string', 'max:80']]);

        $this->bothWays($link)->update(['label' => $this->nullable($data['label'])]);

        return $this->plan($work);
    }

    public function destroyLink(Work $work, CharacterLink $link): JsonResponse
    {
        $link->loadMissing('character:id,work_id');
        abort_unless($link->character?->work_id === $work->id, 404);

        $this->bothWays($link)->delete();

        return $this->plan($work);
    }

    public function storeGroup(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        WorldGroup::query()->create([
            'work_id' => $work->id,
            'name' => trim($data['name']),
            'position' => (int) $work->worldGroups()->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    public function updateGroup(Request $request, Work $work, WorldGroup $group): JsonResponse
    {
        abort_unless($group->work_id === $work->id, 404);

        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $group->update(['name' => trim($data['name'])]);

        return $this->plan($work);
    }

    /**
     * The group's entries move to the chosen group (or the first one left), after its entries.
     */
    public function destroyGroup(Request $request, Work $work, WorldGroup $group): JsonResponse
    {
        abort_unless($group->work_id === $work->id, 404);

        $data = $request->validate(['move_to' => ['nullable', 'integer']]);

        DB::transaction(function () use ($work, $group, $data): void {
            $others = $work->worldGroups()->whereKeyNot($group->id)->orderBy('position')->orderBy('id')->get();

            if ($others->isEmpty()) {
                throw ValidationException::withMessages(['group' => 'Keep at least one group.']);
            }

            $target = isset($data['move_to']) ? $others->firstWhere('id', (int) $data['move_to']) : $others->first();

            if ($target === null) {
                throw ValidationException::withMessages(['move_to' => 'Pick another group in this work.']);
            }

            $position = (int) WorldEntry::query()->where('world_group_id', $target->id)->max('position');

            foreach (WorldEntry::query()->where('world_group_id', $group->id)->orderBy('position')->orderBy('id')->get() as $entry) {
                $entry->forceFill(['world_group_id' => $target->id, 'position' => ++$position])->save();
            }

            $group->delete();
        });

        return $this->plan($work);
    }

    public function storeEntry(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'integer', Rule::exists('world_groups', 'id')->where('work_id', $work->id)],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $group = (int) $data['group'];
        $name = trim($data['name']);

        WorldEntry::query()->create([
            'world_group_id' => $group,
            'name' => $name,
            'typed_as' => Str::ascii($name),
            'position' => (int) WorldEntry::query()->where('world_group_id', $group)->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    public function updateEntry(Request $request, Work $work, WorldEntry $entry): JsonResponse
    {
        $entry->loadMissing('group:id,work_id');
        abort_unless($entry->group?->work_id === $work->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'typedAs' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HTML],
            'group' => ['sometimes', 'required', 'integer', Rule::exists('world_groups', 'id')->where('work_id', $work->id)],
        ]);

        if (array_key_exists('name', $data)) {
            $entry->name = trim($data['name']);
        }

        if (array_key_exists('typedAs', $data)) {
            $entry->typed_as = $this->nullable($data['typedAs']);
        }

        if (array_key_exists('description', $data)) {
            $entry->description = $this->nullable($data['description']);
        }

        if (array_key_exists('notes', $data)) {
            $entry->notes = ['html' => $this->html($data['notes'] ?? '')];
        }

        if (array_key_exists('group', $data) && (int) $data['group'] !== $entry->world_group_id) {
            $entry->world_group_id = (int) $data['group'];
            $entry->position = (int) WorldEntry::query()->where('world_group_id', $entry->world_group_id)->max('position') + 1;
        }

        $entry->save();

        return $this->plan($work);
    }

    public function destroyEntry(Work $work, WorldEntry $entry): JsonResponse
    {
        $entry->loadMissing('group:id,work_id');
        abort_unless($entry->group?->work_id === $work->id, 404);

        DB::transaction(function () use ($entry): void {
            $this->forgetTies('e', $entry->id);
            $entry->delete();
        });

        return $this->plan($work);
    }

    /**
     * Each new thread takes the next colour in turn.
     */
    public function storeThread(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        PlotThread::query()->create([
            'work_id' => $work->id,
            'name' => trim($data['name']),
            'colour' => $work->plotThreads()->count() % 6,
            'position' => (int) $work->plotThreads()->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    public function updateThread(Request $request, Work $work, PlotThread $thread): JsonResponse
    {
        abort_unless($thread->work_id === $work->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'colour' => ['sometimes', 'integer', 'between:0,5'],
        ]);

        if (array_key_exists('name', $data)) {
            $thread->name = trim($data['name']);
        }

        if (array_key_exists('colour', $data)) {
            $thread->colour = (int) $data['colour'];
        }

        $thread->save();

        return $this->plan($work);
    }

    /**
     * Its points move to the first thread left; the last thread stays.
     */
    public function destroyThread(Work $work, PlotThread $thread): JsonResponse
    {
        abort_unless($thread->work_id === $work->id, 404);

        DB::transaction(function () use ($work, $thread): void {
            $other = $work->plotThreads()->whereKeyNot($thread->id)->orderBy('position')->orderBy('id')->first();

            if ($other === null) {
                throw ValidationException::withMessages(['thread' => 'Keep at least one thread.']);
            }

            PlotPoint::query()->where('plot_thread_id', $thread->id)->update(['plot_thread_id' => $other->id]);
            $this->forgetTies('t', $thread->id);
            $thread->delete();
        });

        return $this->plan($work);
    }

    public function storePoint(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate([
            'thread' => ['required', 'integer', Rule::exists('plot_threads', 'id')->where('work_id', $work->id)],
            'item' => ['nullable', 'integer', Rule::exists('work_items', 'id')->where('work_id', $work->id)],
            'text' => ['required', 'string', 'max:500'],
        ]);

        PlotPoint::query()->create([
            'plot_thread_id' => (int) $data['thread'],
            'work_item_id' => isset($data['item']) ? (int) $data['item'] : null,
            'text' => trim($data['text']),
            'position' => (int) PlotPoint::query()->whereIn('plot_thread_id', $work->plotThreads()->select('id'))->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    public function updatePoint(Request $request, Work $work, PlotPoint $point): JsonResponse
    {
        $point->loadMissing('thread:id,work_id');
        abort_unless($point->thread?->work_id === $work->id, 404);

        $data = $request->validate([
            'thread' => ['sometimes', 'required', 'integer', Rule::exists('plot_threads', 'id')->where('work_id', $work->id)],
            'item' => ['sometimes', 'nullable', 'integer', Rule::exists('work_items', 'id')->where('work_id', $work->id)],
            'text' => ['sometimes', 'required', 'string', 'max:500'],
        ]);

        if (array_key_exists('thread', $data)) {
            $point->plot_thread_id = (int) $data['thread'];
        }

        if (array_key_exists('item', $data)) {
            $point->work_item_id = isset($data['item']) ? (int) $data['item'] : null;
        }

        if (array_key_exists('text', $data)) {
            $point->text = trim($data['text']);
        }

        $point->save();

        return $this->plan($work);
    }

    public function destroyPoint(Work $work, PlotPoint $point): JsonResponse
    {
        $point->loadMissing('thread:id,work_id');
        abort_unless($point->thread?->work_id === $work->id, 404);

        $point->delete();

        return $this->plan($work);
    }

    /**
     * A link keeps its page title (or its host when the page can't be read); a picture or PDF is kept as it is.
     */
    public function storeResearch(Request $request, Work $work): JsonResponse
    {
        $kind = (string) $request->input('kind');
        $isUpload = in_array($kind, ['img', 'file'], true);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['link', 'note', 'img', 'file'])],
            'url' => [$kind === 'link' ? 'required' : 'nullable', 'url:http,https', 'max:2048'],
            'title' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:5000'],
            'file' => [$isUpload ? 'required' : 'nullable', 'file', $kind === 'img' ? 'mimes:jpg,jpeg,png,webp' : 'mimes:pdf', 'max:'.($kind === 'img' ? 15360 : 25600)],
        ], [
            'file.mimes' => 'That isn’t a file we can keep. Pictures go in as JPG, PNG or WebP; papers as PDF.',
            'file.max' => 'That file is too big. Pictures can be up to 15 MB, PDFs up to 25 MB.',
        ]);

        $title = $this->nullable($data['title'] ?? null);
        $url = null;
        $media = null;

        if ($kind === 'link') {
            $url = $data['url'];
            $title ??= $this->linkTitle($url);
        }

        if ($isUpload) {
            $file = $request->file('file');
            $media = $kind === 'img'
                ? ImageStore::store($file, self::RESEARCH_FOLDER, self::RESEARCH_IMAGE_SIDE, $request->user(), 'file')
                : $this->storePdf($file, $request->user());
            $title ??= Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 300, '');
        }

        ResearchItem::query()->create([
            'work_id' => $work->id,
            'kind' => match ($kind) {
                'link' => ResearchKind::Link,
                'note' => ResearchKind::Note,
                'img' => ResearchKind::Image,
                default => ResearchKind::File,
            },
            'title' => $title,
            'url' => $url,
            'note' => $this->nullable($data['note'] ?? null),
            'media_id' => $media?->id,
            'position' => (int) $work->researchItems()->max('position') + 1,
        ]);

        return $this->plan($work, 201);
    }

    /**
     * Ties are replaced as a whole when sent; the ones that point outside this work are refused before anything changes.
     */
    public function updateResearch(Request $request, Work $work, ResearchItem $research): JsonResponse
    {
        abort_unless($research->work_id === $work->id, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'url' => ['sometimes', 'required', 'url:http,https', 'max:2048'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'ties' => ['sometimes', 'array', 'max:200'],
            'ties.*' => ['string', Rule::regex('/^(c|e|t|i):\d+$/')],
        ]);

        $targets = array_key_exists('ties', $data) ? $this->tieTargets($work, $data['ties']) : null;

        DB::transaction(function () use ($research, $data, $targets): void {
            if (array_key_exists('title', $data)) {
                $research->title = $this->nullable($data['title']);
            }

            if (array_key_exists('url', $data)) {
                $research->url = $data['url'];
            }

            if (array_key_exists('note', $data)) {
                $research->note = $this->nullable($data['note']);
            }

            $research->save();

            if ($targets !== null) {
                $research->ties()->delete();

                foreach ($targets as $target) {
                    $research->ties()->create($target);
                }
            }
        });

        return $this->plan($work);
    }

    /**
     * An uploaded picture or PDF goes with its item; a link or note has nothing else to delete.
     */
    public function destroyResearch(Work $work, ResearchItem $research): JsonResponse
    {
        abort_unless($research->work_id === $work->id, 404);

        $research->loadMissing('media');
        $upload = $research->media && str_starts_with($research->media->path, self::RESEARCH_FOLDER.'/') ? $research->media : null;

        DB::transaction(function () use ($research, $upload): void {
            $research->delete();
            $upload?->delete();
        });

        if ($upload !== null) {
            Storage::disk($upload->disk)->delete($upload->path);
        }

        return $this->plan($work);
    }

    public function updateNotes(Request $request, Work $work): JsonResponse
    {
        $data = $request->validate(['html' => ['present', 'nullable', 'string', 'max:'.self::MAX_HTML]]);

        $work->forceFill(['plan_notes' => ['html' => $this->html($data['html'] ?? '')]])->save();

        return $this->plan($work);
    }

    private function plan(Work $work, int $status = 200): JsonResponse
    {
        return response()->json(['plan' => PlanPage::props($work)], $status);
    }

    /**
     * Both directions of one link: this row, and the row that points back.
     *
     * @return Builder<CharacterLink>
     */
    private function bothWays(CharacterLink $link): Builder
    {
        return CharacterLink::query()
            ->where(fn (Builder $query) => $query->where('character_id', $link->character_id)->where('other_character_id', $link->other_character_id))
            ->orWhere(fn (Builder $query) => $query->where('character_id', $link->other_character_id)->where('other_character_id', $link->character_id));
    }

    /**
     * Research ties as rows, each id checked to belong to this work.
     *
     * @param  list<string>  $ties
     * @return list<array{tieable_type: string, tieable_id: int}>
     */
    private function tieTargets(Work $work, array $ties): array
    {
        $ids = static fn ($query): array => $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $owned = [
            'c' => $ids($work->characters()),
            'e' => $ids(WorldEntry::query()->whereIn('world_group_id', $work->worldGroups()->select('id'))),
            't' => $ids($work->plotThreads()),
            'i' => $ids($work->items()),
        ];
        $types = PlanPage::tieTypes();
        $targets = [];

        foreach (array_unique($ties) as $tie) {
            [$prefix, $id] = explode(':', $tie, 2);

            if (! in_array((int) $id, $owned[$prefix], true)) {
                throw ValidationException::withMessages(['ties' => 'One of those isn’t in this work.']);
            }

            $targets[] = ['tieable_type' => $types[$prefix], 'tieable_id' => (int) $id];
        }

        return $targets;
    }

    /**
     * Ties that point at a character, entry or thread that is being deleted.
     */
    private function forgetTies(string $prefix, int $id): void
    {
        ResearchTie::query()
            ->where('tieable_type', PlanPage::tieTypes()[$prefix])
            ->where('tieable_id', $id)
            ->delete();
    }

    /**
     * The page's own title, or its host when the page can't be fetched or has no title.
     */
    private function linkTitle(string $url): string
    {
        try {
            $response = SafeFetch::get($url, 4);

            if ($response->successful() && preg_match('#<title[^>]*>(.*?)</title>#is', $response->body(), $matches) === 1) {
                $title = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                if ($title !== '') {
                    return mb_substr($title, 0, 300);
                }
            }
        } catch (Throwable) {
        }

        return mb_substr((string) (parse_url($url, PHP_URL_HOST) ?: $url), 0, 300);
    }

    /**
     * A PDF kept as it was sent, under the research folder on the media disk.
     */
    private function storePdf(UploadedFile $file, User $by): Media
    {
        $disk = (string) config('eyiwetemi.media_disk');
        $path = Storage::disk($disk)->putFileAs(self::RESEARCH_FOLDER, $file, Str::uuid().'.pdf', 'public');

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'That file couldn’t be saved. Try again.']);
        }

        return Media::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => 'application/pdf',
            'size' => $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * Plain text or null: blank means nothing.
     */
    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Trimmed, unique, non-blank aliases; none at all is stored as null.
     *
     * @param  array<int, mixed>  $aliases
     * @return list<string>|null
     */
    private function aliases(array $aliases): ?array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (mixed $alias): string => trim((string) $alias), $aliases),
            fn (string $alias): bool => $alias !== '',
        )));

        return $names === [] ? null : $names;
    }

    /**
     * Notes html, cleaned: only the plan's own tags, with no attributes. Scripts and styles go with their contents,
     * comments go, and text is kept as text (a stray '<' is escaped, never read as a tag).
     */
    private function html(?string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?(?:</\1\s*>|\z)#is', '', (string) $html);
        $html = (string) preg_replace('/<!--.*?(?:-->|\z)/s', '', $html);

        $clean = '';

        foreach (preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('#^<(/?)([a-zA-Z][a-zA-Z0-9]*)(?=[\s/>])[^>]*>$#', $token, $match) === 1) {
                if (in_array(strtolower($match[2]), self::ALLOWED_TAGS, true)) {
                    $clean .= '<'.$match[1].strtolower($match[2]).'>';
                }

                continue;
            }

            $clean .= htmlspecialchars(html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        }

        if (mb_strlen($clean) > self::MAX_HTML) {
            $clean = (string) preg_replace('/<[^>]*$/', '', mb_substr($clean, 0, self::MAX_HTML));
        }

        return $clean;
    }
}

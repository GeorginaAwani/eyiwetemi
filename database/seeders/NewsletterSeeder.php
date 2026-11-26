<?php

namespace Database\Seeders;

use App\Enums\SendStatus;
use App\Models\NewsletterSend;
use App\Models\Note;
use App\Models\Subscriber;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NewsletterSeeder extends Seeder
{
    /** Subscribers in total: the prototype's named list plus generated addresses, all on example.com. */
    private const SUBSCRIBER_TOTAL = 40;

    private const FIRST_NAMES = [
        'amaka', 'bisi', 'chidi', 'dayo', 'ebi', 'femi', 'gbenga', 'hauwa', 'ije', 'jide', 'kunle', 'lola', 'musa',
        'nneka', 'osas', 'pere', 'remi', 'sade', 'tari', 'uche', 'vera', 'wale', 'yemi', 'zainab', 'ada', 'tobi',
        'ese', 'onome', 'kelechi', 'funmi',
    ];

    private const LAST_PARTS = ['', '.o', '_reads', '.a', '23', '.writes', '.k', '.n', '99', '.e', '_b', '.m'];

    private const DOMAINS = ['gmail.com', 'gmail.com', 'gmail.com', 'yahoo.com', 'outlook.com', 'icloud.com', 'hotmail.com', 'proton.me'];

    /** Kind sets in the order the prototype cycles through them. */
    private const KIND_SETS = [
        'Everything', 'Everything', 'Everything', 'Novels', 'Novels, Notes', 'Poetry', 'Short stories, Poetry',
        'Novels, Short stories', 'Essays', 'Poetry, Notes', 'Novels, Short stories, Essays',
    ];

    /**
     * What each past send carried, by send date. A work item is [work slug, chapter key], where "body" is a single
     * piece's text; "note" is the diary note with that date.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const SEND_ITEMS = [
        '22 Nov' => [['paper-boats', 'c31'], ['harmattan', 'body']],
        '15 Nov' => [['paper-boats', 'c30']],
        '8 Nov' => [['on-leaving-home-twice', 'body']],
        '25 Oct' => [['paper-boats', 'c28'], ['night-bus', 'body']],
        '18 Oct' => [['midnight-poems', 'c11']],
        '11 Oct' => [['the-case-for-rereading', 'body']],
        '4 Oct' => [['paper-boats', 'c25']],
    ];

    public function run(): void
    {
        $this->subscribers();
        $this->sends();
        $this->plannedSend();
    }

    private function subscribers(): void
    {
        $rows = SeedData::SUBS_EXPLICIT;
        $seen = array_map(fn (array $row): string => $row[0], array_merge(SeedData::SUBS_EXPLICIT, SeedData::SUBS_EXTRA));
        $index = 0;

        while (count($rows) + count(SeedData::SUBS_EXTRA) < self::SUBSCRIBER_TOTAL) {
            $email = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)]
                .self::LAST_PARTS[intdiv($index, count(self::FIRST_NAMES)) % count(self::LAST_PARTS)]
                .($index < 360 ? '' : (string) ($index % 97))
                .'@'.self::DOMAINS[($index * 3) % count(self::DOMAINS)];
            $index++;

            if (in_array($email, $seen, true)) {
                continue;
            }

            $seen[] = $email;
            $count = count($rows);
            $rows[] = [
                $email,
                self::KIND_SETS[($count * 7) % count(self::KIND_SETS)],
                max(1, 16 - intdiv($count, 6)).' Nov',
                0,
            ];
        }

        foreach (array_merge($rows, SeedData::SUBS_EXTRA) as [$email, $kinds, $joined, $unconfirmed]) {
            $confirmed = $unconfirmed === 0;
            $joinedAt = Carbon::createFromFormat('j M Y H:i', "{$joined} 2026 10:00", 'Africa/Lagos')->utc();

            Subscriber::forceCreate([
                'email' => Str::before($email, '@').'@example.com',
                'kinds' => $this->kinds($kinds),
                'manage_token' => Str::random(64),
                'confirm_token_hash' => $confirmed ? null : hash('sha256', Str::random(64)),
                'confirmed_at' => $confirmed ? $joinedAt : null,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function kinds(string $label): array
    {
        if ($label === 'Everything') {
            return ['novels', 'stories', 'poems', 'essays', 'notes'];
        }

        $map = [
            'Novels' => 'novels',
            'Short stories' => 'stories',
            'Poetry' => 'poems',
            'Essays' => 'essays',
            'Notes' => 'notes',
        ];

        return array_values(array_map(fn (string $name): string => $map[$name], explode(', ', $label)));
    }

    /**
     * The prototype's sent history: sends go out on Sundays at 09:00 WAT.
     */
    private function sends(): void
    {
        foreach (SeedData::SENT as $rows) {
            foreach ($rows as [$date, , $sent, $bounced, $stopped, $status]) {
                $scheduledFor = Carbon::createFromFormat('j M Y H:i', "{$date} 2026 09:00", 'Africa/Lagos')->utc();
                $sendStatus = $this->sendStatus($status);

                $send = NewsletterSend::forceCreate([
                    'scheduled_for' => $scheduledFor,
                    'status' => $sendStatus,
                    'intro_lines' => null,
                    'sent_at' => $sendStatus === SendStatus::Sent ? $scheduledFor : null,
                    'sent_count' => $sent,
                    'bounced_count' => $bounced,
                    'stopped_count' => $stopped,
                ]);

                $this->attachItems($send, $this->sendItems($date));
            }
        }
    }

    private function plannedSend(): void
    {
        $send = NewsletterSend::forceCreate([
            'scheduled_for' => Carbon::createFromFormat('Y-m-d H:i', '2026-11-29 09:00', 'Africa/Lagos')->utc(),
            'status' => SendStatus::Planned,
            'intro_lines' => null,
            'sent_at' => null,
            'sent_count' => 0,
            'bounced_count' => 0,
            'stopped_count' => 0,
        ]);

        $scheduledChapter = $this->chapter(Work::query()->where('slug', 'paper-boats')->firstOrFail(), 36);
        $scheduledNote = Note::query()->where('status', 'scheduled')->firstOrFail();

        $this->attachItems($send, [$scheduledChapter, $scheduledNote]);
    }

    /**
     * @param  list<WorkItem|Note>  $items
     */
    private function attachItems(NewsletterSend $send, array $items): void
    {
        foreach ($items as $position => $item) {
            $send->items()->forceCreate([
                'item_type' => $item->getMorphClass(),
                'item_id' => $item->getKey(),
                'left_out' => false,
                'position' => $position + 1,
            ]);
        }
    }

    /**
     * @return list<WorkItem>
     */
    private function sendItems(string $date): array
    {
        $items = [];

        foreach (self::SEND_ITEMS[$date] ?? [] as [$slug, $key]) {
            $work = Work::query()->where('slug', $slug)->firstOrFail();

            $items[] = $key === 'body'
                ? $work->items()->where('type', '!=', 'part')->firstOrFail()
                : $this->chapter($work, (int) substr($key, 1));
        }

        return $items;
    }

    private function chapter(Work $work, int $number): WorkItem
    {
        return $work->items()->where('type', '!=', 'part')->get()->values()[$number - 1];
    }

    private function sendStatus(string $prototypeStatus): SendStatus
    {
        return match ($prototypeStatus) {
            'sent' => SendStatus::Sent,
            'held' => SendStatus::Held,
            'skip' => SendStatus::Skipped,
        };
    }
}

import { Link, router } from '@inertiajs/react';
import { CSSProperties, ReactNode, useEffect, useRef, useState } from 'react';
import { FieldError, KSelect, loading, SavedNote, SaveState, Unfold, wait } from '../../Components/admin/Controls';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { BackSmall, Book15, BoxTick, Chevron, Close, FACT_ICONS, Globe, Plus, Ring } from '../../Components/admin/Icons';
import NameField from '../../Components/admin/NameField';
import { COLLECTION_KINDS, CollectionChoice, KINDS, KKind, SeriesChoice } from '../../Components/admin/NewWork';
import { bloomStyle } from '../../Components/site/Bloom';
import { INVITED, openInvite, Person as Invited, PI } from '../../Components/admin/People';
import ChaptersTab, { Contents } from '../../Components/admin/ChaptersTab';
import PlanTab, { Plan } from '../../Components/admin/PlanTab';
import AdminLayout from '../../Layouts/AdminLayout';
import { heading } from '../../lib/headings';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Tab = 'chapters' | 'plan' | 'settings';
type Reviewer = { kind: 'user' | 'invite'; id: number; name: string; seed: string; state: 'on' | 'invited' | 'expired' };
type Person = { id: number; name: string; email: string; seed: string };
type Settings = {
    slug: string;
    title: string;
    kind: KKind;
    collectionKind: string;
    description: string;
    cover: CoverData;
    hasCover: boolean;
    genres: string[];
    status: string;
    visibility: 'private' | 'reviewers' | 'public';
    seriesId: number | null;
    seriesPosition: number;
    collectionId: number | null;
    headingStyle: string;
    showChapterTitles: boolean;
    showPov: boolean;
    partWord: string;
    partStyle: string;
    showPartNumbers: boolean;
    sceneBreak: string;
    contentNotes: string;
    published: { live: number; drafts: number; ever: boolean };
    writeHref: string;
    inPrint: { coming: boolean; href: string } | null;
};
type Choices = { series: SeriesChoice[]; collections: CollectionChoice[]; genres: string[]; reviewers: Reviewer[]; people: Person[] };
type Saved = { slug: string; href: string; collectionId: number | null; seriesId: number | null; seriesPosition: number | null; collections: CollectionChoice[]; reviewers: Reviewer[] };

/** Suggested genres, as in the prototype; her own become suggestions once used. */
const GENRES = ['Literary fiction', 'Historical fiction', 'Romance', 'Fantasy', 'Coming of age', 'Mystery', 'Thriller', 'Science fiction', 'Horror'];
const STATUSES: [string, string][] = [
    ['in_progress', 'In progress'],
    ['finished', 'Finished'],
    ['coming_soon', 'Coming soon'],
];
const STYLES = ['chapter_word', 'chapter_word_lower', 'chapter_number', 'chapter_roman', 'word', 'number'];
const PART_WORDS = ['Part', 'Book', 'Volume', 'Act'];
const BREAKS: [string, string, ReactNode][] = [
    ['line_dot', 'Line and dot', <span className="sbp"><i /><b /><i /></span>],
    ['asterisks', 'Three asterisks', <>*&nbsp; *&nbsp; *</>],
    ['asterism', 'Asterism', <>⁂</>],
    ['short_line', 'A short line', <span className="sbp"><i /></span>],
    ['space', 'Space only', <small>Space</small>],
];
const VIS: ['private' | 'reviewers' | 'public', string, string, string][] = [
    ['private', 'lock', 'Private', 'Only you'],
    ['reviewers', 'people', 'Reviewers', 'Only your reviewers'],
    ['public', 'globe', 'Public', 'Everyone, published chapters only'],
];
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** The names of people losing access, as a sentence: "Adaeze, Ruth and Chioma". */
const names = (list: Reviewer[]) => {
    const first = list.map((r) => r.name.split(' ')[0]);

    return first.length > 1 ? first.slice(0, -1).join(', ') + ' and ' + first[first.length - 1] : first[0];
};

/**
 * A work's page: its cover and title over Chapters, Plan and Settings. Settings save as she goes ("Saving…", "Saved");
 * a title another work has, or no title, is "Not saved" until it's fixed.
 */
export default function Work({ tab: firstTab, work: initial, choices, contents, plan }: { tab: Tab; work: Settings; choices: Choices; contents: Contents | null; plan: Plan | null }) {
    const [w, setW] = useState(initial);
    const [tab, setTab] = useState<Tab>(firstTab);
    const [save, setSave] = useState<SaveState>('saved');
    const [title, setTitle] = useState(initial.title);
    const [titleError, setTitleError] = useState('');
    const [series, setSeries] = useState(choices.series);
    const [collections, setCollections] = useState(choices.collections);
    const [reviewers, setReviewers] = useState(choices.reviewers);
    const slug = useRef(initial.slug);
    const timers = useRef<Record<string, number>>({});
    const inFlight = useRef(0);
    const panel = useRef<HTMLElement>(null);

    const single = w.kind === 'story' || w.kind === 'poem' || w.kind === 'essay';
    const novel = w.kind === 'novel';
    const unit = w.kind === 'collection' ? ({ poem: 'Poems', essay: 'Essays' } as Record<string, string>)[w.collectionKind] ?? 'Stories' : single ? 'Writing' : 'Chapters';
    const hasPlan = w.kind !== 'poem';

    /* ---------- saving ---------- */
    async function patch(body: Record<string, unknown>): Promise<Saved | null> {
        inFlight.current++;
        setSave((s) => (s === 'bad' && !('title' in body) ? s : 'saving'));
        const started = Date.now();
        const { status, data } = await json<Saved & { errors: Record<string, string[]> }>('PATCH', `/works/${slug.current}`, body);
        await wait(Math.max(0, (prefersReducedMotion() ? 200 : 700) - (Date.now() - started)));
        inFlight.current--;
        if (status !== 200) {
            if (status === 422 && data.errors?.title) {
                setTitleError(data.errors.title[0]);
            }
            setSave('bad');

            return null;
        }
        if (data.slug && data.slug !== slug.current) {
            slug.current = data.slug;
            router.replace({ url: `/works/${data.slug}?tab=${tab}`, preserveScroll: true, preserveState: true });
        }
        if (data.collections) {
            setCollections(data.collections);
        }
        if (data.reviewers) {
            setReviewers(data.reviewers);
        }
        if (inFlight.current === 0) {
            setSave((s) => (s === 'bad' && titleErrorRef.current ? 'bad' : 'saved'));
        }

        return data as Saved;
    }
    const titleErrorRef = useRef('');
    titleErrorRef.current = titleError;

    const later = (key: string, ms: number, body: Record<string, unknown>) => {
        window.clearTimeout(timers.current[key]);
        timers.current[key] = window.setTimeout(() => patch(body), ms);
    };
    const set = (changes: Partial<Settings>, body?: Record<string, unknown>) => {
        setW((cur) => ({ ...cur, ...changes }));
        if (body) {
            patch(body);
        }
    };

    useEffect(() => () => Object.values(timers.current).forEach((t) => window.clearTimeout(t)), []);

    /* ---------- tabs ---------- */
    function openTab(t: Tab) {
        if (t === tab) {
            return;
        }
        if (t === 'chapters' && single) {
            router.visit(w.writeHref);

            return;
        }
        setTab(t);
        router.replace({ url: `/works/${slug.current}?tab=${t}`, preserveScroll: true, preserveState: true });
    }

    useEffect(() => {
        const el = panel.current;
        if (!el) {
            return;
        }
        el.classList.remove('settle');
        void el.offsetWidth;
        el.classList.add('settle');
    }, [tab]);

    /* ---------- title ---------- */
    function typedTitle(v: string) {
        setTitle(v);
        const clean = v.trim().replace(/\s+/g, ' ');
        window.clearTimeout(timers.current.title);
        if (!clean) {
            setTitleError('Give it a title.');
            setSave('bad');

            return;
        }
        timers.current.title = window.setTimeout(async () => {
            const { data } = await json<{ taken: { title: string } | null }>('GET', `/works/title-taken?title=${encodeURIComponent(clean)}&except=${encodeURIComponent(slug.current)}`);
            if (data.taken) {
                setTitleError(`You already have a work called ${data.taken.title}.`);
                setSave('bad');

                return;
            }
            setTitleError('');
            setW((cur) => ({ ...cur, title: clean }));
            patch({ title: clean });
        }, 450);
    }

    const shownTitle = titleError ? w.title : title.trim().replace(/\s+/g, ' ') || w.title;

    return (
        <AdminLayout section="works" title={shownTitle}>
            <Link className="k-back" href="/works">
                <BackSmall />
                Works
            </Link>
            <div className="b-wk">
                <Cover cover={w.cover} title={shownTitle} />
                <div className="tx">
                    <h1 id="wT">{shownTitle}</h1>
                    <div className="row">
                        <nav className="k-wtabs" role="tablist">
                            {(
                                [
                                    ['chapters', unit],
                                    ...(hasPlan ? [['plan', 'Plan']] : []),
                                    ['settings', 'Settings'],
                                ] as [Tab, string][]
                            ).map(([t, label]) => (
                                <a
                                    key={t}
                                    href={`/works/${slug.current}?tab=${t}`}
                                    role="tab"
                                    aria-selected={tab === t}
                                    className={tab === t ? 'on' : undefined}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        openTab(t);
                                    }}
                                >
                                    {label}
                                </a>
                            ))}
                        </nav>
                        <SavedNote state={save} hidden={tab === 'chapters'} />
                    </div>
                </div>
            </div>
            <section className={`wpan${tab === 'chapters' ? ' on' : ''}`} id="p-chapters" role="tabpanel" ref={tab === 'chapters' ? panel : undefined}>
                {contents && (
                    <ChaptersTab
                        work={{ slug: slug.current, kind: w.kind, collectionKind: w.collectionKind, headingStyle: w.headingStyle, partWord: w.partWord, partStyle: w.partStyle, showPartNumbers: w.showPartNumbers }}
                        contents={contents}
                    />
                )}
            </section>
            {hasPlan && (
                <section className={`wpan${tab === 'plan' ? ' on' : ''}`} id="p-plan" role="tabpanel" ref={tab === 'plan' ? panel : undefined}>
                    {plan && <PlanTab slug={slug.current} kind={w.kind === 'collection' ? 'collection' : w.kind} initial={plan} setSave={setSave} parts={{ word: w.partWord, style: w.partStyle, show: w.showPartNumbers }} />}
                </section>
            )}
            <section className={`wpan${tab === 'settings' ? ' on' : ''}`} id="p-settings" role="tabpanel" ref={tab === 'settings' ? panel : undefined}>
                <SettingsForm
                    w={w}
                    set={set}
                    later={later}
                    patch={patch}
                    title={title}
                    titleError={titleError}
                    typedTitle={typedTitle}
                    series={series}
                    setSeries={setSeries}
                    collections={collections}
                    reviewers={reviewers}
                    setReviewers={setReviewers}
                    choices={choices}
                    slug={slug}
                    setSave={setSave}
                />
            </section>
            {w.inPrint && tab === 'settings' && (
                <a className="k-l sp-wlink" href={w.inPrint.href}>
                    <Book15 />
                    <span>{w.inPrint.coming ? 'Coming: stores and pre-order' : 'In print: stores and prices'}</span>
                </a>
            )}
        </AdminLayout>
    );
}

type FormProps = {
    w: Settings;
    set: (changes: Partial<Settings>, body?: Record<string, unknown>) => void;
    later: (key: string, ms: number, body: Record<string, unknown>) => void;
    patch: (body: Record<string, unknown>) => Promise<Saved | null>;
    title: string;
    titleError: string;
    typedTitle: (v: string) => void;
    series: SeriesChoice[];
    setSeries: (s: SeriesChoice[]) => void;
    collections: CollectionChoice[];
    reviewers: Reviewer[];
    setReviewers: (r: Reviewer[]) => void;
    choices: Choices;
    slug: { current: string };
    setSave: (s: SaveState) => void;
};

function SettingsForm({ w, set, later, patch, title, titleError, typedTitle, series, setSeries, collections, reviewers, setReviewers, choices, slug, setSave }: FormProps) {
    const single = w.kind === 'story' || w.kind === 'poem' || w.kind === 'essay';
    const novel = w.kind === 'novel';
    const [tocOpen, setTocOpen] = useState(false);
    const [mark, setMark] = useState('ti');

    const toc: [string, string, boolean][] = [
        ['ti', 'Basics', true],
        ['co', 'Cover', true],
        ['ge', 'Genre', novel],
        ['wh', 'Who can see it', true],
        ['se', 'Series', !single],
        ['cl', 'Collection', single],
        ['hd', 'Chapter headings', novel],
        ['pt', 'Parts', novel],
        ['sb', 'Scene breaks', w.kind !== 'poem'],
        ['cn', 'Content notes', true],
    ];
    const shownToc = toc.filter(([, , on]) => on);

    /* contents: the section in view is marked; on phones the contents fold under a button */
    useEffect(() => {
        const spy = () => {
            const seen = shownToc.map(([id]) => document.getElementById('f-' + id)).filter((el): el is HTMLElement => !!el && el.getBoundingClientRect().top < window.innerHeight * 0.4);
            setMark(seen.length ? seen[seen.length - 1].id.slice(2) : 'ti');
        };
        window.addEventListener('scroll', spy, { passive: true });

        return () => window.removeEventListener('scroll', spy);
    });

    function go(id: string) {
        const el = document.getElementById('f-' + id);
        if (!el) {
            return;
        }
        el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
        setTocOpen(false);
        window.setTimeout(() => el.querySelector<HTMLElement>('input:not([type=radio]):not([type=checkbox]),textarea,.k-sbtn')?.focus({ preventScroll: true }), prefersReducedMotion() ? 0 : 500);
    }

    const tocLinks = shownToc.map(([id, label]) => (
        <a
            key={id}
            href={'#f-' + id}
            className={mark === id ? 'on' : undefined}
            onClick={(e) => {
                e.preventDefault();
                go(id);
            }}
        >
            {label}
        </a>
    ));

    return (
        <>
            <button className="b-toc-ph" type="button" aria-expanded={tocOpen} onClick={() => setTocOpen(!tocOpen)}>
                <span>{shownToc.find(([id]) => id === mark)?.[1] ?? 'Basics'}</span>
                <Chevron />
            </button>
            <div className={`tocf${tocOpen ? ' open' : ''}`}>{tocLinks}</div>
            <div className="b-set">
                <nav className="b-toc" aria-label="Settings">
                    {tocLinks}
                </nav>
                <form className="b-form" id="sf" noValidate onSubmit={(e) => e.preventDefault()}>
                    <div className="k-f" id="f-ti">
                        <label className="k-fl" htmlFor="ti">
                            Title
                        </label>
                        <span className={`k-in${titleError ? ' bad' : ''}`}>
                            <input id="ti" autoComplete="off" spellCheck={false} value={title} onChange={(e) => typedTitle(e.target.value)} />
                        </span>
                        <FieldError message={titleError} />
                    </div>

                    <div className="k-f" id="f-ki">
                        <span className="k-fl" id="l-ki">
                            Kind
                        </span>
                        <div className="k-pills" role="radiogroup" aria-labelledby="l-ki">
                            {KINDS.map(([v, label]) => (
                                <label className="k-pill" key={v}>
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={v}
                                        checked={w.kind === v}
                                        onChange={() => set({ kind: v, ...(v === 'story' || v === 'poem' || v === 'essay' ? { seriesId: null } : { collectionId: null }) }, { kind: v, collection_kind: v === 'collection' ? w.collectionKind : null })}
                                    />
                                    <span>{label}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                    <Unfold show={w.kind === 'collection'} id="kckU">
                        <div className="k-f w-of" id="f-ck">
                            <span className="k-fl" id="l-ck">
                                Of
                            </span>
                            <div className="k-pills" role="radiogroup" aria-labelledby="l-ck">
                                {COLLECTION_KINDS.map(([v, label]) => (
                                    <label className="k-pill" key={v}>
                                        <input type="radio" name="kck" value={v} checked={w.collectionKind === v} onChange={() => set({ collectionKind: v }, { collection_kind: v })} />
                                        <span>{label}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </Unfold>

                    <div className="k-f" id="f-de">
                        <label className="k-fl" htmlFor="de">
                            Description
                        </label>
                        <span className="k-in k-ta">
                            <textarea
                                id="de"
                                rows={2}
                                placeholder="A line or two for the work page"
                                value={w.description}
                                onChange={(e) => {
                                    set({ description: e.target.value });
                                    later('description', 500, { description: e.target.value });
                                }}
                            />
                        </span>
                    </div>

                    <CoverField w={w} set={set} slug={slug} setSave={setSave} />

                    {novel && <GenreField w={w} set={set} suggestions={choices.genres} />}

                    <div className="k-f" id="f-st">
                        <span className="k-fl" id="l-st">
                            Status
                        </span>
                        <div className="k-pills" role="radiogroup" aria-labelledby="l-st">
                            {STATUSES.map(([v, label]) => (
                                <label className="k-pill" key={v}>
                                    <input type="radio" name="status" value={v} checked={w.status === v} onChange={() => set({ status: v }, { status: v })} />
                                    <span>{label}</span>
                                </label>
                            ))}
                        </div>
                    </div>

                    <WhoCanSee w={w} set={set} patch={patch} reviewers={reviewers} setReviewers={setReviewers} people={choices.people} slug={slug} setSave={setSave} />

                    {!single && <SeriesField w={w} set={set} series={series} setSeries={setSeries} setSave={setSave} />}
                    {single && <CollectionField w={w} set={set} patch={patch} collections={collections} />}

                    {novel && <HeadingsField w={w} set={set} />}
                    {novel && <PartsField w={w} set={set} later={later} />}

                    {w.kind !== 'poem' && (
                        <div className="k-f" id="f-sb">
                            <span className="k-fl" id="l-sb">
                                Scene breaks
                            </span>
                            <div className="k-tiles sbt" role="radiogroup" aria-labelledby="l-sb">
                                {BREAKS.map(([v, label, look]) => (
                                    <label className="k-tile" key={v}>
                                        <input type="radio" name="sb" value={v} aria-label={label} checked={w.sceneBreak === v} onChange={() => set({ sceneBreak: v }, { scene_break: v })} />
                                        <span>{look}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="k-f" id="f-cn">
                        <label className="k-fl" htmlFor="cn">
                            Content notes
                        </label>
                        <span className="k-in">
                            <input
                                id="cn"
                                placeholder="Optional, shown before a reader starts"
                                autoComplete="off"
                                value={w.contentNotes}
                                onChange={(e) => {
                                    set({ contentNotes: e.target.value });
                                    later('content_notes', 500, { content_notes: e.target.value });
                                }}
                            />
                        </span>
                    </div>

                    <DeleteField w={w} slug={slug} />
                </form>
            </div>
        </>
    );
}

/* ---------------------------------------------------------------- cover */
function CoverField({ w, set, slug, setSave }: { w: Settings; set: FormProps['set']; slug: { current: string }; setSave: (s: SaveState) => void }) {
    const file = useRef<HTMLInputElement>(null);
    const [error, setError] = useState('');

    async function upload(f: File) {
        setError('');
        setSave('saving');
        loading(true);
        const body = new FormData();
        body.append('cover', f);
        try {
            const res = await fetch(`/works/${slug.current}/cover`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.errors?.cover?.[0] ?? 'That picture couldn’t be saved. Try again.');
                setSave('bad');

                return;
            }
            set({ cover: data.cover, hasCover: true });
            setSave('saved');
        } catch {
            setError('No connection. Try again.');
            setSave('bad');
        } finally {
            loading(false);
            if (file.current) {
                file.current.value = '';
            }
        }
    }

    async function remove() {
        setError('');
        setSave('saving');
        const { status, data } = await json<{ cover: CoverData }>('DELETE', `/works/${slug.current}/cover`);
        if (status !== 200 || !data.cover) {
            setSave('bad');

            return;
        }
        set({ cover: data.cover, hasCover: false });
        setSave('saved');
    }

    return (
        <div className="k-f" id="f-co">
            <span className="k-fl" id="l-co">
                Cover
            </span>
            <div className="k-covrow">
                <Cover cover={w.cover} title={w.title} className="k-cov big" />
                <div className="k-links">
                    <button className="k-l" type="button" onClick={() => file.current?.click()}>
                        <Plus />
                        <span>Change</span>
                    </button>
                    {w.hasCover && (
                        <button className="k-l" type="button" onClick={remove}>
                            <span>Remove</span>
                        </button>
                    )}
                </div>
                <input type="file" ref={file} accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
            </div>
            <FieldError message={error} />
        </div>
    );
}

/* ---------------------------------------------------------------- genre: up to two */
function GenreField({ w, set, suggestions }: { w: Settings; set: FormProps['set']; suggestions: string[] }) {
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [act, setAct] = useState(-1);
    const [fresh, setFresh] = useState('');
    const [leaving, setLeaving] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const norm = (t: string) => t.trim().replace(/\s+/g, ' ').toLowerCase();
    const all = [...new Set([...GENRES, ...suggestions])];
    const options = all.filter((g) => !w.genres.some((x) => norm(x) === norm(g)) && (!norm(query) || norm(g).includes(norm(query))));

    function commit(genres: string[]) {
        set({ genres }, { genres });
    }

    function add(v: string) {
        v = v.trim().replace(/\s+/g, ' ');
        if (!v || w.genres.length >= 2 || w.genres.some((x) => norm(x) === norm(v))) {
            return;
        }
        const next = [...w.genres, v];
        setQuery('');
        setFresh(v);
        commit(next);
        setOpen(false);
        if (next.length >= 2) {
            setAdding(false);
        } else {
            input.current?.focus();
        }
    }

    async function remove(t: string) {
        setLeaving(t);
        await wait(prefersReducedMotion() ? 0 : 260);
        setLeaving('');
        commit(w.genres.filter((x) => x !== t));
    }

    return (
        <div className="k-f nov" id="f-ge">
            <span className="k-fl" id="l-ge">
                Genre
            </span>
            <div className="k-chips" id="gen">
                {w.genres.map((t) => (
                    <span key={t} className={`k-chip${t === fresh ? ' hi' : ''}${t === leaving ? ' bye' : ''}`}>
                        {t}
                        <button type="button" aria-label={`Remove ${t}`} onClick={() => remove(t)}>
                            <Close size={14} width={2} />
                        </button>
                    </span>
                ))}
                {w.genres.length < 2 && (
                    <button
                        type="button"
                        className="k-l"
                        onClick={() => {
                            setAdding(true);
                            window.setTimeout(() => input.current?.focus(), 60);
                        }}
                    >
                        <Plus />
                        <span>Add genre</span>
                    </button>
                )}
            </div>
            <Unfold show={adding && w.genres.length < 2} id="genU">
                <div className={`k-select k-combo${open && options.length ? ' open' : ''}`} id="genC">
                    <span className="k-in sm">
                        <input
                            id="genI"
                            ref={input}
                            role="combobox"
                            aria-autocomplete="list"
                            aria-expanded={open && options.length > 0}
                            aria-controls="genL"
                            aria-label="Genre"
                            aria-activedescendant={act >= 0 ? `genL-o${act}` : undefined}
                            placeholder="Add a genre, then Enter"
                            autoComplete="off"
                            spellCheck={false}
                            value={query}
                            onFocus={() => setOpen(true)}
                            onChange={(e) => {
                                setQuery(e.target.value);
                                setAct(-1);
                                setOpen(true);
                            }}
                            onBlur={() => window.setTimeout(() => setOpen(false), 120)}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape') {
                                    e.stopPropagation();
                                    if (open) {
                                        setOpen(false);
                                    } else {
                                        setAdding(false);
                                    }
                                } else if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    setOpen(true);
                                    if (options.length) {
                                        setAct((act + 1) % options.length);
                                    }
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    if (options.length) {
                                        setAct(act <= 0 ? options.length - 1 : act - 1);
                                    }
                                } else if (e.key === 'Enter') {
                                    e.preventDefault();
                                    add(open && act >= 0 ? options[act] : query);
                                }
                            }}
                        />
                    </span>
                    <div className="k-lb" role="listbox" id="genL" tabIndex={-1} aria-label="Genres" onMouseDown={(e) => e.preventDefault()}>
                        {options.map((g, i) => (
                            <div key={g} role="option" id={`genL-o${i}`} aria-selected={false} className={i === act ? 'act' : undefined} onClick={() => add(g)}>
                                <span>{g}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </Unfold>
        </div>
    );
}

/* ---------------------------------------------------------------- who can see it + reviewers */
function WhoCanSee({
    w,
    set,
    patch,
    reviewers,
    setReviewers,
    people,
    slug,
    setSave,
}: {
    w: Settings;
    set: FormProps['set'];
    patch: FormProps['patch'];
    reviewers: Reviewer[];
    setReviewers: (r: Reviewer[]) => void;
    people: Person[];
    slug: { current: string };
    setSave: (s: SaveState) => void;
}) {
    const [asking, setAsking] = useState<'' | 'private' | 'public'>('');
    const [busy, setBusy] = useState(false);
    const shown = asking || w.visibility;
    const single = w.kind === 'story' || w.kind === 'poem' || w.kind === 'essay';
    const unitWord = w.kind === 'collection' ? ({ poem: 'poem', essay: 'essay' } as Record<string, string>)[w.collectionKind] ?? 'story' : 'chapter';
    const plural = (n: number) => (n === 1 ? unitWord : unitWord === 'story' ? 'stories' : unitWord + 's');

    function publicText(): string {
        const { live, drafts, ever } = w.published;
        if (single) {
            return ever ? 'Readers will see it.' : 'Readers will see it once it’s published.';
        }
        if (!live) {
            return `Nothing is published yet. Readers will see ${plural(2)} as you publish them.`;
        }

        return (
            `Readers will see ${live === 1 ? `${unitWord} 1` : `${live} published ${plural(live)}`}.` +
            (drafts ? ` ${drafts === 1 ? `One ${unitWord} stays a draft.` : `${drafts} ${plural(drafts)} stay drafts.`}` : '')
        );
    }

    function choose(v: Settings['visibility']) {
        if (v === w.visibility) {
            setAsking('');

            return;
        }
        if (v === 'private' && reviewers.length) {
            setAsking('private');
            window.setTimeout(() => document.getElementById('rvPNo')?.focus(), prefersReducedMotion() ? 0 : 200);

            return;
        }
        if (v === 'public') {
            setAsking('public');

            return;
        }
        setAsking('');
        set({ visibility: v }, { visibility: v });
    }

    async function confirm(v: 'private' | 'public') {
        setBusy(true);
        loading(true);
        const saved = await patch({ visibility: v });
        setBusy(false);
        loading(false);
        if (saved) {
            set({ visibility: v });
            if (v === 'private') {
                setReviewers([]);
            }
        }
        setAsking('');
    }

    return (
        <div className="k-f" id="f-wh">
            <span className="k-fl" id="l-wh">
                Who can see it
            </span>
            <div className="k-vis" role="radiogroup" aria-labelledby="l-wh">
                {VIS.map(([v, icon, label, note]) => (
                    <div key={v} className={`k-vrow${shown === v ? ' on' : ''}`} data-v={v}>
                        <label className="k-radio">
                            <input type="radio" name="vis" value={v} checked={(asking === 'private' ? w.visibility : shown) === v} onChange={() => choose(v)} />
                            <span className="k-dot" />
                            <span className="k-vi">{FACT_ICONS[icon]}</span>
                            <span className="k-vt">
                                <b>{label}</b>
                                <small>{note}</small>
                            </span>
                        </label>
                        {v === 'private' && (
                            <Unfold show={asking === 'private'} id="rvPU">
                                <div className="k-conf">
                                    <p>
                                        {names(reviewers)} {reviewers.length > 1 ? 'lose' : 'loses'} access. {reviewers.length > 1 ? 'Their' : 'The'} comments stay with you.
                                    </p>
                                    <div className="acts">
                                        <button className={`k-btn sm${busy ? ' busy' : ''}`} type="button" aria-busy={busy} onClick={() => confirm('private')}>
                                            <span>Make it private</span>
                                            <Ring />
                                        </button>
                                        <button className="w-act" type="button" id="rvPNo" onClick={() => setAsking('')}>
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </Unfold>
                        )}
                        {v === 'public' && (
                            <Unfold show={asking === 'public'} id="pubU">
                                <div className="k-conf">
                                    <p>{publicText()}</p>
                                    <div className="acts">
                                        <button className={`k-btn sm${busy ? ' busy' : ''}`} type="button" aria-busy={busy} onClick={() => confirm('public')}>
                                            <Globe />
                                            <span>Make it public</span>
                                            <Ring />
                                        </button>
                                        <button className="k-l" type="button" onClick={() => setAsking('')}>
                                            <span>Cancel</span>
                                        </button>
                                    </div>
                                </div>
                            </Unfold>
                        )}
                    </div>
                ))}
                <Unfold show={w.visibility !== 'private'} id="rvU">
                    <ReviewersField w={w} reviewers={reviewers} setReviewers={setReviewers} people={people} slug={slug} setSave={setSave} />
                </Unfold>
            </div>
        </div>
    );
}

function ReviewersField({
    w,
    reviewers,
    setReviewers,
    people,
    slug,
    setSave,
}: {
    w: Settings;
    reviewers: Reviewer[];
    setReviewers: (r: Reviewer[]) => void;
    people: Person[];
    slug: { current: string };
    setSave: (s: SaveState) => void;
}) {
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState('');
    const [act, setAct] = useState(0);
    const [focused, setFocused] = useState(false);
    const [leaving, setLeaving] = useState('');
    const [fresh, setFresh] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const addButton = useRef<HTMLButtonElement>(null);

    const have = new Set(reviewers.filter((r) => r.kind === 'user').map((r) => r.id));
    const q = query.trim().toLowerCase();
    const found = people.filter((p) => !have.has(p.id) && (!q || `${p.name} ${p.email}`.toLowerCase().includes(q))).slice(0, 6);
    /* a new email offers an invite (the Invite a reviewer dialog, with this work ticked) */
    const invite = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(query.trim()) && !people.some((p) => p.email.toLowerCase() === q) ? query.trim() : '';
    const options: (Person | { invite: string })[] = invite ? [...found, { invite }] : found;

    /* someone invited from here shows as Invited */
    useEffect(() => {
        const added = (e: Event) => {
            const p = (e as CustomEvent<Invited>).detail;
            if (p.works.includes(slug.current)) {
                setReviewers([...reviewers, { kind: p.kind, id: p.id, name: p.name, seed: p.seed, state: p.state }]);
            }
        };
        window.addEventListener(INVITED, added);

        return () => window.removeEventListener(INVITED, added);
    }, [reviewers]);

    useEffect(() => {
        if (adding) {
            input.current?.focus();
        }
    }, [adding]);

    async function send(method: 'POST' | 'DELETE', url: string, body?: Record<string, unknown>) {
        setSave('saving');
        const { status, data } = await json<{ reviewers: Reviewer[] }>(method, url, body);
        if (status !== 200 || !data.reviewers) {
            setSave('bad');

            return;
        }
        setReviewers(data.reviewers);
        setSave('saved');
    }

    async function pick(p: Person | { invite: string } | undefined) {
        if (!p) {
            return;
        }
        if ('invite' in p) {
            setAdding(false);
            setQuery('');
            openInvite({ email: p.invite, works: [slug.current] });

            return;
        }
        setAdding(false);
        setQuery('');
        setFresh(p.id);
        await send('POST', `/works/${slug.current}/reviewers`, { user_id: p.id });
        window.setTimeout(() => addButton.current?.focus(), 0);
    }

    async function remove(r: Reviewer) {
        setLeaving(`${r.kind}${r.id}`);
        await wait(prefersReducedMotion() ? 0 : 220);
        await send('DELETE', `/works/${slug.current}/reviewers/${r.kind}/${r.id}`);
        setLeaving('');
        window.setTimeout(() => addButton.current?.focus(), 20);
    }

    return (
        <div className="k-f rv-f" id="rvF">
            <span className="k-fl" id="rvL">
                Reviewers
            </span>
            <div className="rv-chips" id="rvC" role="group" aria-labelledby="rvL">
                {reviewers.map((r) => (
                    <span
                        key={`${r.kind}${r.id}`}
                        className={`rv-chip${r.state !== 'on' ? ' pend' : ''}${r.kind === 'user' && r.id === fresh ? ' hi' : ''}`}
                        style={leaving === `${r.kind}${r.id}` ? ({ opacity: 0, transform: 'scale(.92)' } as CSSProperties) : undefined}
                    >
                        <span className="cv" style={{ ...bloomStyle(r.seed, 28) }} aria-hidden="true" />
                        <span>{r.name}</span>
                        {r.state !== 'on' && <small>{r.state === 'expired' ? 'Expired' : 'Invited'}</small>}
                        <button className="ad-ic" type="button" aria-label={`Remove ${r.name} from this work`} onClick={() => remove(r)}>
                            <Close size={14} />
                        </button>
                    </span>
                ))}
                <span className="rv-add" id="rvA">
                    {adding ? (
                        <span className={`k-select k-combo rv-combo${focused && options.length ? ' open' : ''}`}>
                            <span className="k-in sm">
                                <input
                                    id="rvI"
                                    ref={input}
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-expanded={focused && options.length > 0}
                                    aria-controls="rvLB"
                                    aria-activedescendant={options.length ? `rvO${act}` : undefined}
                                    aria-label="Add a reviewer"
                                    placeholder="Name or email"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={query}
                                    onFocus={() => setFocused(true)}
                                    onChange={(e) => {
                                        setQuery(e.target.value);
                                        setAct(0);
                                    }}
                                    onBlur={() =>
                                        window.setTimeout(() => {
                                            setFocused(false);
                                            if (document.activeElement !== input.current && !query) {
                                                setAdding(false);
                                            }
                                        }, 150)
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'ArrowDown') {
                                            e.preventDefault();
                                            setAct(Math.min(options.length - 1, act + 1));
                                        } else if (e.key === 'ArrowUp') {
                                            e.preventDefault();
                                            setAct(Math.max(0, act - 1));
                                        } else if (e.key === 'Enter') {
                                            e.preventDefault();
                                            pick(options[act]);
                                        } else if (e.key === 'Escape') {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setAdding(false);
                                            setQuery('');
                                            window.setTimeout(() => addButton.current?.focus(), 0);
                                        }
                                    }}
                                />
                            </span>
                            <div className="k-lb" role="listbox" id="rvLB" onMouseDown={(e) => e.preventDefault()}>
                                {options.map((p, i) => (
                                    <div key={'invite' in p ? 'inv' : p.id} role="option" id={`rvO${i}`} className={`rv-o${i === act ? ' act' : ''}`} aria-selected={false} onMouseMove={() => setAct(i)} onClick={() => pick(p)}>
                                        {'invite' in p ? PI.mail : <span className="cv" style={{ ...bloomStyle(p.seed, 24) }} aria-hidden="true" />}
                                        <span>{'invite' in p ? `Invite ${p.invite}` : p.name}</span>
                                    </div>
                                ))}
                            </div>
                        </span>
                    ) : (
                        <button className="k-l" type="button" id="rvAB" ref={addButton} onClick={() => setAdding(true)}>
                            <Plus />
                            <span>Add a reviewer</span>
                        </button>
                    )}
                </span>
            </div>
            <p className="rv-note" id="rvN" hidden={w.visibility !== 'public'}>
                Readers see published chapters. Reviewers leave notes only you can see.
            </p>
            <div className="rv-foot">
                <Link className="k-l" href="/reviewers">
                    <span>All reviewers</span>
                </Link>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- series */
function SeriesField({ w, set, series, setSeries, setSave }: { w: Settings; set: FormProps['set']; series: SeriesChoice[]; setSeries: (s: SeriesChoice[]) => void; setSave: (s: SaveState) => void }) {
    const [naming, setNaming] = useState<'' | 'new' | 'rename'>('');
    const current = series.find((s) => s.id === w.seriesId);
    const books = Math.max(4, (current?.books ?? 0) + (w.seriesId ? 0 : 1), w.seriesPosition);

    return (
        <div className="k-f" id="f-se">
            <span className="k-fl" id="l-se">
                Series
            </span>
            <div className="k-serrow">
                <KSelect
                    className="w-se2"
                    id="se2"
                    labelledBy="l-se"
                    value={w.seriesId ? String(w.seriesId) : ''}
                    options={[{ value: '', label: 'Not in a series' }, ...series.map((s) => ({ value: String(s.id), label: s.name })), { value: '__new', label: 'New series', add: true }]}
                    onPick={(v) => {
                        if (v === '__new') {
                            setNaming('new');

                            return;
                        }
                        setNaming('');
                        const id = v ? Number(v) : null;
                        const position = id ? (series.find((s) => s.id === id)?.books ?? 0) + 1 : 1;
                        set({ seriesId: id, seriesPosition: position }, { series_id: id, ...(id ? { series_position: position } : {}) });
                        if (id) {
                            setSeries(series.map((s) => (s.id === id ? { ...s, books: s.books + 1 } : s.id === w.seriesId ? { ...s, books: s.books - 1 } : s)));
                        }
                    }}
                />
                <Unfold show={!!w.seriesId} id="bkU">
                    <span className="k-fl k-vh" id="l-bk">
                        Book
                    </span>
                    <KSelect
                        className="bk"
                        id="bk"
                        labelledBy="l-bk"
                        value={String(w.seriesPosition)}
                        options={Array.from({ length: books }, (_, i) => ({ value: String(i + 1), label: `Book ${i + 1}` }))}
                        onPick={(v) => set({ seriesPosition: Number(v) }, { series_position: Number(v) })}
                    />
                </Unfold>
            </div>
            <NameField
                show={naming !== ''}
                placeholder={naming === 'rename' ? 'New name' : 'Series name'}
                initial={naming === 'rename' ? current?.name ?? '' : ''}
                action={naming === 'rename' ? 'Save' : 'Create'}
                onCancel={() => {
                    setNaming('');
                    document.getElementById('se2')?.focus();
                }}
                onCreate={async (name) => {
                    const renaming = naming === 'rename' && current;
                    setSave('saving');
                    const { status, data } = await json<{ series: SeriesChoice; errors: Record<string, string[]> }>(renaming ? 'PATCH' : 'POST', renaming ? `/series/${current.id}` : '/series', { name });
                    if ((status !== 200 && status !== 201) || !data.series) {
                        setSave('saved');

                        return data.errors?.name?.[0] ?? (status === 0 ? 'No connection. Try again.' : 'That didn’t work. Try again.');
                    }
                    const made = data.series;
                    if (renaming) {
                        setSeries(series.map((s) => (s.id === made.id ? made : s)));
                        setSave('saved');
                    } else {
                        setSeries([...series, { ...made, books: 1 }].sort((a, b) => a.name.localeCompare(b.name)));
                        set({ seriesId: made.id, seriesPosition: 1 }, { series_id: made.id, series_position: 1 });
                    }
                    setNaming('');
                    document.getElementById('se2')?.focus();

                    return null;
                }}
            />
            <Unfold show={!!w.seriesId && naming === ''} id="seRU">
                <button className="w-act" type="button" onClick={() => setNaming('rename')}>
                    Rename this series
                </button>
            </Unfold>
        </div>
    );
}

/* ---------------------------------------------------------------- a single piece's collection */
function CollectionField({ w, set, patch, collections }: { w: Settings; set: FormProps['set']; patch: FormProps['patch']; collections: CollectionChoice[] }) {
    const [naming, setNaming] = useState(false);
    const mine = collections.filter((c) => c.kind === w.kind);
    const current = collections.find((c) => c.id === w.collectionId);

    return (
        <div className="k-f" id="f-cl">
            <span className="k-fl" id="l-cl">
                Collection
            </span>
            <KSelect
                className="w-se2"
                id="cl2"
                labelledBy="l-cl"
                value={w.collectionId ? String(w.collectionId) : ''}
                options={[{ value: '', label: 'Not in a collection' }, ...mine.map((c) => ({ value: String(c.id), label: c.title })), { value: '__new', label: 'New collection', add: true }]}
                onPick={(v) => {
                    if (v === '__new') {
                        setNaming(true);

                        return;
                    }
                    setNaming(false);
                    const id = v ? Number(v) : null;
                    set({ collectionId: id }, { collection_id: id });
                }}
            />
            <Unfold show={!!current && !naming} id="clOU">
                {current && (
                    <Link className="k-l w-clo" href={`/works/${current.slug}?tab=chapters`}>
                        <span>Open {current.title}</span>
                    </Link>
                )}
            </Unfold>
            <NameField
                show={naming}
                placeholder="Collection name"
                onCancel={() => {
                    setNaming(false);
                    document.getElementById('cl2')?.focus();
                }}
                onCreate={async (name) => {
                    const { data } = await json<{ taken: { title: string } | null }>('GET', '/works/title-taken?title=' + encodeURIComponent(name));
                    if (data.taken) {
                        return `You already have a work called ${data.taken.title}.`;
                    }
                    const saved = await patch({ new_collection: name });
                    if (!saved) {
                        return 'That didn’t work. Try again.';
                    }
                    set({ collectionId: saved.collectionId });
                    setNaming(false);
                    document.getElementById('cl2')?.focus();

                    return null;
                }}
            />
        </div>
    );
}

/* ---------------------------------------------------------------- chapter headings */
function HeadingsField({ w, set }: { w: Settings; set: FormProps['set'] }) {
    const [swap, setSwap] = useState(false);

    async function pick(v: string) {
        setSwap(true);
        await wait(prefersReducedMotion() ? 0 : 160);
        set({ headingStyle: v }, { heading_style: v });
        setSwap(false);
    }

    return (
        <div className="k-f nov" id="f-hd">
            <span className="k-fl" id="l-hd">
                Chapter headings
            </span>
            <div className="k-tiles" role="radiogroup" aria-labelledby="l-hd">
                {STYLES.map((s) => (
                    <label className="k-tile" key={s}>
                        <input type="radio" name="hd" value={s} checked={w.headingStyle === s} onChange={() => pick(s)} />
                        <span>{heading(12, 'Chapter', s)}</span>
                    </label>
                ))}
            </div>
            <label className="ad-cb k-cbx">
                <input type="checkbox" checked={w.showChapterTitles} onChange={(e) => set({ showChapterTitles: e.target.checked }, { show_chapter_titles: e.target.checked })} />
                <span className="ad-box">
                    <BoxTick />
                </span>
                Show chapter titles
            </label>
            <label className="ad-cb k-cbx">
                <input type="checkbox" checked={w.showPov} onChange={(e) => set({ showPov: e.target.checked }, { show_pov: e.target.checked })} />
                <span className="ad-box">
                    <BoxTick />
                </span>
                Show POV to readers
            </label>
            <div className="k-hprev" aria-hidden="true">
                <span className={`k-hn${swap ? ' sw' : ''}`}>{heading(1, 'Chapter', w.headingStyle)}</span>
                <Unfold show={w.showChapterTitles}>
                    <span className="k-ht">The last warm day</span>
                </Unfold>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- parts */
function PartsField({ w, set, later }: { w: Settings; set: FormProps['set']; later: FormProps['later'] }) {
    const standard = PART_WORDS.includes(w.partWord);
    const [other, setOther] = useState(!standard);
    const [otherWord, setOtherWord] = useState(standard ? '' : w.partWord);
    const [swap, setSwap] = useState(false);
    const other_ = useRef<HTMLInputElement>(null);

    async function pickStyle(v: string) {
        setSwap(true);
        await wait(prefersReducedMotion() ? 0 : 160);
        set({ partStyle: v }, { part_style: v });
        setSwap(false);
    }

    return (
        <div className="k-f nov" id="f-pt">
            <span className="k-fl" id="l-pt">
                Parts
            </span>
            <span className="k-fl k-sub" id="l-pw">
                Call them
            </span>
            <div className="k-pills" role="radiogroup" aria-labelledby="l-pw">
                {[...PART_WORDS, ''].map((v) => (
                    <label className="k-pill" key={v || 'other'}>
                        <input
                            type="radio"
                            name="pw"
                            value={v}
                            checked={v ? !other && w.partWord === v : other}
                            onChange={() => {
                                if (!v) {
                                    setOther(true);
                                    window.setTimeout(() => other_.current?.focus(), prefersReducedMotion() ? 0 : 200);
                                    if (otherWord.trim()) {
                                        set({ partWord: otherWord.trim() }, { part_word: otherWord.trim() });
                                    }

                                    return;
                                }
                                setOther(false);
                                set({ partWord: v }, { part_word: v });
                            }}
                        />
                        <span>{v || 'Other'}</span>
                    </label>
                ))}
            </div>
            <Unfold show={other} id="pwU">
                <span className="k-in sm">
                    <input
                        ref={other_}
                        placeholder="What you call them, e.g. Movement"
                        autoComplete="off"
                        aria-label="What you call them"
                        value={otherWord}
                        onChange={(e) => {
                            setOtherWord(e.target.value);
                            const v = e.target.value.trim().replace(/\s+/g, ' ');
                            if (v) {
                                set({ partWord: cap(v) });
                                later('part_word', 400, { part_word: v });
                            }
                        }}
                    />
                </span>
            </Unfold>
            <span className="k-fl k-sub" id="l-ps">
                Numbers
            </span>
            <div className="k-tiles" role="radiogroup" aria-labelledby="l-ps" id="psT">
                {STYLES.map((s) => (
                    <label className="k-tile" key={s}>
                        <input type="radio" name="ps" value={s} checked={w.partStyle === s} onChange={() => pickStyle(s)} />
                        <span>{heading(2, w.partWord || 'Part', s)}</span>
                    </label>
                ))}
            </div>
            <label className="ad-cb k-cbx">
                <input type="checkbox" checked={w.showPartNumbers} onChange={(e) => set({ showPartNumbers: e.target.checked }, { show_part_numbers: e.target.checked })} />
                <span className="ad-box">
                    <BoxTick />
                </span>
                Show part numbers
            </label>
            <div className="k-hprev" aria-hidden="true">
                <Unfold show={w.showPartNumbers}>
                    <span className={`k-hn${swap ? ' sw' : ''}`}>{heading(2, w.partWord || 'Part', w.partStyle)}</span>
                </Unfold>
                <span className="k-ht">Lanterns</span>
                <span className="k-pd">Paper, matches, and everything nobody says out loud.</span>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- delete */
function DeleteField({ w, slug }: { w: Settings; slug: { current: string } }) {
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <div className="k-f k-del" id="f-dl">
            <button className="k-l k-danger" type="button" onClick={() => setAsking(true)}>
                <span>Delete this work</span>
            </button>
            <Unfold show={asking} id="dlU">
                <div className="k-conf">
                    <p>Delete {w.title}? Its chapters and plan go with it.</p>
                    <div className="acts">
                        <button
                            className={`k-btn sm danger${busy ? ' busy' : ''}`}
                            type="button"
                            aria-busy={busy}
                            onClick={() => {
                                setBusy(true);
                                loading(true);
                                router.delete(`/works/${slug.current}`, { onFinish: () => (setBusy(false), loading(false)) });
                            }}
                        >
                            <span>Delete</span>
                            <Ring />
                        </button>
                        <button className="k-l" type="button" onClick={() => setAsking(false)}>
                            <span>Cancel</span>
                        </button>
                    </div>
                </div>
            </Unfold>
        </div>
    );
}

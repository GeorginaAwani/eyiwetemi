import { Link, usePage } from '@inertiajs/react';
import { CSSProperties, FormEvent, Fragment, ReactNode, useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import Bloom from '@/Components/site/Bloom';
import Runs, { Run } from '@/Components/site/Runs';
import { Review, useReviewMarks } from '@/Components/site/ReviewMarks';
import SendButton, { SendState } from '@/Components/site/SendButton';
import SiteLayout from '@/Layouts/SiteLayout';
import { currentTheme, prefersReducedMotion, setThemeFrom, subscribeTheme, THEMES } from '@/lib/motion';
import { act, Action, ask, useAnyDone, useDone, useFollow, useLike } from '@/lib/live';
import { SignInDetail } from '@/lib/signin';
import { json } from '@/lib/http';

type Block =
    | { type: 'p'; id: string | null; runs: Run[] }
    | { type: 'h'; runs: Run[] }
    | { type: 'quote'; runs: Run[] }
    | { type: 'break' }
    | { type: 'ul' | 'ol'; items: Run[][] }
    | { type: 'img'; src: string | null; alt: string; caption: string | null; credit: string | null }
    | { type: 'stanza'; lines: { text: string; indent: number }[] };
type Chapter = { number: number; title: string | null; label: string; minutes: number; href: string | null; isNew: boolean };
type CommentT = {
    id: number;
    name: string;
    username: string | null;
    seed: string;
    photo: string | null;
    author: boolean;
    replyTo: string | null;
    body: string | null;
    when: string;
    likes: number;
    liked?: boolean;
};
type TopComment = CommentT & { deleted: boolean; replies: CommentT[] };
type Thread = { count: number; list: TopComment[]; shown: number; repliesShown: number };
type Props = {
    unavailable?: { work: { title: string; href: string }; label: string };
    work: { id: number; following?: boolean; title: string; href: string | null; kind: string; unitPlural: string; cover: string | null; kindLabel: string; thumb: { src: string | null; color: string | null } };
    item: {
        id: number;
        number: number;
        total: number;
        isPiece: boolean;
        label: string;
        title: string | null;
        meta: string;
        minutes: number;
        epigraph: { q: string; by: string | null } | null;
        soundtrack: { url: string; label: string | null } | null;
        likes: number;
        liked?: boolean;
        blocks: Block[];
        poem: boolean;
    };
    sceneBreak: string | null;
    nav: { prev: string | null; next: string | null; end: string | null; follow: boolean } | null;
    chapters: Chapter[];
    comments: Thread;
    /** a reviewer of this work: their own marks in the text */
    review?: Review | null;
};

/** What the sign-in card shows under its why-line: the chapter and work you're reading, with its cover. */
let reading: { label?: string; title: string; thumb: { src: string | null; color: string | null } | null } = { title: '', thumb: null };
const whyFor = (why: string): SignInDetail => ({
    why,
    sub: reading.label && !why.endsWith(reading.label) ? `${reading.label} · ${reading.title}` : why.endsWith(reading.title) ? undefined : reading.title,
    picture: reading.thumb ?? undefined,
});
const v = (vars: Record<string, string | number>) => vars as CSSProperties;

const I = {
    chapters: (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M8 6h13M8 12h13M8 18h13" />
            <circle cx="3.5" cy="6" r=".6" />
            <circle cx="3.5" cy="12" r=".6" />
            <circle cx="3.5" cy="18" r=".6" />
        </svg>
    ),
    note: (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M9 18V5l12-2v13" />
            <circle cx="6" cy="18" r="3" />
            <circle cx="18" cy="16" r="3" />
        </svg>
    ),
    close: (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12" />
        </svg>
    ),
    min: (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
        </svg>
    ),
    heart: (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7z" />
        </svg>
    ),
    prev: (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M19 12H5M11 6l-6 6 6 6" />
        </svg>
    ),
    next: (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M5 12h14M13 6l6 6-6 6" />
        </svg>
    ),
    send: (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 19V5M5 12l7-7 7 7" />
        </svg>
    ),
    bubble: (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z" />
        </svg>
    ),
    bell: (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
    ),
};

/** The text itself: paragraphs (first after a break unindented), headings, lists, pictures, stanzas. */
function Body({ blocks, poem, paragraph }: { blocks: Block[]; poem: boolean; paragraph?: (id: string | null, runs: Run[], className: string, key: number) => ReactNode }) {
    const out: ReactNode[] = [];
    let first = true;
    const stanzas: ReactNode[] = [];

    const flushPoem = () => {
        if (stanzas.length) {
            out.push(
                <div className="poem" key={`poem-${out.length}`}>
                    {stanzas.splice(0)}
                </div>,
            );
        }
    };

    blocks.forEach((b, i) => {
        if (b.type === 'stanza') {
            stanzas.push(
                <p className="stz pp" key={i}>
                    {b.lines.map((l, k) => (
                        <Fragment key={k}>
                            {k > 0 && '\n'}
                            {l.indent > 0 ? ' '.repeat(l.indent) : ''}
                            {l.text}
                        </Fragment>
                    ))}
                </p>,
            );
            return;
        }
        flushPoem();
        switch (b.type) {
            case 'p':
                out.push(
                    paragraph ? (
                        paragraph(b.id, b.runs, `pp${first ? ' first' : ''}`, i)
                    ) : (
                        <p className={`pp${first ? ' first' : ''}`} key={i} data-id={b.id ?? undefined}>
                            <Runs runs={b.runs} />
                        </p>
                    ),
                );
                first = false;
                break;
            case 'h':
                out.push(
                    <h2 className="eh" key={i}>
                        <Runs runs={b.runs} />
                    </h2>,
                );
                first = true;
                break;
            case 'quote':
                out.push(
                    <blockquote className="pp" key={i}>
                        <Runs runs={b.runs} />
                    </blockquote>,
                );
                break;
            case 'break':
                out.push(
                    <div className="brk r2b" role="separator" key={i}>
                        <i />
                        <b />
                        <i />
                    </div>,
                );
                first = true;
                break;
            case 'ul':
            case 'ol': {
                const List = b.type;
                out.push(
                    <List className="el" key={i}>
                        {b.items.map((runs, k) => (
                            <li key={k}>
                                <Runs runs={runs} />
                            </li>
                        ))}
                    </List>,
                );
                break;
            }
            case 'img':
                if (b.src) {
                    out.push(
                        <figure className="fig" key={i}>
                            <div className="book mat">
                                <img src={b.src} alt={b.alt} />
                            </div>
                            {(b.caption || b.credit) && (
                                <figcaption>
                                    <span>{b.caption}</span>
                                    {b.credit && <small>{b.credit}</small>}
                                </figcaption>
                            )}
                        </figure>,
                    );
                }
                break;
        }
    });
    flushPoem();

    return <>{poem && out.length === 0 ? null : out}</>;
}

/* ---------- reading settings (kept between visits, shared with Settings later) ---------- */
type ReadSettings = { size: number; font: 'serif' | 'sans'; width: 'narrow' | 'wide'; lines: 'close' | 'airy'; sound: 'off' | 'rain' | 'night' | 'cafe' };
const SIZES = [-3, -2, 0, 2, 4];
const DEFAULTS: ReadSettings = { size: 2, font: 'serif', width: 'narrow', lines: 'airy', sound: 'off' };
/** This device's settings; on a device that has none yet, your account's (signed in). */
const loadSettings = (account?: Partial<ReadSettings> | null): ReadSettings => {
    try {
        const local = localStorage.getItem('eyiRead');

        return { ...DEFAULTS, ...(local ? JSON.parse(local) : (account ?? {})) };
    } catch {
        return DEFAULTS;
    }
};

/** Signed in, a change here also becomes your account's default (a moment after you stop changing it). */
let saveTimer = 0;
function saveToAccount(st: ReadSettings) {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(() => json('PUT', '/settings/reading', { size: st.size, font: st.font, width: st.width, lines: st.lines }), 1200);
}
function applySettings(st: ReadSettings) {
    const R = document.documentElement.style;
    R.setProperty('--rso', `${SIZES[st.size]}px`);
    R.setProperty('--rf', st.font === 'sans' ? 'var(--A)' : 'var(--NR)');
    R.setProperty('--rw', st.width === 'wide' ? '780px' : '640px');
    R.setProperty('--rl', st.lines === 'close' ? '1.5' : '1.7');
    try {
        localStorage.setItem('eyiRead', JSON.stringify(st));
    } catch {
        /* this visit only */
    }
}

/* ---------- ambient sound: generated stand-ins until licensed loops are chosen ---------- */
let audio: AudioContext | null = null;
let ambient: { src: AudioBufferSourceNode; g: GainNode; level: number } | null = null;
function playAmbient(kind: ReadSettings['sound'], ducked: boolean) {
    if (ambient && audio) {
        const a = ambient;
        a.g.gain.cancelScheduledValues(audio.currentTime);
        a.g.gain.linearRampToValueAtTime(0, audio.currentTime + 0.6);
        window.setTimeout(() => a.src.stop(), 700);
        ambient = null;
    }
    if (kind === 'off') {
        return;
    }
    audio = audio ?? new AudioContext();
    void audio.resume();
    const len = audio.sampleRate * 4;
    const buf = audio.createBuffer(1, len, audio.sampleRate);
    const d = buf.getChannelData(0);
    let last = 0;
    for (let i = 0; i < len; i++) {
        const w = Math.random() * 2 - 1;
        if (kind === 'night') {
            last = (last + 0.02 * w) / 1.02;
            d[i] = last * 3.5;
        } else {
            d[i] = w;
        }
    }
    const src = audio.createBufferSource();
    src.buffer = buf;
    src.loop = true;
    const f = audio.createBiquadFilter();
    const g = audio.createGain();
    if (kind === 'rain') {
        f.type = 'bandpass';
        f.frequency.value = 1500;
        f.Q.value = 0.5;
    }
    if (kind === 'night') {
        f.type = 'lowpass';
        f.frequency.value = 420;
    }
    if (kind === 'cafe') {
        f.type = 'bandpass';
        f.frequency.value = 650;
        f.Q.value = 1.1;
        const lfo = audio.createOscillator();
        const lg = audio.createGain();
        lfo.frequency.value = 0.23;
        lg.gain.value = 0.04;
        lfo.connect(lg).connect(g.gain);
        lfo.start();
    }
    src.connect(f).connect(g).connect(audio.destination);
    g.gain.value = 0;
    src.start();
    ambient = { src, g, level: { rain: 0.14, night: 0.32, cafe: 0.1 }[kind] };
    duckAmbient(ducked);
}
function duckAmbient(ducked: boolean) {
    if (!ambient || !audio) {
        return;
    }
    ambient.g.gain.cancelScheduledValues(audio.currentTime);
    ambient.g.gain.linearRampToValueAtTime(ambient.level * (ducked ? 0.3 : 1), audio.currentTime + 0.8);
}

/** The soundtrack's own player: YouTube (no-cookie) or Spotify, loaded only when you open Listen. */
function embedUrl(url: string): { src: string; kind: 'youtube' | 'spotify' } | null {
    const yt = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([\w-]{11})/);
    if (yt) {
        return { src: `https://www.youtube-nocookie.com/embed/${yt[1]}`, kind: 'youtube' };
    }
    const sp = url.match(/open\.spotify\.com\/(track|album|playlist)\/(\w+)/);
    if (sp) {
        return { src: `https://open.spotify.com/embed/${sp[1]}/${sp[2]}`, kind: 'spotify' };
    }
    return null;
}

/** Add a comment, or a reply (it starts with "@Name "). Signed out, it's kept and posts once you're in. */
function CommentForm({
    itemId,
    replyTo = null,
    placeholder,
    reply,
    prefill = '',
    why,
}: {
    itemId: number;
    replyTo?: number | null;
    placeholder: string;
    reply?: boolean;
    prefill?: string;
    why: string;
}) {
    const { auth } = usePage().props;
    const [text, setText] = useState(prefill);
    const [state, setState] = useState<SendState>('idle');
    const lead = prefill.trim();
    const body = () => {
        const t = text.trim();

        return lead && t.startsWith(lead) ? t.slice(lead.length).trim() : t;
    };
    const can = body().length > 0 && state !== 'sending';
    const action = (): Action => ({ kind: 'comment', work_item_id: itemId, body: body(), reply_to: replyTo });

    /* posted (here, or carried on after signing in): the box empties */
    useDone({ kind: 'comment', work_item_id: itemId, body: '', reply_to: replyTo }, ({ status }) => {
        if (status === 201) {
            setText(prefill);
            setState('idle');
        }
    });

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!can) {
            return;
        }
        if (!auth.user) {
            ask(action(), whyFor(why));

            return;
        }
        setState('sending');
        const { status } = await act(action());
        if (status !== 201) {
            setState('failed');
        }
    };

    return (
        <form className={`cnew${reply ? ' rform settle' : ''}`} onSubmit={submit}>
            {auth.user && <Bloom seed={auth.user.avatar_seed} size={reply ? 32 : 40} />}
            <div className="cin">
                <input
                    type="text"
                    value={text}
                    maxLength={2000}
                    placeholder={placeholder}
                    aria-label={placeholder || 'Reply'}
                    autoFocus={reply}
                    onFocus={(e) => reply && e.currentTarget.setSelectionRange(e.currentTarget.value.length, e.currentTarget.value.length)}
                    onChange={(e) => {
                        setText(e.target.value);
                        if (state === 'failed') {
                            setState('idle');
                        }
                    }}
                />
                <SendButton className="send" type="submit" state={state} aria-label="Send" disabled={!can}>
                    {I.send}
                </SendButton>
            </div>
        </form>
    );
}

/** A comment's heart. */
function CommentLike({ c }: { c: CommentT }) {
    const like = useLike('comment', c.id, c.liked ?? false, c.likes, whyFor(`Sign in to like ${c.name}’s comment`));

    return (
        <button className={`lk sm${like.liked ? ' on' : ''}${like.liked && like.pop ? ' pop' : ''}`} type="button" aria-pressed={like.liked} aria-label="Like" onClick={like.toggle}>
            {I.heart}
            <span>{like.count}</span>
        </button>
    );
}

function CommentView({ c, reply, fresh, onReply }: { c: CommentT; reply?: boolean; fresh?: boolean; onReply: (c: CommentT) => void }) {
    return (
        <div className={`cm ${reply ? 'rp' : 'top'}${fresh ? ' settle' : ''}`} id={`c-${c.id}`}>
            <Bloom seed={c.seed} size={reply ? 32 : 40} photo={c.photo} name={c.name} />
            <div className="cbd">
                <div className="chd">
                    <b>{c.name}</b>
                    {c.author && <span className="atag">Author</span>}
                    <span>{c.when}</span>
                </div>
                <p>
                    {c.replyTo && (
                        <>
                            <span className="at">@{c.replyTo}</span>{' '}
                        </>
                    )}
                    {c.body}
                </p>
                <div className="cft">
                    <CommentLike c={c} />
                    <button className="rbtn" type="button" onClick={() => onReply(c)}>
                        Reply
                    </button>
                </div>
            </div>
        </div>
    );
}

/** Reader comments, oldest first, replies one level under; what you post settles in where it belongs. */
function Comments({ itemId, thread, setThread, why }: { itemId: number; thread: Thread; setThread: (f: (t: Thread) => Thread) => void; why: string }) {
    const [shown, setShown] = useState(thread.shown);
    const [openReplies, setOpenReplies] = useState<Record<number, boolean>>({});
    const [replying, setReplying] = useState<{ top: number; to: number; name: string } | null>(null);
    const [fresh, setFresh] = useState<Record<number, true>>({});
    const left = thread.list.filter((t, idx) => idx >= shown && !fresh[t.id]).length;

    useAnyDone(({ action, status, data }) => {
        if (action.kind !== 'comment' || action.work_item_id !== itemId || status !== 201 || !data?.comment) {
            return;
        }
        const c = data.comment as TopComment & { parent: number | null };
        setFresh((f) => ({ ...f, [c.id]: true }));
        setThread((t) => ({
            ...t,
            count: typeof data.count === 'number' ? data.count : t.count + 1,
            list: c.parent === null ? [...t.list, { ...c, deleted: false, replies: [] }] : t.list.map((top) => (top.id === c.parent ? { ...top, replies: [...top.replies, c] } : top)),
        }));
        if (c.parent !== null) {
            setOpenReplies((o) => ({ ...o, [c.parent as number]: true }));
            setReplying(null);
        }
        window.setTimeout(() => document.getElementById(`c-${c.id}`)?.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' }), 60);
    });

    const replyTo = (top: number) => (c: CommentT) => setReplying({ top, to: c.id, name: c.name });

    return (
        <section className="cmts" id="comments">
            <h2 className="ch2">
                Comments <small className="ccount">{thread.count}</small>
            </h2>
            <CommentForm itemId={itemId} placeholder="Add a comment" why="Sign in to post your comment" />
            <div className="clist">
                {thread.list.map((top, idx) => {
                    if (idx >= shown && !fresh[top.id]) {
                        return null;
                    }
                    const late = idx >= thread.shown && !fresh[top.id];
                    const all = openReplies[top.id];
                    const reps = all ? top.replies : top.replies.slice(0, thread.repliesShown);
                    const more = top.replies.length - reps.length;
                    const content = (
                        <div className="reps">
                            {reps.map((r) => (
                                <CommentView key={r.id} c={r} reply fresh={!!fresh[r.id]} onReply={replyTo(top.id)} />
                            ))}
                            {more > 0 && (
                                <button type="button" className="more mrep" onClick={() => setOpenReplies((o) => ({ ...o, [top.id]: true }))}>
                                    <i />
                                    <span>
                                        Show {more} more {more === 1 ? 'reply' : 'replies'}
                                    </span>
                                </button>
                            )}
                            {replying?.top === top.id && (
                                <CommentForm itemId={itemId} replyTo={replying.to} placeholder="" reply prefill={`@${replying.name} `} why={`Sign in to reply to ${replying.name}`} key={replying.to} />
                            )}
                        </div>
                    );

                    return top.deleted ? (
                        <div className={`cm top gone${late ? ' settle' : ''}`} key={top.id} id={`c-${top.id}`}>
                            <span className="av cdel-av" aria-hidden="true" />
                            <div className="cbd">
                                <div className="chd cdel">Comment deleted</div>
                                {content}
                            </div>
                        </div>
                    ) : (
                        <div
                            className={`cm top${late || fresh[top.id] ? ' settle' : ''}`}
                            key={top.id}
                            id={`c-${top.id}`}
                            style={late ? { animationDelay: `${(idx - thread.shown) * 70}ms` } : undefined}
                        >
                            <Bloom seed={top.seed} size={40} photo={top.photo} name={top.name} />
                            <div className="cbd">
                                <div className="chd">
                                    <b>{top.name}</b>
                                    {top.author && <span className="atag">Author</span>}
                                    <span>{top.when}</span>
                                </div>
                                <p>{top.body}</p>
                                <div className="cft">
                                    <CommentLike c={top} />
                                    <button className="rbtn" type="button" onClick={() => replyTo(top.id)(top)}>
                                        Reply
                                    </button>
                                </div>
                                {content}
                            </div>
                        </div>
                    );
                })}
            </div>
            {left > 0 && (
                <button type="button" className="more mcom" onClick={() => setShown((s) => s + 5)}>
                    <i />
                    <span>
                        Show {left} more {left === 1 ? 'comment' : 'comments'}
                    </span>
                </button>
            )}
        </section>
    );
}

type PanelName = 'ch' | 'set' | 'snd' | null;

export default function Reader(props: Props) {
    const { unavailable } = props;

    /* the reader's own styles apply while it's open */
    useEffect(() => {
        document.documentElement.classList.add('reader');

        return () => document.documentElement.classList.remove('reader', 'hh0');
    }, []);

    if (unavailable) {
        return (
            <SiteLayout title={unavailable.work.title} notice={false}>
                <div className="r2">
                    <div className="of-page">
                        <Link className="w" href={unavailable.work.href}>
                            {unavailable.work.title}
                        </Link>
                        <span className="n">{unavailable.label}</span>
                        <h1>Not available right now</h1>
                        <Link className="bk" href={unavailable.work.href}>
                            Back to {unavailable.work.title}
                        </Link>
                    </div>
                </div>
            </SiteLayout>
        );
    }

    return <ReaderPage {...props} />;
}

function ReaderPage({ work, item, sceneBreak, nav, chapters, comments, review }: Props) {
    reading = { label: item.isPiece ? undefined : item.label, title: work.title, thumb: work.thumb };
    const { url } = usePage();
    const theme = useSyncExternalStore(subscribeTheme, currentTheme, () => 'white' as const);
    const [panel, setPanel] = useState<PanelName>(null);
    const [barShown, setBarShown] = useState(false);
    const [pct, setPct] = useState(0);
    const { auth } = usePage().props;
    const [st, setSt] = useState<ReadSettings>(() => loadSettings(auth.user?.reading));
    const firstSettings = useRef(true);
    const [listening, setListening] = useState(false);
    const [back, setBack] = useState(false);
    const txt = useRef<HTMLElement>(null);
    const marks = useReviewMarks(review ?? null, item.id, txt);
    const lastY = useRef(0);
    const panelRef = useRef<PanelName>(null);
    panelRef.current = panel;
    const embed = item.soundtrack ? embedUrl(item.soundtrack.url) : null;
    const key = `eyiPos:${url.split('?')[0]}`;
    const isPhone = () => window.matchMedia('(max-width:900px)').matches;
    const [thread, setThread] = useState(comments);
    useEffect(() => setThread(comments), [comments]);
    const itemName = item.isPiece ? work.title : item.label;
    const like = useLike('work_item', item.id, item.liked ?? false, item.likes, whyFor(`Sign in to like ${itemName}`));
    const follow = useFollow('work', work.id, work.following ?? false, { why: `Sign in to follow ${work.title}`, picture: work.thumb });

    useEffect(() => {
        applySettings(st);
        if (auth.user && !firstSettings.current) {
            saveToAccount(st);
        }
        firstSettings.current = false;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [st]);
    useEffect(() => duckAmbient(listening), [listening]);
    useEffect(() => () => playAmbient('off', false), []);
    useEffect(() => {
        if (st.sound === 'off') {
            return;
        }
        const start = () => playAmbient(st.sound, listening);
        window.addEventListener('pointerdown', start, { once: true });

        return () => window.removeEventListener('pointerdown', start);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* progress, the bar on scroll-up, and where you are in the text */
    const saveAt = useRef(0);
    const onScroll = useCallback(() => {
        const el = txt.current;
        const hdr = document.getElementById('hdr');
        if (!el) {
            return;
        }
        const y = window.scrollY;
        const r = el.getBoundingClientRect();
        const p = Math.min(1, Math.max(0, (window.innerHeight * 0.6 - r.top) / r.height));
        setPct(p);
        document.documentElement.style.setProperty('--hh', `${hdr?.offsetHeight ?? 96}px`);
        requestAnimationFrame(() => document.documentElement.classList.toggle('hh0', !!hdr?.classList.contains('hide')));
        if (panelRef.current === null || panelRef.current === 'snd') {
            if (y < lastY.current - 6 && r.top < -40) {
                setBarShown(true);
            } else if (y > lastY.current + 6 || r.top > 0) {
                setBarShown(false);
            }
        }
        lastY.current = y;

        if (Date.now() - saveAt.current < 400) {
            return;
        }
        saveAt.current = Date.now();
        try {
            if (p > 0.97) {
                localStorage.removeItem(key);
                return;
            }
            let k = 0;
            el.querySelectorAll('.pp').forEach((pp, i) => {
                if (pp.getBoundingClientRect().top < window.innerHeight * 0.3) {
                    k = i;
                }
            });
            if (k > 0) {
                localStorage.setItem(key, String(k));
            }
        } catch {
            /* nowhere to keep it */
        }
    }, [key]);

    useEffect(() => {
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);

        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
        };
    }, [onScroll]);

    /* back where you left off (?start reads from the top) */
    useEffect(() => {
        const el = txt.current;
        if (!el) {
            return;
        }
        let saved = 0;
        try {
            if (/[?&]start/.test(window.location.search)) {
                localStorage.removeItem(key);
            } else {
                saved = Number(localStorage.getItem(key)) || 0;
            }
        } catch {
            saved = 0;
        }
        const target = el.querySelectorAll<HTMLElement>('.pp')[saved];
        if (saved > 1 && target && !window.location.hash) {
            window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - window.innerHeight * 0.3, behavior: 'instant' as ScrollBehavior });
            target.classList.add('here2');
            saveAt.current = Date.now() + 1500;
            const a = window.setTimeout(() => setBack(true), 300);
            const b = window.setTimeout(() => setBack(false), 6500);

            return () => {
                window.clearTimeout(a);
                window.clearTimeout(b);
            };
        }
        onScroll();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    /* Esc closes panels */
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setPanel((p) => (p === 'snd' ? p : null));
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const open = (name: Exclude<PanelName, null>) => {
        setPanel((p) => (p === name ? null : name));
        if (name === 'snd') {
            setListening(true);
        }
        if (!isPhone()) {
            setBarShown(true);
        }
    };

    useEffect(() => {
        if (panel === 'ch') {
            document.querySelector('#pCh .crow.chere')?.scrollIntoView({ block: 'center' });
        }
    }, [panel]);

    const set = <K extends keyof ReadSettings>(k: K, value: ReadSettings[K]) => {
        setSt((s) => ({ ...s, [k]: value }));
        if (k === 'sound') {
            playAmbient(value as ReadSettings['sound'], listening);
        }
    };

    const listenIcon = listening ? (
        <span className="eq" aria-hidden="true">
            <i />
            <i />
            <i />
        </span>
    ) : (
        I.note
    );

    const tools = (inBar: boolean) => (
        <>
            {!item.isPiece && (
                <button type="button" className={`tool${panel === 'ch' ? ' on' : ''}`} aria-label={work.unitPlural} onClick={(e) => (e.stopPropagation(), open('ch'))}>
                    {I.chapters}
                    <span>{work.unitPlural}</span>
                </button>
            )}
            {inBar && !item.isPiece && (
                <span className="b2t">
                    {item.label} · <b id="pct">{Math.round(pct * 100)}%</b>
                </span>
            )}
            <button type="button" className={`tool${panel === 'set' ? ' on' : ''}`} aria-label="Reading" onClick={(e) => (e.stopPropagation(), open('set'))}>
                <span className="aa" aria-hidden="true">
                    <b>A</b>
                    <small>a</small>
                </span>
                <span>Reading</span>
            </button>
            {embed && (
                <button type="button" className={`tool lis${listening ? ' on' : ''}`} aria-label="Listen" onClick={(e) => (e.stopPropagation(), open('snd'))}>
                    <span className="ico">{listenIcon}</span>
                    <span>Listen</span>
                </button>
            )}
            {inBar && (
                <a
                    className="tool cmt"
                    href="#comments"
                    aria-label="Comments"
                    onClick={(e) => {
                        e.preventDefault();
                        document.getElementById('comments')?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
                    }}
                >
                    {I.bubble}
                    <span className="n">{thread.count}</span>
                </a>
            )}
        </>
    );

    const title = item.title ?? item.label;
    const why = `Sign in to reply on ${itemName}`;

    return (
        <SiteLayout title={item.isPiece ? work.title : `${title} · ${work.title}`} notice={false}>
            <div className="prog" id="prog" aria-hidden="true">
                <i style={{ width: `${(pct * 100).toFixed(1)}%` }} />
            </div>
            {work.cover && (
                <div className="covwrap">
                    <div className="book mat pcov">
                        <img src={work.cover} alt="" />
                        <i className="glint" />
                    </div>
                </div>
            )}
            <div className={`r2${item.isPiece ? ' piece' : ''}`} data-sb={sceneBreak ?? undefined}>
                <header className="chh c2h">
                    {work.href ? (
                        <Link className="tl wk2" href={work.href}>
                            {work.title}
                        </Link>
                    ) : null}
                    {item.isPiece ? <div className="chn">{work.kindLabel}</div> : item.title && <div className="chn">{item.label}</div>}
                    <h1 className="cht">{title}</h1>
                    <div className="chmeta">{item.meta}</div>
                    <div className="tools2">{tools(false)}</div>
                </header>
                {item.epigraph && (
                    <div className="epi">
                        <span className="q">{item.epigraph.q}</span>
                        {item.epigraph.by && <span className="by">{item.epigraph.by}</span>}
                    </div>
                )}
                <article className="txt2" id="txt" ref={txt} data-rev={marks.on ? '' : undefined}>
                    <Body blocks={item.blocks} poem={item.poem} paragraph={marks.on ? marks.paragraph : undefined} />
                </article>
                {marks.toolbar}
                <div className="end">
                    <div className="endl">
                        <button className={`lk${like.liked ? ' on' : ''}${like.liked && like.pop ? ' pop' : ''}`} type="button" aria-pressed={like.liked} aria-label="Like" onClick={like.toggle}>
                            {I.heart}
                            <span>{like.count}</span>
                        </button>
                        {!item.isPiece && (
                            <span className="endm">
                                {item.label} of {item.total}
                            </span>
                        )}
                    </div>
                    {nav && (
                        <nav className="pn" aria-label={`${work.unitPlural}`}>
                            {nav.prev ? (
                                <Link className="pv" href={nav.prev}>
                                    <span className="pa">{I.prev}</span>
                                    Previous {work.kind === 'novel' ? 'chapter' : work.unitPlural.toLowerCase().replace(/ies$/, 'y').replace(/s$/, '')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            {nav.next ? (
                                <Link className="nx" href={nav.next}>
                                    Next {work.kind === 'novel' ? 'chapter' : work.unitPlural.toLowerCase().replace(/ies$/, 'y').replace(/s$/, '')}
                                    <span className="pa">{I.next}</span>
                                </Link>
                            ) : (
                                <span className="nx soon">
                                    {nav.end}
                                    {nav.follow && (
                                        <>
                                            {' '}
                                            <button type="button" className={`fpill${follow.following ? ' on' : ''}`} aria-pressed={follow.following} onClick={follow.toggle}>
                                                {I.bell}
                                                <span>{follow.following ? 'Following' : 'Follow'}</span>
                                            </button>
                                        </>
                                    )}
                                </span>
                            )}
                        </nav>
                    )}
                    <Comments itemId={item.id} thread={thread} setThread={setThread} why={why} />
                </div>
            </div>

            <div className={`bar2 live${barShown ? ' show' : ''}`} id="bar" aria-hidden={!barShown}>
                {tools(true)}
            </div>
            <div className={`scrim${panel && panel !== 'snd' && typeof window !== 'undefined' && isPhone() ? ' on' : ''}`} id="scrim" onClick={() => setPanel(null)} />
            <div className="dock" id="dock">
                {!item.isPiece && (
                    <div className={`chpop pnl${panel === 'ch' ? ' open' : ''}`} id="pCh" role="dialog" aria-label={work.unitPlural}>
                        <div className="cph">
                            <span>
                                <b>
                                    {work.href && (
                                        <Link className="tl" href={work.href}>
                                            {work.title}
                                        </Link>
                                    )}
                                </b>
                                <small>
                                    {item.total} {work.unitPlural.toLowerCase()}
                                </small>
                            </span>
                            <button type="button" className="sx" aria-label="Close" onClick={() => setPanel(null)}>
                                {I.close}
                            </button>
                        </div>
                        <div className="crows">
                            {chapters.map((c) => (
                                <Link key={c.number} className={`crow${c.number === item.number ? ' chere' : ''}`} href={c.href ?? '#'} onClick={() => setPanel(null)}>
                                    <span className="cn">{c.title ? c.number : ''}</span>
                                    <span className="ct">{c.label}</span>
                                    {c.number === item.number ? <span className="pill2">you’re here</span> : c.isNew ? <span className="pill2">new</span> : null}
                                    <span className="cmn">{c.minutes} min</span>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}
                <div className={`set pnl${panel === 'set' ? ' open' : ''}`} id="pSet" role="dialog" aria-label="Reading">
                    <div className="sth">
                        <b>Reading</b>
                        <button type="button" className="sx" aria-label="Close" onClick={() => setPanel(null)}>
                            {I.close}
                        </button>
                    </div>
                    <div className="sr">
                        <div className="swr">
                            {THEMES.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    className={`sw${theme === t.key ? ' cur' : ''}`}
                                    style={v({ '--c': t.bg, '--k': t.ink })}
                                    aria-label={t.name}
                                    aria-pressed={theme === t.key}
                                    onClick={(e) => setThemeFrom(t.key, e.currentTarget)}
                                >
                                    <i />
                                    <small>{t.name}</small>
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="sr">
                        <span className="sl">Size</span>
                        <div className="sz">
                            <small>A</small>
                            <span className="szd">
                                {SIZES.map((_, k) => (
                                    <button key={k} type="button" className={st.size === k ? 'cur' : undefined} aria-label={`Size ${k + 1}`} aria-pressed={st.size === k} onClick={() => set('size', k)}>
                                        <i />
                                    </button>
                                ))}
                            </span>
                            <b>A</b>
                        </div>
                    </div>
                    {(
                        [
                            ['font', 'Font', [['serif', 'Serif'], ['sans', 'Sans']]],
                            ['width', 'Width', [['narrow', 'Narrow'], ['wide', 'Wide']]],
                            ['lines', 'Lines', [['close', 'Close'], ['airy', 'Airy']]],
                            ['sound', 'Sound', [['off', 'Off'], ['rain', 'Rain'], ['night', 'Night'], ['cafe', 'Café']]],
                        ] as const
                    ).map(([k, label, opts]) => (
                        <div className="sr" key={k}>
                            <span className="sl">{label}</span>
                            <div className="sg">
                                {opts.map(([value, text]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={st[k] === value ? 'cur' : undefined}
                                        aria-pressed={st[k] === value}
                                        onClick={() => set(k, value as never)}
                                    >
                                        {value === 'serif' ? <span className="f1">{text}</span> : text}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
                {embed && (
                    <div className={`snd pnl${panel === 'snd' ? ' open' : ''}${listening ? ' playing' : ''}`} id="pSnd" role="dialog" aria-label="Soundtrack">
                        <div className="sndh">
                            {listenIcon}
                            <b>{item.soundtrack?.label ?? 'Soundtrack'}</b>
                            <button type="button" className="sx" aria-label="Minimise" onClick={() => setPanel(null)}>
                                {I.min}
                            </button>
                            <button
                                type="button"
                                className="sx"
                                aria-label="Close"
                                onClick={() => {
                                    setListening(false);
                                    setPanel(null);
                                }}
                            >
                                {I.close}
                            </button>
                        </div>
                        <div className="emb">
                            {listening && (
                                <iframe
                                    title={item.soundtrack?.label ?? 'Soundtrack'}
                                    src={embed.src}
                                    style={{ display: 'block', width: '100%', border: 0, aspectRatio: embed.kind === 'youtube' ? '16/9' : undefined, height: embed.kind === 'spotify' ? 152 : undefined }}
                                    allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"
                                    loading="lazy"
                                />
                            )}
                        </div>
                        <div className="snds">Plays from {embed.kind === 'youtube' ? 'YouTube' : 'Spotify'}</div>
                    </div>
                )}
            </div>
            <div className={`backpill live${back ? ' show' : ''}`} id="backp" role="status">
                Back where you left off
                <button type="button" className="sx" aria-label="Dismiss" onClick={() => setBack(false)}>
                    {I.close}
                </button>
            </div>
        </SiteLayout>
    );
}

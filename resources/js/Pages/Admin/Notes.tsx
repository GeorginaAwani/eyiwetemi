import { CSSProperties, Fragment, ReactNode, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { EditorState, Selection } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { KSelect, wait } from '../../Components/admin/Controls';
import { Ring } from '../../Components/admin/Icons';
import { isEmptyDoc, Json, markActive, noteDoc, notePlugins, setLink, toggleBold, toggleItalic } from '../../Components/admin/notes/editor';
import AdminLayout from '../../Layouts/AdminLayout';
import { beacon, json } from '../../lib/http';
import { prefersReducedMotion as RM } from '../../lib/motion';

/* ------------------------------------------------------------------ data */
type NoteRow = {
    id: number;
    state: 'draft' | 'sch' | 'live';
    changed: boolean;
    day: string | null;
    at: string | null;
    edited: string;
    justNow: boolean;
    body: Json;
    text: string;
    work: string | null;
    photo: { id: number; src: string } | null;
    quiet: boolean;
    likes: number;
    newLikes: number;
};
type WorkRow = { slug: string; title: string; kind: string; public: boolean; followers: number };
type Props = { notes: NoteRow[]; works: WorkRow[]; open: number | null; w: string; now: string };

/* ------------------------------------------------------------------ icons (the prototype's set) */
const line = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
const ic = (size: number, width: number, d: ReactNode) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth={width} aria-hidden="true">
        {d}
    </svg>
);
const D = {
    heart: <path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z" />,
    img: (
        <>
            <rect x="3.5" y="5" width="17" height="14" rx="2" />
            <circle cx="9" cy="10" r="1.6" />
            <path d="m4 17 5-5 4 4 2.5-2.5L20 17" />
        </>
    ),
    bell: (
        <>
            <path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z" />
            <path d="M10 20.5a2.2 2.2 0 0 0 4 0" />
        </>
    ),
    belloff: (
        <>
            <path d="M3 3l18 18" />
            <path d="M8.3 5.9A6 6 0 0 1 18 11v4.2M16.5 18H4.5L6 16v-5c0-.9.2-1.7.5-2.5" />
            <path d="M10 20.5a2.2 2.2 0 0 0 4 0" />
        </>
    ),
    link: (
        <>
            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" />
            <path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />
        </>
    ),
    trash: <path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13" />,
    check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
    cloud: (
        <>
            <path d="M3 3l18 18" />
            <path d="M7 18h10.5a3.5 3.5 0 0 0 1.2-6.8A6 6 0 0 0 8.7 7.3M5.2 9.8A4.2 4.2 0 0 0 7 18" />
        </>
    ),
    pen: <path d="M4 20h4L19 9l-4-4L4 16z" />,
    clock: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    globe: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
        </>
    ),
    book: (
        <>
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
        </>
    ),
    chev: <path d="m6 9 6 6 6-6" />,
    x: <path d="M6 6l12 12M18 6 6 18" />,
    tick: <path d="m5 12.5 4.5 4.5L19 7" />,
    plus: <path d="M12 5v14M5 12h14" />,
    search: (
        <>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </>
    ),
    lock: (
        <>
            <rect x="5" y="11" width="14" height="9" rx="2" />
            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
        </>
    ),
    alert: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7.5v5.5M12 16.5v.01" />
        </>
    ),
};
const I = {
    heart15: ic(15, 1.8, D.heart),
    img18: ic(18, 1.8, D.img),
    img20: ic(20, 1.8, D.img),
    bell18: ic(18, 1.8, D.bell),
    belloff18: ic(18, 1.8, D.belloff),
    link18: ic(18, 1.8, D.link),
    link20: ic(20, 1.8, D.link),
    trash17: ic(17, 1.8, D.trash),
    check17: ic(17, 1.8, D.check),
    cloud15: ic(15, 1.8, D.cloud),
    pen15: ic(15, 1.8, D.pen),
    clock15: ic(15, 1.8, D.clock),
    clock16: ic(16, 1.8, D.clock),
    clock17: ic(17, 1.8, D.clock),
    globe15: ic(15, 1.8, D.globe),
    book17: ic(17, 1.8, D.book),
    chev16: ic(16, 1.8, D.chev),
    chev18: ic(18, 1.8, D.chev),
    chev20: ic(20, 1.8, D.chev),
    x16: ic(16, 1.8, D.x),
    x17: ic(17, 1.8, D.x),
    x18: ic(18, 1.8, D.x),
    tick15: ic(15, 2.4, D.tick),
    plus18: ic(18, 2, D.plus),
    search: ic(18, 1.8, D.search),
    lock14: ic(14, 1.8, D.lock),
    alert15: ic(15, 2, D.alert),
    dot: (
        <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="3" fill="currentColor" />
        </svg>
    ),
};

/* ------------------------------------------------------------------ dates (all West Africa Time, as the server sends them) */
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTH = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const pd = (d: string) => {
    const [y, m, dd] = d.split('-').map(Number);

    return new Date(y, m - 1, dd);
};
const ymd = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
const dShort = (d: string) => `${pd(d).getDate()} ${MON[pd(d).getMonth()]}`;
const dDay = (d: string) => {
    const x = pd(d);

    return `${DAY[x.getDay()]} ${x.getDate()} ${MON[x.getMonth()]}`;
};
const tFmt = (hm: string) => {
    const [h, m] = hm.split(':').map(Number);

    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
};
const atFmt = (at: string) => `${dDay(at.slice(0, 10))}, ${tFmt(at.slice(11, 16))}`;
function ago(iso: string) {
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) {
        return 'just now';
    }
    if (m < 60) {
        return m + ' min ago';
    }
    if (m < 1440) {
        return Math.round(m / 60) + ' h ago';
    }

    return m < 2880 ? 'yesterday' : Math.round(m / 1440) + ' d ago';
}
const TIMES = [...Array(48)].map((_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`);

/* ------------------------------------------------------------------ motion (as the prototype) */
const EASE = 'cubic-bezier(.22,.8,.2,1)';
function after(an: Animation, ms: number, fn: () => void) {
    let done = false;
    const f = () => {
        if (!done) {
            done = true;
            fn();
        }
    };
    an.onfinish = f;
    window.setTimeout(f, ms + 60);
}
function grow(el: HTMLElement, from: number) {
    if (RM()) {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: from + 'px', opacity: 0.6 }, { height: h + 'px', opacity: 1 }], { duration: 380, easing: EASE }), 380, () => (el.style.overflow = ''));
    [...el.children].forEach((c, i) =>
        c.animate([{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(6px)' }, { opacity: 1, filter: 'none', transform: 'none' }], { duration: 420, delay: 60 + i * 40, easing: EASE, fill: 'backwards' }),
    );
}
function shrink(el: HTMLElement, to: number, done: () => void) {
    if (RM()) {
        done();

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: h + 'px', opacity: 1 }, { height: to + 'px', opacity: 0.4 }], { duration: 300, easing: EASE, fill: 'forwards' }), 300, done);
}
function bye(el: HTMLElement, done: () => void) {
    if (RM()) {
        after(el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' }), 160, done);

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: h + 'px', opacity: 1, filter: 'none' }, { height: '0px', opacity: 0, filter: 'blur(4px)' }], { duration: 320, easing: EASE, fill: 'forwards' }), 320, done);
}
function inA(el: Element | null) {
    if (!el) {
        return;
    }
    if (RM()) {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

        return;
    }
    el.animate([{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(4px)' }, { opacity: 1, filter: 'none', transform: 'none' }], { duration: 420, easing: EASE });
}
function settle(el: Element | null) {
    if (!el) {
        return;
    }
    [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i)));
    el.classList.remove('settle');
    void (el as HTMLElement).offsetWidth;
    el.classList.add('settle');
}
const phone = () => window.innerWidth <= 600;
const xsrf = (): string => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
const offline = () => !navigator.onLine;
const KINDS: Record<string, string> = { novel: 'Novel', poem: 'Poem', story: 'Story', essay: 'Essay', collection: 'Collection' };
const KEEP = 'eyiAdmNoteDraft:';

/* ------------------------------------------------------------------ the page */
type Pop = { kind: 'aw' | 'sch' | 'del' | 'off'; id: number; btn: HTMLElement; what?: string };

/**
 * Notes, her diary: what isn't out yet on top, then the months (earlier months of this year and earlier years fold).
 * A line unfolds into the writer in place; it saves as she types, and publishes, schedules or updates from there.
 */
export default function Notes(props: Props) {
    const [notes, setNotes] = useState<NoteRow[]>(props.notes);
    const works = props.works;
    const W = new Map(works.map((w) => [w.slug, w]));
    const TODAY = props.now.slice(0, 10);
    const [wf, setWf] = useState(props.w && W.has(props.w) ? props.w : '');
    const [query, setQuery] = useState('');
    const [q, setQ] = useState('');
    const [openId, setOpenId] = useState<number | null>(null);
    const [fresh, setFresh] = useState<number | null>(null);
    const [justId, setJustId] = useState<number | null>(null);
    const [opened, setOpened] = useState<Set<string>>(new Set());
    const [out, setOut] = useState(false);
    const [pop, setPop] = useState<Pop | null>(null);
    const [popOn, setPopOn] = useState(false);
    const [popAt, setPopAt] = useState<CSSProperties>({});
    const [sheet, setSheet] = useState(false);
    const [sb, setSb] = useState<{ on: boolean; link: boolean; at: CSSProperties; b: boolean; i: boolean }>({ on: false, link: false, at: {}, b: false, i: false });
    const [kbOn, setKbOn] = useState(false);
    const [kbBottom, setKbBottom] = useState(0);
    const L = useRef<HTMLDivElement>(null);
    const main = useRef<HTMLDivElement>(null);
    const popBox = useRef<HTMLDivElement>(null);
    const view = useRef<EditorView | null>(null);
    const flushRef = useRef<() => Promise<void>>(async () => undefined);
    const fx = useRef<(() => void) | null>(null);
    const justTimer = useRef(0);
    const qTimer = useRef(0);
    const linkInput = useRef<HTMLInputElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const get = (id: number) => notes.find((n) => n.id === id);
    const put = (n: NoteRow) => setNotes((xs) => (xs.some((x) => x.id === n.id) ? xs.map((x) => (x.id === n.id ? n : x)) : [n, ...xs]));
    const drop = (id: number) => setNotes((xs) => xs.filter((x) => x.id !== id));

    /* things to do once the list has drawn (animate a row in, scroll to it) */
    useLayoutEffect(() => {
        const f = fx.current;
        fx.current = null;
        f?.();
    });

    /* new hearts count as seen once she has been here */
    useEffect(() => {
        const any = props.notes.some((n) => n.newLikes > 0);
        const send = () => any && beacon('/notes/seen', {});
        window.addEventListener('pagehide', send);

        return () => {
            send();
            window.removeEventListener('pagehide', send);
        };
    }, [props.notes]);

    /* a note asked for by the address opens */
    useEffect(() => {
        if (props.open && get(props.open)) {
            window.setTimeout(() => open(props.open!, true, true), RM() ? 0 : 260);
        }
    }, []);

    /* ---------- the list ---------- */
    const wt = (slug: string | null) => (slug ? (W.get(slug)?.title ?? '') : '');
    const isPub = (slug: string | null) => !!slug && !!W.get(slug)?.public;
    const followers = (slug: string | null) => (slug && isPub(slug) ? (W.get(slug)?.followers ?? 0) : 0);
    const wlab = (slug: string | null): ReactNode =>
        !slug ? '' : isPub(slug) ? wt(slug) : (
            <span className="nt-lk" title={`Hidden on the Diary until ${wt(slug)} is public`}>
                {I.lock14}
                {wt(slug)}
            </span>
        );
    const hit = (n: NoteRow) => !q || `${n.text} ${wt(n.work)}`.toLowerCase().includes(q.toLowerCase());
    const hl = (t: string): ReactNode => {
        if (!q) {
            return t;
        }
        const low = t.toLowerCase();
        const k = q.toLowerCase();
        const outp: ReactNode[] = [];
        let i = 0;
        let j: number;
        while ((j = low.indexOf(k, i)) >= 0) {
            outp.push(t.slice(i, j), <mark className="nt-hl" key={j}>{t.slice(j, j + k.length)}</mark>);
            i = j + k.length;
        }
        outp.push(t.slice(i));

        return outp;
    };
    function snippet(n: NoteRow) {
        const t = n.text.replace(/\s+/g, ' ').trim();
        if (!q) {
            return t;
        }
        const i = t.toLowerCase().indexOf(q.toLowerCase());
        if (i < 40) {
            return t;
        }

        return '… ' + t.slice(t.lastIndexOf(' ', i - 20) + 1);
    }

    function status(n: NoteRow) {
        if (n.state === 'sch' && n.at) {
            return (
                <span className="nt-st sch">
                    {I.clock15}
                    <span>{atFmt(n.at)}</span>
                </span>
            );
        }
        if (n.state === 'draft') {
            return (
                <span className="nt-st">
                    {I.pen15}
                    <span>Draft · {ago(n.edited)}</span>
                </span>
            );
        }
        if (n.changed) {
            return (
                <span className="nt-st ed" title="Edits not out">
                    {I.pen15}
                    <span>{dShort(n.day!)}</span>
                </span>
            );
        }

        return <span className="nt-st lv">{n.day ? dShort(n.day) : ''}</span>;
    }

    function row(n: NoteRow) {
        const just = n.id === justId;

        return (
            <button className={`nl-r${n.state !== 'live' ? ' dr' : ''}${just ? ' just' : ''}`} type="button" data-id={n.id} aria-expanded="false" key={n.id} onClick={() => open(n.id)}>
                <span className="d">{status(n)}</span>
                <span className="tx">
                    <span>{hl(snippet(n)) || <i className="nt-emp">Empty</i>}</span>
                </span>
                <span className="w">{wlab(n.work)}</span>
                <span className="hs">
                    {just && (
                        <span className="nt-undo">
                            <span className="k-meta">Published</span>
                            <span
                                className="w-act"
                                role="button"
                                tabIndex={0}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    void undo(n.id);
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        void undo(n.id);
                                    }
                                }}
                            >
                                Undo
                            </span>
                        </span>
                    )}
                    {n.state === 'live' && (
                        <span className="nt-h">
                            {I.heart15}
                            <span>{n.likes}</span>
                            {n.newLikes > 0 && <em>+{n.newLikes}</em>}
                        </span>
                    )}
                </span>
            </button>
        );
    }

    const item = (n: NoteRow) => (n.id === openId ? card(n) : row(n));
    const xs = notes.filter((n) => (!wf || n.work === wf) && (hit(n) || n.id === openId));
    const waiting = xs
        .filter((n) => n.state !== 'live')
        .sort((a, b) => {
            if (a.id === fresh) {
                return -1;
            }
            if (b.id === fresh) {
                return 1;
            }
            if (a.state !== b.state) {
                return a.state === 'sch' ? -1 : 1;
            }

            return a.state === 'sch' ? (a.at! < b.at! ? -1 : 1) : b.edited.localeCompare(a.edited);
        });
    const live = xs.filter((n) => n.state === 'live').sort((a, b) => (a.day! < b.day! ? 1 : a.day! > b.day! ? -1 : b.id - a.id));
    const by = new Map<string, NoteRow[]>();
    live.forEach((n) => {
        const k = n.day!.slice(0, 7);
        by.set(k, [...(by.get(k) ?? []), n]);
    });
    const keys = [...by.keys()];
    const yr = TODAY.slice(0, 4);
    const count = (n: number) => `${n} ${n === 1 ? 'note' : 'notes'}`;
    const toggleFold = (k: string) => foldTo(k);

    function month(k: string, ns: NoteRow[], canFold: boolean) {
        const y = k.slice(0, 4);
        const m = MONTH[Number(k.slice(5)) - 1];

        return (
            <section className="nl-m" data-g={k} key={k}>
                <h2>
                    {canFold ? (
                        <button className="nt-fh" type="button" data-fold={k} aria-expanded="true" onClick={() => toggleFold(k)}>
                            {m} <small>{y}</small>
                            {I.chev18}
                        </button>
                    ) : (
                        <>
                            {m} <small>{y}</small>
                        </>
                    )}
                </h2>
                <div className="nl-rows">{ns.map(item)}</div>
            </section>
        );
    }
    const fold = (k: string, label: string, n: number, kind: 'm' | 'y') => (
        <button className={`nt-fold${kind === 'y' ? ' y' : ''}`} type="button" data-fold={k} aria-expanded="false" key={'f' + k} onClick={() => toggleFold(k)}>
            <span className="m">{label}</span>
            <span className="n">{count(n)}</span>
            {I.chev18}
        </button>
    );

    function listBody(): ReactNode[] {
        const h: ReactNode[] = [];
        if (q) {
            h.push(
                <p className="nt-cnt" key="cnt">
                    {count(xs.length)}
                </p>,
            );
        }
        if (waiting.length) {
            h.push(
                <section className="nl-m" data-g="wait" key="wait">
                    <h2>Not out yet</h2>
                    <div className="nl-rows">{waiting.map(item)}</div>
                </section>,
            );
        }
        if (q || wf) {
            keys.forEach((k) => h.push(month(k, by.get(k)!, false)));
        } else {
            const cur = keys.filter((k) => k.startsWith(yr));
            const old = keys.filter((k) => !k.startsWith(yr));
            cur.forEach((k, i) => h.push(i < 2 || opened.has(k) ? month(k, by.get(k)!, i >= 2) : fold(k, MONTH[Number(k.slice(5)) - 1], by.get(k)!.length, 'm')));
            const years = [...new Set(old.map((k) => k.slice(0, 4)))];
            if (years.length) {
                h.push(
                    <div className="nt-yrs" key="yrs">
                        {years.map((y) => {
                            const ks = old.filter((k) => k.startsWith(y));
                            const n = ks.reduce((a, k) => a + by.get(k)!.length, 0);

                            return opened.has(y) ? (
                                <section className="nl-m nl-y" data-g={y} key={y}>
                                    <h2>
                                        <button className="nt-fh" type="button" data-fold={y} aria-expanded="true" onClick={() => toggleFold(y)}>
                                            {y} <small>{count(n)}</small>
                                            {I.chev18}
                                        </button>
                                    </h2>
                                    {ks.map((k) => (
                                        <Fragment key={k}>
                                            <h3 className="nl-mh">{MONTH[Number(k.slice(5)) - 1]}</h3>
                                            <div className="nl-rows">{by.get(k)!.map(item)}</div>
                                        </Fragment>
                                    ))}
                                </section>
                            ) : (
                                fold(y, y, n, 'y')
                            );
                        })}
                    </div>,
                );
            }
        }
        if (!xs.length) {
            h.push(
                <div className="nt-empty" key="empty">
                    <h2>{q ? `No notes match “${q}”.` : wf ? `No notes about ${wt(wf)} yet.` : 'No notes yet.'}</h2>
                </div>,
            );
        }

        return h;
    }

    /* a fold opens in place (the page keeps its place) and its lines settle in */
    function foldTo(k: string) {
        const sel = `[data-g="${k}"],.nt-fold[data-fold="${k}"]`;
        const was = L.current?.querySelector(sel);
        const top = was ? was.getBoundingClientRect().top : 0;
        if (openId) {
            closeOpen(true);
        }
        setOpened((o) => {
            const n = new Set(o);
            if (n.has(k)) {
                n.delete(k);
            } else {
                n.add(k);
            }

            return n;
        });
        fx.current = () => {
            const now = L.current?.querySelector(sel) as HTMLElement | null;
            if (!now) {
                return;
            }
            const d = now.getBoundingClientRect().top - top;
            if (Math.abs(d) > 1) {
                window.scrollBy(0, d);
            }
            if (now.matches('section')) {
                [...now.querySelectorAll('.nl-rows>*,.nl-mh')].forEach((c, i) => {
                    if (RM()) {
                        c.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

                        return;
                    }
                    c.animate([{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(6px)' }, { opacity: 1, filter: 'none', transform: 'none' }], {
                        duration: 380,
                        delay: Math.min(i, 12) * 30,
                        easing: EASE,
                        fill: 'backwards',
                    });
                });
            } else {
                inA(now);
            }
            ((now.querySelector('[data-fold]') as HTMLElement | null) ?? now).focus({ preventScroll: true });
        };
    }

    /* a new work or search fades the list out, and the new one settles in */
    async function swap(change: () => void) {
        if (openId) {
            closeOpen(true);
        }
        if (RM()) {
            change();

            return;
        }
        setOut(true);
        await wait(160);
        change();
        setOut(false);
        fx.current = () => settle(L.current);
    }
    function pickWork(v: string) {
        void swap(() => setWf(v));
        const u = new URLSearchParams();
        if (v) {
            u.set('w', v);
        }
        window.history.replaceState(window.history.state, '', '/notes' + (v ? '?' + u : ''));
    }

    /* ---------- open / close ---------- */
    function open(id: number, focus = true, center = false) {
        if (openId === id) {
            return;
        }
        if (openId) {
            closeOpen(true);
        }
        const r = L.current?.querySelector(`.nl-r[data-id="${id}"]`) as HTMLElement | null;
        const h = r?.offsetHeight ?? 0;
        setOpenId(id);
        fx.current = () => {
            const el = L.current?.querySelector(`.nl-o[data-id="${id}"]`) as HTMLElement | null;
            if (!el) {
                return;
            }
            grow(el, h);
            if (center) {
                el.scrollIntoView({ block: 'center' });
            }
            if (focus) {
                window.setTimeout(() => placeEnd(), RM() ? 0 : 200);
            }
        };
    }
    function placeEnd() {
        const v = view.current;
        if (!v) {
            return;
        }
        v.focus();
        v.dispatch(v.state.tr.setSelection(Selection.atEnd(v.state.doc)).scrollIntoView());
    }
    function closeOpen(quiet = false, after_?: () => void) {
        const id = openId;
        popClose();
        if (id == null) {
            return;
        }
        void flushRef.current();
        const n = get(id);
        const el = L.current?.querySelector(`.nl-o[data-id="${id}"]`) as HTMLElement | null;
        const empty = n && id === fresh && !n.text.trim() && !n.photo && (!view.current || isEmptyDoc(view.current.state.doc));
        const finish = () => {
            setOpenId(null);
            setKbOn(false);
            if (empty) {
                drop(id);
                void json('DELETE', `/notes/${id}`);
            }
            if (id === fresh) {
                setFresh(null);
            }
            if (!empty && !quiet) {
                fx.current = () => inA(L.current?.querySelector(`.nl-r[data-id="${id}"]`) ?? null);
            }
            after_?.();
        };
        if (quiet || !el) {
            finish();

            return;
        }
        if (empty) {
            bye(el, finish);
        } else {
            shrink(el, 56, finish);
        }
    }

    /* Esc closes the popover, then the writer (focus goes back to its line) */
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') {
                return;
            }
            if (pop && !(e.target as Element).closest?.('.k-select.open')) {
                e.preventDefault();
                popClose(true);

                return;
            }
            if (sb.on) {
                setSb((s) => ({ ...s, on: false, link: false }));

                return;
            }
            const el = L.current?.querySelector('.nl-o');
            if (el && el.contains(document.activeElement) && openId) {
                const id = openId;
                closeOpen(false, () => window.setTimeout(() => (L.current?.querySelector(`.nl-r[data-id="${id}"]`) as HTMLElement | null)?.focus(), 0));
            }
        };
        document.addEventListener('keydown', key);

        return () => document.removeEventListener('keydown', key);
    });

    /* ---------- New note ---------- */
    async function newNote() {
        if (fresh && openId === fresh) {
            placeEnd();

            return;
        }
        if (openId) {
            closeOpen(true);
        }
        if (q || wf) {
            setQuery('');
            setQ('');
            setWf('');
            window.history.replaceState(window.history.state, '', '/notes');
        }
        const { status: st, data } = await json<{ note: NoteRow }>('POST', '/notes');
        if (st !== 201 || !data.note) {
            return;
        }
        const n = data.note;
        put(n);
        setFresh(n.id);
        setOpenId(n.id);
        fx.current = () => {
            const el = L.current?.querySelector(`.nl-o[data-id="${n.id}"]`) as HTMLElement | null;
            if (!el) {
                return;
            }
            const sec = el.closest('.nl-m');
            if (sec && sec.querySelectorAll('.nl-r,.nl-o').length === 1) {
                inA(sec);
            }
            grow(el, 0);
            el.scrollIntoView({ block: 'nearest', behavior: RM() ? 'auto' : 'smooth' });
            window.setTimeout(() => placeEnd(), RM() ? 0 : 220);
        };
    }

    /* ---------- after publishing: the writer folds into its line, tinted, with Undo for a while ---------- */
    function published(id: number, note: NoteRow) {
        const el = L.current?.querySelector(`.nl-o[data-id="${id}"]`) as HTMLElement | null;
        const finish = () => {
            put(note);
            setOpenId(null);
            setFresh(null);
            setKbOn(false);
            setJustId(id);
            window.clearTimeout(justTimer.current);
            justTimer.current = window.setTimeout(() => {
                const u = L.current?.querySelector(`.nl-r[data-id="${id}"] .nt-undo`) as HTMLElement | null;
                const r = L.current?.querySelector(`.nl-r[data-id="${id}"]`);
                r?.classList.remove('just');
                if (u) {
                    bye(u, () => setJustId(null));
                } else {
                    setJustId(null);
                }
            }, 8000);
            fx.current = () => {
                const r = L.current?.querySelector(`.nl-r[data-id="${id}"]`);
                if (r) {
                    inA(r);
                    r.scrollIntoView({ block: 'nearest', behavior: RM() ? 'auto' : 'smooth' });
                }
            };
        };
        if (el) {
            shrink(el, 56, finish);
        } else {
            finish();
        }
    }
    function folded(id: number, note: NoteRow) {
        const el = L.current?.querySelector(`.nl-o[data-id="${id}"]`) as HTMLElement | null;
        const finish = () => {
            put(note);
            setOpenId(null);
            setFresh(null);
            setKbOn(false);
            fx.current = () => {
                const r = L.current?.querySelector(`.nl-r[data-id="${id}"]`);
                if (r) {
                    r.classList.add('just');
                    inA(r);
                    window.setTimeout(() => r.classList.remove('just'), 1600);
                }
            };
        };
        if (el) {
            shrink(el, 56, finish);
        } else {
            finish();
        }
    }
    async function undo(id: number) {
        const { status: st, data } = await json<{ note: NoteRow }>('POST', `/notes/${id}/undo`);
        if (st === 200 && data.note) {
            window.clearTimeout(justTimer.current);
            setJustId(null);
            put(data.note);
            fx.current = () => {
                settle(L.current);
                open(id);
            };
        }
    }

    /* ---------- popovers: one at a time ---------- */
    function popShow(p: Pop) {
        setPop(p);
        setPopOn(false);
        setSheet(phone());
        p.btn.setAttribute('aria-expanded', 'true');
    }
    function popClose(back = false) {
        if (!pop) {
            return;
        }
        const b = pop.btn;
        b.setAttribute('aria-expanded', 'false');
        setPopOn(false);
        window.setTimeout(() => setPop((cur) => (cur && cur.btn === b ? null : cur)), RM() ? 0 : 220);
        if (back) {
            b.focus();
        }
    }
    useLayoutEffect(() => {
        if (!pop || popOn) {
            return;
        }
        const p = popBox.current;
        const m = main.current;
        if (!p || !m) {
            return;
        }
        if (!sheet) {
            const r = pop.btn.getBoundingClientRect();
            const mr = m.getBoundingClientRect();
            const w = p.offsetWidth;
            const h = p.offsetHeight;
            const side = pop.kind === 'aw' ? 'left' : pop.kind === 'del' ? 'above' : 'right';
            let x = side === 'left' ? r.left - mr.left : r.right - mr.left - w;
            x = Math.max(0, Math.min(x, mr.width - w));
            let y = r.bottom - mr.top + 10;
            if (side === 'above' || (r.bottom + h + 20 > window.innerHeight && r.top - h - 20 > 0)) {
                y = r.top - mr.top - h - 10;
            }
            setPopAt({ left: x, top: y });
        }
        requestAnimationFrame(() => setPopOn(true));
    }, [pop, popOn, sheet]);
    useEffect(() => {
        if (!pop) {
            return;
        }
        const down = (e: MouseEvent) => {
            const t = e.target as Element;
            if (!popBox.current?.contains(t) && !pop.btn.contains(t) && !t.closest('.k-select')) {
                popClose();
            }
        };
        document.addEventListener('mousedown', down);

        return () => document.removeEventListener('mousedown', down);
    });

    /* ---------- the writer ---------- */
    function card(n: NoteRow) {
        return (
            <NoteCard
                key={'o' + n.id}
                note={n}
                fresh={n.id === fresh}
                followers={followers(n.work)}
                wlab={wlab(n.work)}
                popFor={pop && popOn && pop.id === n.id ? pop.kind : null}
                viewRef={view}
                flushRef={flushRef}
                onSaved={put}
                onAct={(a, btn) => act(n, a, btn)}
                onFocus={(on) => setKbOn(on && phone())}
                onLink={() => startLink()}
            />
        );
    }

    async function act(n: NoteRow, a: string, btn: HTMLElement) {
        if (a === 'close') {
            closeOpen();

            return;
        }
        if (a === 'photo') {
            fileInput.current!.dataset.id = String(n.id);
            fileInput.current!.click();

            return;
        }
        if (a === 'rmph') {
            const ph = btn.closest('.nt-ph') as HTMLElement | null;
            const go = async () => {
                const { data } = await json<{ note: NoteRow }>('PATCH', `/notes/${n.id}`, { photo: null });
                if (data.note) {
                    put(data.note);
                }
            };
            if (ph) {
                bye(ph, () => void go());
            } else {
                void go();
            }

            return;
        }
        if (a === 'tell') {
            const quiet = !n.quiet;
            put({ ...n, quiet });
            const icon = btn.querySelector('.ic');
            if (icon && !RM()) {
                icon.animate([{ transform: 'rotate(-14deg) scale(.8)' }, { transform: 'rotate(8deg)' }, { transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.34,1.45,.64,1)' });
            }
            const { data } = await json<{ note: NoteRow }>('PATCH', `/notes/${n.id}`, { quiet });
            if (data.note) {
                put(data.note);
            }

            return;
        }
        if (pop && pop.btn === btn) {
            popClose(true);

            return;
        }
        if (a === 'aw' || a === 'del') {
            popShow({ kind: a, id: n.id, btn });

            return;
        }
        if (a === 'sch') {
            popShow(offline() ? { kind: 'off', id: n.id, btn, what: 'Schedule' } : { kind: 'sch', id: n.id, btn });

            return;
        }
        if (a === 'go') {
            await flushRef.current();
            if (offline()) {
                popShow({ kind: 'off', id: n.id, btn, what: n.state === 'live' ? 'Update' : 'Publish' });

                return;
            }
            popClose();
            btn.classList.add('busy');
            const [{ status: st, data }] = await Promise.all([json<{ note: NoteRow }>('POST', `/notes/${n.id}/publish`), wait(RM() ? 300 : 900)]);
            btn.classList.remove('busy');
            if (st !== 200 || !data.note) {
                if (st === 0) {
                    popShow({ kind: 'off', id: n.id, btn, what: n.state === 'live' ? 'Update' : 'Publish' });
                }

                return;
            }
            if (n.state === 'live') {
                folded(n.id, data.note);
            } else {
                published(n.id, data.note);
            }
        }
    }

    /* photo: one per note */
    async function upload(f: File, id: number) {
        const body = new FormData();
        body.append('image', f);
        try {
            const res = await fetch(`/notes/${id}/photo`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.note) {
                put(data.note);
                fx.current = () => inA(L.current?.querySelector(`.nl-o[data-id="${id}"] .nt-ph`) ?? null);
            } else {
                window.dispatchEvent(new CustomEvent('admin:note-error', { detail: data.errors?.image?.[0] ?? 'That picture couldn’t be added. Try again.' }));
            }
        } catch {
            window.dispatchEvent(new CustomEvent('admin:note-error', { detail: 'That picture couldn’t be added. Try again.' }));
        }
    }

    /* ---------- formatting: the selection bar (computer) and the keyboard row (phone) ---------- */
    const syncBar = useCallback(() => {
        /* read the page's selection on the next frame, once the editor has caught up with it */
        requestAnimationFrame(() => {
            const v = view.current;
            const sel = window.getSelection();
            const inside = !!v && !!sel && sel.rangeCount > 0 && !sel.isCollapsed && v.dom.contains(sel.anchorNode);
            setSb((s) => {
                if (s.link) {
                    return s;
                }
                if (!inside || phone() || !main.current) {
                    return s.on ? { ...s, on: false } : s;
                }
                const r = sel!.getRangeAt(0).getBoundingClientRect();
                const m = main.current.getBoundingClientRect();
                const w = 140;

                return {
                    on: true,
                    link: false,
                    b: markActive(v!.state, 'bold'),
                    i: markActive(v!.state, 'italic'),
                    at: { left: Math.max(0, Math.min(r.left + r.width / 2 - m.left - w / 2, m.width - w)), top: r.top - m.top - 62 },
                };
            });
        });
    }, []);
    useEffect(() => {
        document.addEventListener('selectionchange', syncBar);

        return () => document.removeEventListener('selectionchange', syncBar);
    }, [syncBar]);
    function fmt(f: 'b' | 'i' | 'a') {
        const v = view.current;
        if (!v) {
            return;
        }
        if (f === 'b') {
            toggleBold(v.state, v.dispatch);
        } else if (f === 'i') {
            toggleItalic(v.state, v.dispatch);
        } else {
            startLink();

            return;
        }
        syncBar();
    }
    function startLink(): boolean {
        const v = view.current;
        if (!v || v.state.selection.empty) {
            return false;
        }
        if (markActive(v.state, 'link')) {
            setLink('')(v.state, v.dispatch);

            return true;
        }
        if (phone()) {
            const u = window.prompt('Paste a link');
            if (u) {
                setLink(u.trim())(v.state, v.dispatch);
            }

            return true;
        }
        setSb((s) => ({ ...s, on: true, link: true }));
        window.setTimeout(() => linkInput.current?.focus(), 20);

        return true;
    }
    function doneLink() {
        const v = view.current;
        const u = linkInput.current?.value.trim() ?? '';
        setSb((s) => ({ ...s, on: false, link: false }));
        if (!v) {
            return;
        }
        v.focus();
        if (u) {
            setLink(u)(v.state, v.dispatch);
        }
    }
    /* the keyboard row rides on top of the phone's keyboard */
    useEffect(() => {
        document.body.classList.toggle('nt-kbon', kbOn);
        const vv = window.visualViewport;
        if (!vv) {
            return;
        }
        const place = () => setKbBottom(Math.max(0, window.innerHeight - (vv.height + vv.offsetTop)));
        place();
        vv.addEventListener('resize', place);
        vv.addEventListener('scroll', place);

        return () => {
            vv.removeEventListener('resize', place);
            vv.removeEventListener('scroll', place);
            document.body.classList.remove('nt-kbon');
        };
    }, [kbOn]);

    /* ---------- the popovers ---------- */
    const popNote = pop ? get(pop.id) : undefined;
    function popover(): ReactNode {
        if (!pop || !popNote) {
            return null;
        }
        const cls = `nt-pop${popOn ? ' on' : ''}${sheet ? ' sheet' : ''}`;
        const style = sheet ? {} : popAt;
        if (pop.kind === 'aw') {
            return <AboutPop key="aw" cls={cls} style={style} box={popBox} note={popNote} works={works} isPub={isPub} onPick={(slug) => pickAbout(popNote, slug)} />;
        }
        if (pop.kind === 'sch') {
            return (
                <SchedulePop
                    key="sch"
                    cls={cls}
                    style={style}
                    box={popBox}
                    note={popNote}
                    now={props.now}
                    onClose={() => popClose(true)}
                    onDone={(n, folds) => {
                        popClose();
                        if (folds) {
                            folded(n.id, n);
                        } else {
                            put(n);
                        }
                    }}
                    flush={() => flushRef.current()}
                />
            );
        }
        if (pop.kind === 'del') {
            const liveNote = popNote.state === 'live';

            return (
                <div className={`${cls} sm`} role="alertdialog" aria-label="Delete" ref={popBox} style={style}>
                    <h3>{liveNote ? 'Delete this note?' : 'Delete this draft?'}</h3>
                    {liveNote && (
                        <p className="pp">
                            It goes from the Diary{popNote.likes ? ` and its ${popNote.likes} ${popNote.likes === 1 ? 'heart goes' : 'hearts go'} with it` : ''}.
                        </p>
                    )}
                    <div className="acts">
                        <button className="k-btn sm line nt-dng" type="button" onClick={(e) => void remove(popNote, e.currentTarget)}>
                            <span>Delete</span>
                            <Ring />
                        </button>
                        <button className="w-act" type="button" autoFocus onClick={() => popClose(true)}>
                            Cancel
                        </button>
                    </div>
                </div>
            );
        }

        return (
            <div className={`${cls} sm`} role="alertdialog" aria-label="No connection" ref={popBox} style={style}>
                <h3>No connection</h3>
                <p className="pp">{pop.what} when you’re back online. Your note is safe on this device.</p>
                <div className="acts">
                    <button className="k-btn sm line" type="button" autoFocus onClick={() => popClose(true)}>
                        <span>OK</span>
                    </button>
                </div>
            </div>
        );
    }
    async function pickAbout(n: NoteRow, slug: string) {
        put({ ...n, work: slug || null });
        window.setTimeout(() => popClose(true), RM() ? 0 : 160);
        const { data } = await json<{ note: NoteRow }>('PATCH', `/notes/${n.id}`, { work: slug || null });
        if (data.note) {
            put(data.note);
        }
    }
    async function remove(n: NoteRow, btn: HTMLElement) {
        btn.classList.add('busy');
        const [{ status: st }] = await Promise.all([json('DELETE', `/notes/${n.id}`), wait(RM() ? 300 : 700)]);
        btn.classList.remove('busy');
        if (st !== 204 && st !== 200) {
            return;
        }
        popClose();
        const el = L.current?.querySelector(`.nl-o[data-id="${n.id}"]`) as HTMLElement | null;
        const finish = () => {
            setOpenId(null);
            setFresh(null);
            setKbOn(false);
            drop(n.id);
        };
        if (el) {
            const sec = el.closest('.nl-m') as HTMLElement | null;
            const last = sec && sec.querySelectorAll('.nl-r,.nl-o').length === 1;
            bye(last && sec ? sec : el, finish);
        } else {
            finish();
        }
    }

    const workOptions = [{ value: '', label: 'All works' }, ...works.filter((w) => notes.some((n) => n.work === w.slug)).map((w) => ({ value: w.slug, label: w.title }))];

    return (
        <AdminLayout section="notes">
            <div className="b-wh">
                <h1 className="b-h" id="sT">
                    Notes
                </h1>
                <button className={`k-btn nt-new${openId ? ' line' : ''}`} type="button" id="ntNew" onClick={() => void newNote()}>
                    {I.plus18}
                    <span>New note</span>
                </button>
            </div>
            <div className="nt-main" ref={main} style={{ position: 'relative' }}>
                <div className="nt-tools">
                    <KSelect className="cm-wsw nt-wsw" buttonClassName="k-sbtn cm-ws" label="Work" value={wf} options={workOptions} onPick={pickWork} />
                    <label className="k-search cm-q nt-q" htmlFor="ntQ">
                        {I.search}
                        <input
                            id="ntQ"
                            type="search"
                            placeholder="Search"
                            autoComplete="off"
                            aria-label="Search notes"
                            value={query}
                            onChange={(e) => {
                                const v = e.target.value;
                                setQuery(v);
                                window.clearTimeout(qTimer.current);
                                qTimer.current = window.setTimeout(() => void swap(() => setQ(v.trim())), 180);
                            }}
                        />
                        {query && (
                            <button
                                className="ad-ic"
                                type="button"
                                aria-label="Clear search"
                                onClick={() => {
                                    setQuery('');
                                    void swap(() => setQ(''));
                                    document.getElementById('ntQ')?.focus();
                                }}
                            >
                                {I.x16}
                            </button>
                        )}
                    </label>
                </div>
                <div className={`nt-list${out ? ' nt-out' : ''}`} id="ntL" ref={L} aria-live="polite">
                    {listBody()}
                </div>
                <div className={`nt-sb${sb.on ? ' on' : ''}${sb.link ? ' ln' : ''}`} role="toolbar" aria-label="Format" style={sb.at} onMouseDown={(e) => !(e.target as Element).closest('input') && e.preventDefault()}>
                    <button type="button" aria-label="Bold" className={sb.b ? 'on' : ''} onClick={() => fmt('b')}>
                        <b>B</b>
                    </button>
                    <button type="button" aria-label="Italic" className={sb.i ? 'on' : ''} onClick={() => fmt('i')}>
                        <i>I</i>
                    </button>
                    <button type="button" aria-label="Link" onClick={() => fmt('a')}>
                        {I.link18}
                    </button>
                    <span className="nt-sbl">
                        <input
                            ref={linkInput}
                            placeholder="Paste a link"
                            aria-label="Link address"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    doneLink();
                                }
                                if (e.key === 'Escape') {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    setSb((s) => ({ ...s, on: false, link: false }));
                                    view.current?.focus();
                                }
                            }}
                        />
                        <button type="button" className="ad-ic" aria-label="Done" onClick={doneLink}>
                            {I.check17}
                        </button>
                    </span>
                </div>
                {pop && !sheet && popover()}
            </div>
            {createPortal(
                <>
                    <div className={`nt-kb${kbOn ? ' on' : ''}`} role="toolbar" aria-label="Format" style={{ bottom: kbBottom }} onMouseDown={(e) => e.preventDefault()}>
                        <button type="button" aria-label="Bold" onClick={() => fmt('b')}>
                            <b>B</b>
                        </button>
                        <button type="button" aria-label="Italic" onClick={() => fmt('i')}>
                            <i>I</i>
                        </button>
                        <button type="button" aria-label="Link" onClick={() => fmt('a')}>
                            {I.link20}
                        </button>
                        <button
                            type="button"
                            aria-label="Photo"
                            onClick={() => {
                                if (openId) {
                                    fileInput.current!.dataset.id = String(openId);
                                    fileInput.current!.click();
                                }
                            }}
                        >
                            {I.img20}
                        </button>
                        <span />
                        <button
                            type="button"
                            aria-label="Done"
                            onClick={() => {
                                (document.activeElement as HTMLElement | null)?.blur();
                                setKbOn(false);
                            }}
                        >
                            {I.chev20}
                        </button>
                    </div>
                    <div className={`nt-scrim${pop && sheet && popOn ? ' on' : ''}`} onClick={() => popClose()} />
                    {pop && sheet && popover()}
                </>,
                document.body,
            )}
            <input
                type="file"
                accept="image/*"
                hidden
                ref={fileInput}
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    const id = Number(e.target.dataset.id);
                    e.target.value = '';
                    if (f && id) {
                        void upload(f, id);
                    }
                }}
            />
        </AdminLayout>
    );
}

/* ------------------------------------------------------------------ one note, open */
function NoteCard({
    note,
    fresh,
    followers,
    wlab,
    popFor,
    viewRef,
    flushRef,
    onSaved,
    onAct,
    onFocus,
    onLink,
}: {
    note: NoteRow;
    fresh: boolean;
    followers: number;
    wlab: ReactNode;
    popFor: Pop['kind'] | null;
    viewRef: React.MutableRefObject<EditorView | null>;
    flushRef: React.MutableRefObject<() => Promise<void>>;
    onSaved: (n: NoteRow) => void;
    onAct: (a: string, btn: HTMLElement) => void;
    onFocus: (on: boolean) => void;
    onLink: () => boolean;
}) {
    const host = useRef<HTMLDivElement>(null);
    const box = useRef<HTMLDivElement>(null);
    const [save, setSave] = useState<'saved' | 'saving' | 'device'>(() => (localStorage.getItem(KEEP + note.id) ? 'device' : 'saved'));
    const [typing, setTyping] = useState(false);
    const [empty, setEmpty] = useState(() => !note.text.trim());
    const [error, setError] = useState('');
    const timer = useRef(0);
    const pending = useRef<Json | null>(null);
    const noteRef = useRef(note);
    noteRef.current = note;

    async function send(): Promise<void> {
        window.clearTimeout(timer.current);
        const body = pending.current;
        if (!body) {
            return;
        }
        pending.current = null;
        const { status: st, data } = await json<{ note: NoteRow }>('PATCH', `/notes/${noteRef.current.id}`, { body });
        if (st === 200 && data.note) {
            localStorage.removeItem(KEEP + noteRef.current.id);
            onSaved(data.note);
            setSave('saved');
            setTyping(false);
        } else {
            /* no connection: the words wait on this device and go when it's back */
            localStorage.setItem(KEEP + noteRef.current.id, JSON.stringify(body));
            setSave('device');
        }
        const sv = box.current?.querySelector('.nt-sv');
        inA(sv ?? null);
    }

    useEffect(() => {
        const kept = localStorage.getItem(KEEP + note.id);
        let doc = noteDoc(note.body);
        if (kept) {
            try {
                doc = noteDoc(JSON.parse(kept));
                pending.current = JSON.parse(kept);
            } catch {
                localStorage.removeItem(KEEP + note.id);
            }
        }
        const v = new EditorView(host.current!, {
            state: EditorState.create({ doc, plugins: notePlugins(onLink) }),
            attributes: (state) => ({
                class: `nt-eb${isEmptyDoc(state.doc) ? ' ph' : ''}`,
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label': 'Note',
                'data-ph': 'Start writing',
            }),
            dispatchTransaction(tr) {
                const s = v.state.apply(tr);
                v.updateState(s);
                if (tr.docChanged) {
                    setEmpty(isEmptyDoc(s.doc));
                    setTyping(true);
                    setSave('saving');
                    pending.current = s.doc.toJSON() as Json;
                    window.clearTimeout(timer.current);
                    timer.current = window.setTimeout(() => void send(), 600);
                }
            },
            handleDOMEvents: {
                focus: () => {
                    onFocus(true);

                    return false;
                },
                blur: () => {
                    window.setTimeout(() => {
                        if (!box.current?.contains(document.activeElement) && !document.querySelector('.nt-kb')?.contains(document.activeElement)) {
                            onFocus(false);
                        }
                    }, 120);

                    return false;
                },
            },
        });
        viewRef.current = v;
        flushRef.current = send;
        const online = () => pending.current && void send();
        window.addEventListener('online', online);
        const err = (e: Event) => setError((e as CustomEvent<string>).detail);
        window.addEventListener('admin:note-error', err);

        return () => {
            void send();
            window.removeEventListener('online', online);
            window.removeEventListener('admin:note-error', err);
            if (viewRef.current === v) {
                viewRef.current = null;
            }
            v.destroy();
        };
    }, []);

    const live = note.state === 'live';
    const sch = note.state === 'sch';
    const dirty = live && (note.changed || typing);
    const label = fresh ? 'New note' : sch ? 'Scheduled' : live ? (dirty ? 'Edits not out' : `${note.day ? dShort(note.day) : ''} · Published`) : 'Draft';
    const icon = sch ? I.clock15 : live && !dirty ? I.globe15 : I.pen15;
    const go = live ? 'Update' : sch ? 'Publish now' : 'Publish';
    const act = (a: string) => (e: React.MouseEvent<HTMLElement>) => onAct(a, e.currentTarget);

    return (
        <div className="nl-o" data-id={note.id} role="group" aria-label="Note" ref={box}>
            <div className="nl-oh">
                <span className={`nt-st${sch ? ' sch' : ''}`}>
                    {icon}
                    <span>{label}</span>
                </span>
                <span className={`nt-sv${save === 'saving' ? ' ing' : ''}`} aria-live="polite">
                    {error ? (
                        <>
                            {I.alert15}
                            <span>{error}</span>
                        </>
                    ) : save === 'saving' ? (
                        <>
                            {I.dot}
                            <span>Saving</span>
                        </>
                    ) : save === 'device' ? (
                        <>
                            {I.cloud15}
                            <span>On this device</span>
                        </>
                    ) : (
                        <>
                            {I.tick15}
                            <span>Saved</span>
                        </>
                    )}
                </span>
                <button className="ad-ic nt-x" type="button" aria-label="Close" onClick={act('close')}>
                    {I.x18}
                </button>
            </div>
            <div className="nt-bw" ref={host} />
            <div className="nt-phw">
                {note.photo && (
                    <div className="nt-ph">
                        <img src={note.photo.src} alt="" />
                        <div className="nt-pha">
                            <button className="w-act" type="button" onClick={act('photo')}>
                                Replace
                            </button>
                            <button className="w-act" type="button" onClick={act('rmph')}>
                                Remove
                            </button>
                        </div>
                    </div>
                )}
            </div>
            <div className="nl-ob">
                <div className="l">
                    <div className="nt-awr">
                        <button className="k-sbtn nt-aw" type="button" aria-haspopup="listbox" aria-expanded={popFor === 'aw'} onClick={act('aw')}>
                            {I.book17}
                            <span className={`v${note.work ? '' : ' ph'}`}>{note.work ? wlab : 'About a work'}</span>
                            {I.chev16}
                        </button>
                    </div>
                    <button className="w-act" type="button" onClick={act('photo')}>
                        {I.img18}
                        <span>Photo</span>
                    </button>
                    <button
                        className={`w-act nt-tell${note.quiet ? ' off' : ''}`}
                        type="button"
                        aria-pressed={!note.quiet}
                        hidden={!followers}
                        title={note.quiet ? 'Quiet: followers won’t hear about it' : 'Followers hear about it'}
                        onClick={act('tell')}
                    >
                        <span className="ic">{note.quiet ? I.belloff18 : I.bell18}</span>
                        <span>{followers === 1 ? '1 follower' : `${followers} followers`}</span>
                    </button>
                </div>
                <div className="r">
                    <button className="w-act nt-del" type="button" aria-expanded={popFor === 'del'} onClick={act('del')}>
                        {I.trash17}
                        <span>Delete</span>
                    </button>
                    {!live && (
                        <button className={`w-act nt-sch${sch ? ' on' : ''}`} type="button" aria-haspopup="dialog" aria-expanded={popFor === 'sch'} onClick={act('sch')}>
                            {I.clock17}
                            <span>{sch && note.at ? atFmt(note.at) : 'Schedule'}</span>
                        </button>
                    )}
                    <button className={`k-btn sm nt-pub${popFor && popFor !== 'off' ? ' line' : ''}`} type="button" disabled={(empty && !note.photo) || (live && !dirty)} onClick={act('go')}>
                        <span>{go}</span>
                        <Ring />
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ About a work: a listbox with a find field */
function AboutPop({
    cls,
    style,
    box,
    note,
    works,
    isPub,
    onPick,
}: {
    cls: string;
    style: CSSProperties;
    box: React.RefObject<HTMLDivElement>;
    note: NoteRow;
    works: WorkRow[];
    isPub: (s: string | null) => boolean;
    onPick: (slug: string) => void;
}) {
    const opts: [string, string, string][] = [
        ['', 'No work', ''],
        ...(note.work && !isPub(note.work) ? [[note.work, works.find((w) => w.slug === note.work)?.title ?? '', 'Not public'] as [string, string, string]] : []),
        ...works.filter((w) => w.public).map((w) => [w.slug, w.title, KINDS[w.kind] ?? ''] as [string, string, string]),
    ];
    const [find, setFind] = useState('');
    const vis = opts.filter(([, t], i) => i === 0 || !find.trim() || t.toLowerCase().includes(find.trim().toLowerCase()));
    const [act, setAct] = useState(() => Math.max(0, opts.findIndex(([v]) => v === (note.work ?? ''))));
    const input = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLDivElement>(null);
    const actV = vis[Math.min(act, vis.length - 1)]?.[0];

    useEffect(() => {
        window.setTimeout(() => input.current?.focus(), RM() ? 0 : 60);
    }, []);
    useEffect(() => {
        (list.current?.querySelector('.act') as HTMLElement | null)?.scrollIntoView({ block: 'nearest' });
    }, [act, find]);

    return (
        <div className={`${cls} nt-lbp`} role="dialog" aria-label="About a work" ref={box} style={style}>
            <label className="k-search nt-lbq">
                {I.search}
                <input
                    ref={input}
                    placeholder="Find a work"
                    aria-label="Find a work"
                    role="combobox"
                    aria-controls="ntAL"
                    aria-expanded="true"
                    autoComplete="off"
                    aria-activedescendant={`ntAo${opts.findIndex(([v]) => v === actV)}`}
                    value={find}
                    onChange={(e) => {
                        setFind(e.target.value);
                        setAct(e.target.value.trim() ? 1 : 0);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setAct((a) => Math.min(vis.length - 1, a + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setAct((a) => Math.max(0, a - 1));
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            if (actV !== undefined) {
                                onPick(actV);
                            }
                        }
                    }}
                />
            </label>
            <div className="nt-lbl" role="listbox" id="ntAL" ref={list}>
                {opts.map(([v, t, k], i) => {
                    const shown = vis.some((o) => o[0] === v);
                    const np = !!v && !isPub(v);

                    return (
                        <div
                            role="option"
                            id={`ntAo${i}`}
                            key={v || '-'}
                            hidden={!shown}
                            data-v={v}
                            aria-selected={v === (note.work ?? '')}
                            className={`${v === actV ? 'act' : ''}${np ? ' np' : ''}`.trim() || undefined}
                            onMouseMove={() => setAct(vis.findIndex((o) => o[0] === v))}
                            onClick={() => onPick(v)}
                        >
                            <span>{t}</span>
                            {np && I.lock14}
                            {k && <small>{k}</small>}
                            {I.tick15}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ Schedule: a day and a time, WAT */
function SchedulePop({
    cls,
    style,
    box,
    note,
    now,
    onClose,
    onDone,
    flush,
}: {
    cls: string;
    style: CSSProperties;
    box: React.RefObject<HTMLDivElement>;
    note: NoteRow;
    now: string;
    onClose: () => void;
    onDone: (n: NoteRow, folds: boolean) => void;
    flush: () => Promise<void>;
}) {
    const today = now.slice(0, 10);
    const days = [...Array(30)].map((_, i) => {
        const x = pd(today);
        x.setDate(x.getDate() + i);

        return ymd(x);
    });
    const at = note.at ?? `${days[2]}T09:00`;
    const [day, setDay] = useState(at.slice(0, 10));
    const [time, setTime] = useState(at.slice(11, 16));
    const [err, setErr] = useState('');
    const past = `${day}T${time}` <= now;
    const sch = note.state === 'sch';

    useEffect(() => {
        window.setTimeout(() => (box.current?.querySelector('.k-sbtn') as HTMLElement | null)?.focus(), RM() ? 0 : 80);
    }, []);

    async function go(btn: HTMLElement) {
        await flush();
        btn.classList.add('busy');
        const [{ status: st, data }] = await Promise.all([json<{ note: NoteRow; errors?: Record<string, string[]> }>('POST', `/notes/${note.id}/schedule`, { day, time }), wait(RM() ? 300 : 900)]);
        btn.classList.remove('busy');
        if (st === 200 && data.note) {
            onDone(data.note, true);
        } else {
            setErr(data.errors?.day?.[0] ?? 'That didn’t work. Try again.');
        }
    }
    async function un() {
        const { data } = await json<{ note: NoteRow }>('DELETE', `/notes/${note.id}/schedule`);
        if (data.note) {
            onDone(data.note, false);
        }
    }

    return (
        <div className={cls} role="dialog" aria-label="Schedule" ref={box} style={style}>
            <button className="ad-ic x" type="button" aria-label="Close" onClick={onClose}>
                {I.x16}
            </button>
            <h3>Schedule</h3>
            <div className="nt-when">
                <div className="nt-f">
                    <span className="k-fl" id="ntDSL">
                        Day
                    </span>
                    <KSelect
                        className="cm-wsw nt-ps"
                        buttonClassName="k-sbtn cm-ws"
                        labelledBy="ntDSL"
                        value={day}
                        options={days.map((d) => ({ value: d, label: dDay(d) + (d === today ? ' · today' : '') }))}
                        onPick={(v) => {
                            setDay(v);
                            setErr('');
                        }}
                    />
                </div>
                <div className="nt-f">
                    <span className="k-fl" id="ntTSL">
                        Time, WAT
                    </span>
                    <KSelect
                        className="cm-wsw nt-ps"
                        buttonClassName="k-sbtn cm-ws"
                        labelledBy="ntTSL"
                        value={time}
                        options={TIMES.map((t) => ({ value: t, label: tFmt(t) }))}
                        onPick={(v) => {
                            setTime(v);
                            setErr('');
                        }}
                    />
                </div>
            </div>
            {err && <p className="pp">{err}</p>}
            <div className="pf">
                {sch && (
                    <button className="w-act" type="button" onClick={() => void un()}>
                        {I.x17}
                        <span>Unschedule</span>
                    </button>
                )}
                <button className="k-btn sm" type="button" disabled={past} onClick={(e) => void go(e.currentTarget)}>
                    {I.clock16}
                    <span>{sch ? 'Save' : 'Schedule'}</span>
                    <Ring />
                </button>
            </div>
        </div>
    );
}

import { CSSProperties, Fragment, ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { KSelect, wait } from '../../Components/admin/Controls';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { Ring } from '../../Components/admin/Icons';
import { atFmt, bye, DAY, dDay, dShort, grow, inA, MONTH, pd, phone, settle, shrink, tFmt, TIMES, turn, ymd } from '../../Components/admin/notes/lines';
import AdminLayout from '../../Layouts/AdminLayout';
import { json } from '../../lib/http';
import { prefersReducedMotion as RM } from '../../lib/motion';

/* ------------------------------------------------------------------ data */
type Kind = 'novels' | 'stories' | 'poems' | 'essays' | 'notes';
type Item = { key: string; kind: Kind; title: string; where: string; text: string; when: string; scheduled: boolean; cover: CoverData | null; out: boolean };
type Next = { id: number; at: string; moved: boolean; held: boolean; after: string; lines: string; readers: number; items: Item[] };
type Sent = { id: number; day: string; status: 'sent' | 'held' | 'skipped'; what: string; sent: number; bounced: number; stopped: number };
type WorkRow = { slug: string; title: string; kind: string; cover: CoverData };
type Subs = { total: number; newThisWeek: number; stoppedThisWeek: number; growth: number[]; kinds: Record<Kind, number>; unconfirmed: number };
type SubRow = { id: number; email: string; kinds: string; joined: string };
type Props = {
    tab: 's' | 'u' | 'w';
    weekly: { day: number; time: string };
    now: string;
    next: Next;
    sent: Sent[];
    subscribers: Subs;
    welcome: { lines: string; works: WorkRow[] };
    works: WorkRow[];
};

const KINDS: [Kind, string][] = [
    ['novels', 'Novels'],
    ['stories', 'Short stories'],
    ['poems', 'Poetry'],
    ['essays', 'Essays'],
    ['notes', 'Notes'],
];
const KIND = Object.fromEntries(KINDS) as Record<Kind, string>;
const DAYL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const WORK_KIND: Record<string, string> = { novel: 'Novel', story: 'Short story', poem: 'Poem', essay: 'Essay', collection: 'Collection' };

/* ------------------------------------------------------------------ icons (the prototype's set) */
const line = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
const ic = (size: number, width: number, d: ReactNode) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth={width} aria-hidden="true">
        {d}
    </svg>
);
const P = {
    clock: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    pause: (
        <>
            <rect x="6.5" y="5" width="3.5" height="14" rx="1" />
            <rect x="14" y="5" width="3.5" height="14" rx="1" />
        </>
    ),
    play: <path d="M8 5.5v13l10.5-6.5z" />,
    minus: <path d="M6 12h12" />,
    plus: <path d="M12 5v14M5 12h14" />,
    x: <path d="M6 6l12 12M18 6 6 18" />,
    chev: <path d="m6 9 6 6 6-6" />,
    send: <path d="M21 3 10.5 13.5M21 3l-6.5 18-4-7.5L3 9.5z" />,
    bounce: (
        <>
            <path d="M4 8h11a5 5 0 0 1 0 10H9" />
            <path d="M8 4 4 8l4 4" />
        </>
    ),
    stop: (
        <>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M6 6l12 12" />
        </>
    ),
    tick: <path d="m5 12.5 4.5 4.5L19 7" />,
    eye: (
        <>
            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
            <circle cx="12" cy="12" r="3" />
        </>
    ),
    note: (
        <>
            <path d="M6 3h9l4 4v14H6z" />
            <path d="M14 3v5h5" />
        </>
    ),
    search: (
        <>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </>
    ),
    undo: (
        <>
            <path d="M9 7 4 12l5 5" />
            <path d="M4 12h11a5 5 0 0 1 0 10h-2" />
        </>
    ),
    screen: (
        <>
            <rect x="3" y="4.5" width="18" height="12" rx="1.8" />
            <path d="M9 20h6M12 16.5V20" />
        </>
    ),
    phone: (
        <>
            <rect x="7" y="3" width="10" height="18" rx="2.2" />
            <path d="M11 18h2" />
        </>
    ),
    sun: (
        <>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
        </>
    ),
    moon: <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" />,
};
const I = {
    clock14: ic(14, 1.8, P.clock),
    clock15: ic(15, 1.8, P.clock),
    clock17: ic(17, 1.8, P.clock),
    pause14: ic(14, 1.8, P.pause),
    pause15: ic(15, 1.8, P.pause),
    pause16: ic(16, 1.8, P.pause),
    play16: ic(16, 1.8, P.play),
    minus16: ic(16, 1.8, P.minus),
    plus16: ic(16, 2, P.plus),
    x15: ic(15, 1.8, P.x),
    x16: ic(16, 1.8, P.x),
    x18: ic(18, 1.8, P.x),
    chev16: ic(16, 1.8, P.chev),
    chev18: ic(18, 1.8, P.chev),
    send14: ic(14, 1.8, P.send),
    send16: ic(16, 1.8, P.send),
    bounce14: ic(14, 1.8, P.bounce),
    stop14: ic(14, 1.8, P.stop),
    tick15: ic(15, 2.4, P.tick),
    tick16: ic(16, 2.2, P.tick),
    eye17: ic(17, 1.8, P.eye),
    note17: ic(17, 1.8, P.note),
    search: ic(18, 1.8, P.search),
    undo16: ic(16, 1.8, P.undo),
    screen16: ic(16, 1.8, P.screen),
    phone16: ic(16, 1.8, P.phone),
    sun16: ic(16, 1.8, P.sun),
    moon16: ic(16, 1.8, P.moon),
    dot: (
        <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="3" fill="currentColor" />
        </svg>
    ),
};

/** The subscribers over the last twelve months. */
function Spark({ vals, w = 300, h = 64 }: { vals: number[]; w?: number; h?: number }) {
    if (vals.length < 2) {
        return null;
    }
    const lo = Math.min(...vals) * 0.9;
    const hi = Math.max(...vals);
    const span = hi - lo || 1;
    const pts = vals.map((v, i) => [Math.round(((i * w) / (vals.length - 1)) * 10) / 10, Math.round((h - 4 - ((v - lo) / span) * (h - 10)) * 10) / 10]);
    const [x, y] = pts[pts.length - 1];

    return (
        <svg className="nw-spark" width={w} height={h} viewBox={`-2 -2 ${w + 4} ${h + 4}`} fill="none" aria-label="Subscribers over the last 12 months" role="img">
            <path d={'M' + pts.map((p) => p.join(',')).join(' L')} stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" strokeLinecap="round" pathLength={1} className="nw-sp" />
            <circle cx={x} cy={y} r="3.2" fill="currentColor" />
        </svg>
    );
}

/** How an item is named in a line: its title, else its place ('Chapter 36'), else 'a note'. */
const short = (i: Item) => i.title || (i.kind === 'notes' ? 'a note' : i.where.replace(/^.*, chapter /, 'Chapter '));

/* ------------------------------------------------------------------ the page */
type Pop = { kind: 'every' | 'this' | 'rm' | 'add'; btn: HTMLElement; row?: SubRow };

/**
 * The newsletter: the next send (her lines, what goes in it, when, held or not), what went out before, the
 * subscribers, and the welcome letter. Every Sunday by default; this send can move on its own.
 */
export default function Newsletter(props: Props) {
    const [tab, setTab] = useState(props.tab);
    const [weekly, setWeekly] = useState(props.weekly);
    const [next, setNext] = useState(props.next);
    const [subs, setSubs] = useState(props.subscribers);
    const [welcome, setWelcome] = useState(props.welcome);
    const [open, setOpen] = useState(false);
    const [folds, setFolds] = useState<Set<string>>(new Set());
    const [out, setOut] = useState(false);
    const [pop, setPop] = useState<Pop | null>(null);
    const [popOn, setPopOn] = useState(false);
    const [popAt, setPopAt] = useState<CSSProperties>({});
    const [sheet, setSheet] = useState(false);
    const [pv, setPv] = useState<'send' | 'welcome' | null>(null);
    const [, setTick] = useState(0);
    const main = useRef<HTMLDivElement>(null);
    const pane = useRef<HTMLDivElement>(null);
    const popBox = useRef<HTMLDivElement>(null);
    const fx = useRef<(() => void) | null>(null);
    const pvBack = useRef<HTMLElement | null>(null);

    useLayoutEffect(() => {
        const f = fx.current;
        fx.current = null;
        f?.();
    });
    useEffect(() => {
        const r = () => setTick((t) => t + 1);
        window.addEventListener('resize', r);

        return () => window.removeEventListener('resize', r);
    }, []);

    /* ---------- tabs ---------- */
    function goTab(t: Props['tab']) {
        if (t === tab) {
            return;
        }
        popClose();
        const u = t === 'u' ? '?tab=subs' : t === 'w' ? '?tab=welcome' : '';
        window.history.replaceState(window.history.state, '', '/newsletter' + u);
        if (RM()) {
            setTab(t);

            return;
        }
        setOut(true);
        window.setTimeout(() => {
            setTab(t);
            setOut(false);
            fx.current = () => settle(pane.current);
        }, 160);
        if (t === 's') {
            fx.current = () => inA(document.getElementById('nwWhen'));
        }
    }
    const whenLabel = phone() ? `${DAY[weekly.day]} ${tFmt(weekly.time).replace(':00', '')}` : `${DAYL[weekly.day]}s, ${tFmt(weekly.time)}`;

    /* ---------- popovers: one at a time; on the phone, a sheet from the bottom ---------- */
    function popShow(p: Pop) {
        if (pop && pop.btn === p.btn) {
            popClose(true);

            return;
        }
        pop?.btn.setAttribute('aria-expanded', 'false');
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
            const side = pop.kind === 'this' || pop.kind === 'add' ? 'left' : 'right';
            let x = side === 'left' ? r.left - mr.left : r.right - mr.left - w;
            x = Math.max(0, Math.min(x, mr.width - w));
            let y = r.bottom - mr.top + 10;
            if (r.bottom + h + 20 > window.innerHeight && r.top - h - 20 > 0) {
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
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if (e.key !== 'Escape' || pv) {
                return;
            }
            if (pop && !(e.target as Element).closest?.('.k-select.open')) {
                e.preventDefault();
                popClose(true);

                return;
            }
            const c = document.getElementById('nwO');
            if (c && c.contains(document.activeElement)) {
                closeCard(true);
            }
        };
        document.addEventListener('keydown', key);

        return () => document.removeEventListener('keydown', key);
    });

    /* ---------- the next send ---------- */
    async function patchNext(body: Record<string, unknown>): Promise<boolean> {
        const { status, data } = await json<{ next: Next; errors?: Record<string, string[]> }>('PATCH', '/newsletter/next', body);
        if (status === 200 && data.next) {
            setNext(data.next);

            return true;
        }

        return false;
    }
    function openCard() {
        const r = document.getElementById('nwNx');
        const from = r?.offsetHeight ?? 58;
        setOpen(true);
        fx.current = () => {
            const c = document.getElementById('nwO');
            if (c) {
                grow(c, from);
                window.setTimeout(() => document.getElementById('nwLn')?.focus(), RM() ? 0 : 200);
            }
        };
    }
    function closeCard(back = false) {
        const c = document.getElementById('nwO');
        popClose();
        if (!c) {
            return;
        }
        shrink(c, 58, () => {
            setOpen(false);
            fx.current = () => {
                const r = document.getElementById('nwNx');
                inA(r);
                if (back) {
                    r?.focus();
                }
            };
        });
    }
    const names = (n: Next) => {
        const xs = n.items.filter((i) => !i.out).map(short);

        return xs.length ? xs.join(', ') : 'Nothing in this send';
    };

    function nextRow() {
        if (next.held) {
            return (
                <button className="nw-sr nx held" type="button" id="nwNx" aria-expanded="false" onClick={openCard}>
                    <span className="d">
                        {I.pause14}
                        <span>Held this week</span>
                    </span>
                    <span className="tx">
                        <span>{names(next)}</span>
                    </span>
                    <span className="nw-to">{dDay(next.after)}</span>
                </button>
            );
        }

        return (
            <button className="nw-sr nx" type="button" id="nwNx" aria-expanded="false" onClick={openCard}>
                <span className="d sch">
                    {I.clock14}
                    <span>{atFmt(next.at)}</span>
                </span>
                <span className="tx">
                    <span>{names(next)}</span>
                </span>
                <span className="nw-to">{next.readers ? 'To ' + next.readers : 'No send'}</span>
            </button>
        );
    }

    /* ---------- sent history: two months open, the rest of the year and earlier years fold ---------- */
    const byMonth = new Map<string, Sent[]>();
    props.sent.forEach((r) => {
        const k = r.day.slice(0, 7);
        byMonth.set(k, [...(byMonth.get(k) ?? []), r]);
    });
    const months = [...byMonth.keys()];
    const yr = props.now.slice(0, 4);
    const countSends = (rows: Sent[]) => rows.filter((r) => r.status === 'sent').length;
    const tip = (t: string, icon: ReactNode, n: number) => (
        <span className="nw-tip" tabIndex={0} data-tip={t}>
            {icon}
            {n}
        </span>
    );
    function sentRow(r: Sent) {
        if (r.status === 'held') {
            return (
                <div className="nw-sr mute" key={r.id}>
                    <span className="d">{dShort(r.day)}</span>
                    <span className="tx">
                        {I.pause14}
                        <span>Held. Went out the next week.</span>
                    </span>
                    <span />
                </div>
            );
        }
        if (r.status === 'skipped') {
            return (
                <div className="nw-sr mute" key={r.id}>
                    <span className="d">{dShort(r.day)}</span>
                    <span className="tx">
                        <span>Nothing new. No send.</span>
                    </span>
                    <span />
                </div>
            );
        }

        return (
            <div className="nw-sr" key={r.id}>
                <span className="d">{dShort(r.day)}</span>
                <span className="tx">
                    <span>{r.what}</span>
                </span>
                <span className="nw-cn">
                    {tip(`${r.sent} sent`, I.send14, r.sent)}
                    {tip(`${r.bounced} bounced: those inboxes couldn’t take it`, I.bounce14, r.bounced)}
                    {tip(`${r.stopped} stopped after this send`, I.stop14, r.stopped)}
                </span>
            </div>
        );
    }
    const month = (k: string) => (
        <section className="nw-m" data-f={k} key={k}>
            <h2>
                {MONTH[Number(k.slice(5)) - 1]} <small>{k.slice(0, 4)}</small>
            </h2>
            <div className="nw-rows">{byMonth.get(k)!.map(sentRow)}</div>
        </section>
    );
    function unfold(id: string) {
        setFolds((f) => new Set(f).add(id));
        fx.current = () => document.querySelectorAll(`[data-f^="${id}"]`).forEach((el) => inA(el));
    }
    function sends() {
        const cur = months.filter((k) => k.startsWith(yr));
        const old = months.filter((k) => !k.startsWith(yr));
        const shown = cur.slice(0, 2);
        const later = cur.slice(2);
        const years = [...new Set(old.map((k) => k.slice(0, 4)))];

        return (
            <>
                <section className="nw-m">
                    <h2>Next</h2>
                    <div className="nw-rows" id="nwNR">
                        {open ? card() : nextRow()}
                    </div>
                </section>
                {shown.map(month)}
                {later.length > 0 && (
                    <div className="nt-folds">
                        {later.map((k) =>
                            folds.has(k) ? (
                                month(k)
                            ) : (
                                <button className="nt-fold" type="button" data-fold={k} aria-expanded="false" key={k} onClick={() => unfold(k)}>
                                    <span className="m">{MONTH[Number(k.slice(5)) - 1]}</span>
                                    <span className="n">
                                        {countSends(byMonth.get(k)!)} {countSends(byMonth.get(k)!) === 1 ? 'send' : 'sends'}
                                    </span>
                                    {I.chev18}
                                </button>
                            ),
                        )}
                    </div>
                )}
                {years.length > 0 && (
                    <div className="nt-folds yrs">
                        {years.map((y) => {
                            const ks = old.filter((k) => k.startsWith(y));
                            const n = ks.reduce((a, k) => a + countSends(byMonth.get(k)!), 0);

                            return folds.has(y) ? (
                                <Fragment key={y}>{ks.map(month)}</Fragment>
                            ) : (
                                <button className="nt-fold y" type="button" data-fold={y} aria-expanded="false" key={y} onClick={() => unfold(y)}>
                                    <span className="m">{y}</span>
                                    <span className="n">
                                        {n} {n === 1 ? 'send' : 'sends'}
                                    </span>
                                    {I.chev18}
                                </button>
                            );
                        })}
                    </div>
                )}
            </>
        );
    }

    /* ---------- the next send, open ---------- */
    const [linesSaved, setLinesSaved] = useState(false);
    const linesTimer = useRef(0);
    const [testState, setTestState] = useState<Record<string, 'busy' | 'ok' | ''>>({});

    async function sendTest(what: 'send' | 'welcome', btn: HTMLElement) {
        if (testState[what]) {
            return;
        }
        setTestState((s) => ({ ...s, [what]: 'busy' }));
        btn.classList.add('busy');
        const [{ status }] = await Promise.all([json('POST', '/newsletter/test', { what }), wait(RM() ? 300 : 1100)]);
        btn.classList.remove('busy');
        if (status !== 204 && status !== 200) {
            setTestState((s) => ({ ...s, [what]: '' }));

            return;
        }
        const label = btn.querySelector('.nw-tl');
        btn.classList.add('ok');
        turn(label, () => setTestState((s) => ({ ...s, [what]: 'ok' })));
        window.setTimeout(() => {
            btn.classList.remove('ok');
            turn(label, () => setTestState((s) => ({ ...s, [what]: '' })));
        }, 4000);
    }
    const testButton = (what: 'send' | 'welcome') => (
        <button className="w-act nw-test" type="button" onClick={(e) => void sendTest(what, e.currentTarget)}>
            <span className="nw-tl">
                {testState[what] === 'ok' ? I.tick16 : I.send16}
                <span>{testState[what] === 'ok' ? 'Sent to you' : 'Send me a test'}</span>
            </span>
            <Ring />
        </button>
    );

    function card() {
        return (
            <div className="nl-o nw-o" id="nwO">
                <div className="nl-oh">
                    {next.held ? (
                        <>
                            <span className="nt-st">
                                {I.pause15}
                                <span>Held this week</span>
                            </span>
                            <span className="k-meta">The pieces wait for {dDay(next.after)}</span>
                        </>
                    ) : (
                        <>
                            <button className="w-act nt-sch on keep nw-this" type="button" aria-haspopup="dialog" aria-expanded={pop?.kind === 'this'} onClick={(e) => popShow({ kind: 'this', btn: e.currentTarget })}>
                                {I.clock17}
                                <span>{atFmt(next.at)}</span>
                            </button>
                            <span className="k-meta nw-rd">
                                {next.readers ? (
                                    <>
                                        To {next.readers}
                                        <span className="of"> of {subs.total} readers</span>
                                    </>
                                ) : (
                                    'Nothing to send'
                                )}
                            </span>
                        </>
                    )}
                    <span className="nt-sv" id="nwSv" hidden={!linesSaved}>
                        {I.tick15}
                        <span>Saved</span>
                    </span>
                    <button className="ad-ic nt-x" type="button" aria-label="Close" onClick={() => closeCard(true)}>
                        {I.x18}
                    </button>
                </div>
                <div className="nw-lf">
                    <label className="k-fl" htmlFor="nwLn">
                        Your lines
                    </label>
                    <Lines
                        id="nwLn"
                        value={next.lines}
                        placeholder="A few lines from you"
                        onChange={(v) => {
                            window.clearTimeout(linesTimer.current);
                            linesTimer.current = window.setTimeout(async () => {
                                if (await patchNext({ lines: v })) {
                                    if (!linesSaved) {
                                        setLinesSaved(true);
                                        fx.current = () => inA(document.getElementById('nwSv'));
                                    }
                                }
                            }, 500);
                        }}
                    />
                </div>
                <div className="nw-its">
                    <span className="k-fl">In this send</span>
                    {next.items.length === 0 && <p className="k-meta">Nothing new since the last send.</p>}
                    {next.items.map((it) => (
                        <div className={`nw-it${it.out ? ' out' : ''}`} key={it.key}>
                            {it.kind === 'notes' || !it.cover ? <span className="nw-ni">{I.note17}</span> : <Cover cover={it.cover} title="" style={{ width: 26, height: 39 }} />}
                            <span className="tx">
                                {it.kind === 'notes' ? (
                                    <b className="nt">{it.text}</b>
                                ) : (
                                    <>
                                        <b>{it.title || short(it)}</b>
                                        <span>{it.title ? it.where : it.where.replace(/, chapter \d+$/, '')}</span>
                                    </>
                                )}
                            </span>
                            <span className="k">{KIND[it.kind]}</span>
                            {it.scheduled ? (
                                <span className="nw-d sch">
                                    {I.clock14}
                                    <span>{`${dDay(it.when.slice(0, 10))}, ${it.when.slice(11, 16)}`}</span>
                                </span>
                            ) : (
                                <span className="nw-d">{dDay(it.when.slice(0, 10))}</span>
                            )}
                            <button
                                className="w-act nw-lv"
                                type="button"
                                aria-pressed={it.out}
                                onClick={(e) => {
                                    const b = e.currentTarget;
                                    const outNow = !it.out;
                                    turn(b, () => setNext((n) => ({ ...n, items: n.items.map((x) => (x.key === it.key ? { ...x, out: outNow } : x)) })));
                                    void (async () => {
                                        const { data } = await json<{ next: Next }>('PATCH', `/newsletter/next/items/${it.key}`, { out: outNow });
                                        if (data.next) {
                                            const rd = document.querySelector('.nw-rd');
                                            if (data.next.readers !== next.readers) {
                                                turn(rd, () => setNext(data.next!));
                                            } else {
                                                setNext(data.next);
                                            }
                                        }
                                    })();
                                }}
                            >
                                {it.out ? I.plus16 : I.minus16}
                                <span>{it.out ? 'Put back' : 'Leave out'}</span>
                            </button>
                        </div>
                    ))}
                </div>
                <div className="nl-ob">
                    <div className="l">
                        <button className="w-act" type="button" aria-haspopup="dialog" onClick={(e) => openPreview('send', e.currentTarget)}>
                            {I.eye17}
                            <span>Preview</span>
                        </button>
                        {testButton('send')}
                    </div>
                    <div className="r">
                        <button
                            className={`w-act nw-hold${next.held ? ' on' : ''}`}
                            type="button"
                            onClick={(e) => {
                                popClose();
                                const held = !next.held;
                                turn(e.currentTarget, () => setNext((n) => ({ ...n, held })));
                                void patchNext({ held });
                            }}
                        >
                            {next.held ? I.play16 : I.pause16}
                            <span>{next.held ? 'Send it after all' : 'Hold this week'}</span>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    /* ---------- subscribers ---------- */
    const [kf, setKf] = useState('');
    const [query, setQuery] = useState('');
    const [q, setQ] = useState('');
    const [rows, setRows] = useState<SubRow[] | null>(null);
    const [more, setMore] = useState(false);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [unconf, setUnconf] = useState<SubRow[] | null>(null);
    const [unconfOpen, setUnconfOpen] = useState(false);
    const [listOut, setListOut] = useState(false);
    const qTimer = useRef(0);
    const listBox = useRef<HTMLDivElement>(null);
    const firstLoad = useRef(true);

    async function loadSubs(kind: string, term: string, p: number): Promise<{ rows: SubRow[]; total: number; more: boolean }> {
        const u = new URLSearchParams({ page: String(p) });
        if (kind) {
            u.set('kind', kind);
        }
        if (term) {
            u.set('q', term);
        }
        const { data } = await json<{ rows: SubRow[]; total: number; more: boolean }>('GET', '/newsletter/subscribers?' + u);

        return { rows: data.rows ?? [], total: data.total ?? 0, more: !!data.more };
    }
    useEffect(() => {
        if (tab !== 'u') {
            return;
        }
        let gone = false;
        (async () => {
            const anim = !firstLoad.current && !RM();
            if (anim) {
                setListOut(true);
            }
            const [res] = await Promise.all([loadSubs(kf, q, 1), wait(anim ? 160 : 0)]);
            if (gone) {
                return;
            }
            firstLoad.current = false;
            setRows(res.rows);
            setTotal(res.total);
            setMore(res.more);
            setPage(1);
            setListOut(false);
            if (anim) {
                fx.current = () => settle(listBox.current);
            }
        })();

        return () => {
            gone = true;
        };
    }, [tab, kf, q]);
    async function showMore() {
        const before = rows?.length ?? 0;
        const res = await loadSubs(kf, q, page + 1);
        setRows((r) => [...(r ?? []), ...res.rows]);
        setMore(res.more);
        setPage((p) => p + 1);
        fx.current = () => {
            if (RM()) {
                return;
            }
            [...document.querySelectorAll('#nwSubs .nw-su')].slice(before).forEach((r, i) => r.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 320, delay: i * 20, fill: 'backwards' }));
        };
    }
    async function toggleUnconf(btn: HTMLElement) {
        if (unconfOpen) {
            const w = document.getElementById('nwW');
            btn.setAttribute('aria-expanded', 'false');
            if (w) {
                bye(w, () => setUnconfOpen(false));
            } else {
                setUnconfOpen(false);
            }

            return;
        }
        if (!unconf) {
            const { data } = await json<{ rows: SubRow[] }>('GET', '/newsletter/subscribers?unconfirmed=1');
            setUnconf(data.rows ?? []);
        }
        setUnconfOpen(true);
        fx.current = () => {
            const w = document.getElementById('nwW');
            if (w) {
                grow(w, 0);
            }
        };
    }
    async function remove(r: SubRow, btn: HTMLElement) {
        btn.classList.add('busy');
        const [{ status }] = await Promise.all([json('DELETE', `/newsletter/subscribers/${r.id}`), wait(RM() ? 300 : 800)]);
        btn.classList.remove('busy');
        if (status !== 204 && status !== 200) {
            return;
        }
        popClose();
        const el = document.querySelector(`.nw-su[data-id="${r.id}"]`) as HTMLElement | null;
        const finish = () => {
            setRows((xs) => xs?.filter((x) => x.id !== r.id) ?? null);
            setUnconf((xs) => xs?.filter((x) => x.id !== r.id) ?? null);
        };
        if (el) {
            bye(el, finish);
        } else {
            finish();
        }
        {
            const n = document.getElementById('nwN');
            turn(n, () =>
                setSubs((s) => ({
                    ...s,
                    total: unconf?.some((x) => x.id === r.id) ? s.total : s.total - 1,
                    unconfirmed: unconf?.some((x) => x.id === r.id) ? s.unconfirmed - 1 : s.unconfirmed,
                    stoppedThisWeek: s.stoppedThisWeek + 1,
                })),
            );
        }
    }
    const subRow = (r: SubRow, mute = false) => (
        <div className={`nw-su${mute ? ' mute' : ''}${pop?.kind === 'rm' && pop.row?.id === r.id ? ' on' : ''}`} data-id={r.id} key={r.id}>
            <span className="em">{r.email}</span>
            <span />
            <span className="k">{r.kinds}</span>
            <span className="d">{dShort(r.joined)}</span>
            <button className="w-act nw-rm" type="button" aria-label={`Remove ${r.email}`} onClick={(e) => popShow({ kind: 'rm', btn: e.currentTarget, row: r })}>
                {I.x15}
                <span>Remove</span>
            </button>
        </div>
    );
    function subsTab() {
        return (
            <>
                <div className="nw-big">
                    <div>
                        <b id="nwN">{subs.total.toLocaleString('en-GB')}</b>
                        <span>
                            Subscribers {subs.newThisWeek > 0 && <em>+{subs.newThisWeek}</em>} this week
                        </span>
                    </div>
                    <div>
                        <b>{subs.stoppedThisWeek}</b>
                        <span>Stopped this week</span>
                    </div>
                    <Spark vals={subs.growth} />
                </div>
                <div className="nw-kinds">
                    {KINDS.map(([k, label]) => (
                        <span key={k}>
                            <b>{subs.kinds[k] ?? 0}</b>
                            {label}
                        </span>
                    ))}
                </div>
                <div className="nw-tools nw-st">
                    <KSelect
                        className="cm-wsw nw-kw"
                        buttonClassName="k-sbtn cm-ws"
                        label="Kinds"
                        value={kf}
                        options={[{ value: '', label: 'All kinds' }, ...KINDS.map(([v, l]) => ({ value: v, label: l }))]}
                        onPick={(v) => setKf(v)}
                    />
                    <label className="k-search nw-q" htmlFor="nwQ">
                        {I.search}
                        <input
                            id="nwQ"
                            type="search"
                            placeholder="Find an email"
                            autoComplete="off"
                            aria-label="Find a subscriber by their whole email address"
                            value={query}
                            onChange={(e) => {
                                const v = e.target.value;
                                setQuery(v);
                                window.clearTimeout(qTimer.current);
                                qTimer.current = window.setTimeout(() => setQ(v.trim().toLowerCase()), 220);
                            }}
                        />
                        {query && (
                            <button
                                className="ad-ic"
                                type="button"
                                aria-label="Clear"
                                onClick={() => {
                                    setQuery('');
                                    setQ('');
                                    document.getElementById('nwQ')?.focus();
                                }}
                            >
                                {I.x16}
                            </button>
                        )}
                    </label>
                </div>
                <div id="nwSL" ref={listBox} className={listOut ? 'nt-out' : ''} style={{ transition: 'opacity 160ms, filter 160ms', ...(listOut ? { opacity: 0, filter: 'blur(4px)' } : {}) }}>
                    {rows === null ? null : q ? (
                        rows.length ? (
                            <div className="nw-subs">{rows.map((r) => subRow(r))}</div>
                        ) : (
                            <p className="nw-qh">{/@.+\./.test(q) ? 'No one with that address.' : 'Type a whole address to find someone.'}</p>
                        )
                    ) : (
                        <>
                            <div className="nw-subs" id="nwSubs">
                                {rows.map((r) => subRow(r))}
                            </div>
                            {more && (
                                <div className="a-more">
                                    <button className="k-btn line sm" type="button" onClick={() => void showMore()}>
                                        Show more
                                    </button>
                                    <span className="k-meta">
                                        {rows.length} of {total}
                                    </span>
                                </div>
                            )}
                            {subs.unconfirmed > 0 && !kf && (
                                <>
                                    <button className={`nw-fold${unconfOpen ? ' on' : ''}`} type="button" aria-expanded={unconfOpen} onClick={(e) => void toggleUnconf(e.currentTarget)}>
                                        {I.chev16}
                                        <span>{subs.unconfirmed} not confirmed</span>
                                    </button>
                                    {unconfOpen && unconf && (
                                        <div className="nw-subs w" id="nwW">
                                            {unconf.map((r) => subRow(r, true))}
                                        </div>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </div>
            </>
        );
    }

    /* ---------- welcome ---------- */
    const [wSave, setWSave] = useState<'saved' | 'saving'>('saved');
    const wTimer = useRef(0);
    async function patchWelcome(body: Record<string, unknown>) {
        setWSave('saving');
        const { data } = await json<{ welcome: Props['welcome'] }>('PATCH', '/newsletter/welcome', body);
        if (data.welcome) {
            setWelcome(data.welcome);
        }
        turn(document.getElementById('nwWsv'), () => setWSave('saved'));
    }
    function welcomeTab() {
        return (
            <div className="nw-wl">
                <div className="nw-lf">
                    <label className="k-fl" htmlFor="nwWl">
                        Your letter
                    </label>
                    <Lines
                        id="nwWl"
                        value={welcome.lines}
                        placeholder="A few lines to say hello"
                        onChange={(v) => {
                            setWSave('saving');
                            window.clearTimeout(wTimer.current);
                            wTimer.current = window.setTimeout(() => void patchWelcome({ lines: v }), 600);
                        }}
                    />
                </div>
                <div className="nw-its" id="nwWp">
                    <span className="k-fl">To start with</span>
                    {welcome.works.map((w) => (
                        <div className="nw-it" data-w={w.slug} key={w.slug}>
                            <Cover cover={w.cover} title="" style={{ width: 26, height: 39 }} />
                            <span className="tx">
                                <b>{w.title}</b>
                                <span>{WORK_KIND[w.kind] ?? ''}</span>
                            </span>
                            <button
                                className="w-act nw-lv"
                                type="button"
                                aria-label={`Remove ${w.title}`}
                                onClick={(e) => {
                                    const row = e.currentTarget.closest('.nw-it') as HTMLElement;
                                    const works = welcome.works.filter((x) => x.slug !== w.slug).map((x) => x.slug);
                                    bye(row, () => {
                                        setWelcome((cur) => ({ ...cur, works: cur.works.filter((x) => x.slug !== w.slug) }));
                                        fx.current = () => inA(document.querySelector('#nwWp .nw-add'));
                                    });
                                    void patchWelcome({ works });
                                }}
                            >
                                {I.x15}
                                <span>Remove</span>
                            </button>
                        </div>
                    ))}
                    {welcome.works.length < 3 && (
                        <button className="w-act nw-add" type="button" aria-haspopup="dialog" aria-expanded={pop?.kind === 'add'} onClick={(e) => popShow({ kind: 'add', btn: e.currentTarget })}>
                            {I.plus16}
                            <span>Add a work</span>
                        </button>
                    )}
                </div>
                <div className="nw-acts row">
                    <button className="w-act" type="button" aria-haspopup="dialog" onClick={(e) => openPreview('welcome', e.currentTarget)}>
                        {I.eye17}
                        <span>Preview</span>
                    </button>
                    {testButton('welcome')}
                    <span className={`nt-sv${wSave === 'saving' ? ' ing' : ''}`} id="nwWsv">
                        {wSave === 'saving' ? I.dot : I.tick15}
                        <span>{wSave === 'saving' ? 'Saving' : 'Saved'}</span>
                    </span>
                </div>
            </div>
        );
    }

    /* ---------- popovers ---------- */
    function popover(): ReactNode {
        if (!pop) {
            return null;
        }
        const cls = `nt-pop${popOn ? ' on' : ''}${sheet ? ' sheet' : ''}`;
        const style = sheet ? {} : popAt;
        if (pop.kind === 'every') {
            return (
                <WhenPop
                    key="every"
                    cls={cls}
                    style={style}
                    box={popBox}
                    title="Every week"
                    days={[0, 1, 2, 3, 4, 5, 6].map((d) => ({ value: String(d), label: DAYL[d] }))}
                    day={String(weekly.day)}
                    time={weekly.time}
                    past={() => false}
                    onClose={() => popClose(true)}
                    onSave={async (d, t) => {
                        const { status, data } = await json<{ weekly: Props['weekly']; next: Next }>('PUT', '/newsletter/weekly', { day: Number(d), time: t });
                        if (status !== 200 || !data.weekly) {
                            return 'That didn’t work. Try again.';
                        }
                        popClose(true);
                        turn(document.querySelector('#nwWhen span'), () => {
                            setWeekly(data.weekly!);
                            setNext(data.next!);
                        });

                        return '';
                    }}
                />
            );
        }
        if (pop.kind === 'this') {
            const today = props.now.slice(0, 10);
            const days = [...Array(21)].map((_, i) => {
                const x = pd(today);
                x.setDate(x.getDate() + i);

                return ymd(x);
            });

            return (
                <WhenPop
                    key="this"
                    cls={cls}
                    style={style}
                    box={popBox}
                    title="This send"
                    days={days.map((d) => ({ value: d, label: dDay(d) + (d === today ? ' · today' : '') }))}
                    day={next.at.slice(0, 10)}
                    time={next.at.slice(11, 16)}
                    past={(d, t) => `${d}T${t}` <= props.now}
                    back={next.moved ? `Back to ${DAY[weekly.day]} ${tFmt(weekly.time)}` : undefined}
                    onBack={async () => {
                        if (await patchNext({ at: null })) {
                            popClose(true);
                        }
                    }}
                    onClose={() => popClose(true)}
                    onSave={async (d, t) => {
                        const { status, data } = await json<{ next: Next; errors?: Record<string, string[]> }>('PATCH', '/newsletter/next', { at: { day: d, time: t } });
                        if (status !== 200 || !data.next) {
                            return data.errors?.day?.[0] ?? 'That didn’t work. Try again.';
                        }
                        popClose(true);
                        setNext(data.next);

                        return '';
                    }}
                />
            );
        }
        if (pop.kind === 'rm' && pop.row) {
            const r = pop.row;

            return (
                <div className={`${cls} sm`} role="alertdialog" aria-label="Remove" ref={popBox} style={style}>
                    <h3>Remove this reader?</h3>
                    <p className="pp">{r.email} gets no more emails. The address is deleted.</p>
                    <div className="acts">
                        <button className="k-btn sm line nt-dng" type="button" onClick={(e) => void remove(r, e.currentTarget)}>
                            <span>Remove</span>
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
            <AddWork
                key="add"
                cls={cls}
                style={style}
                box={popBox}
                works={props.works.filter((w) => !welcome.works.some((x) => x.slug === w.slug))}
                onPick={(w) => {
                    popClose();
                    setWelcome((cur) => ({ ...cur, works: [...cur.works, w] }));
                    fx.current = () => inA(document.querySelector(`#nwWp [data-w="${w.slug}"]`));
                    void patchWelcome({ works: [...welcome.works.map((x) => x.slug), w.slug] });
                }}
            />
        );
    }

    /* ---------- preview ---------- */
    function openPreview(what: 'send' | 'welcome', btn: HTMLElement) {
        popClose();
        pvBack.current = btn;
        setPv(what);
    }

    return (
        <AdminLayout section="newsletter">
            <div className="b-wh">
                <h1 className="b-h" id="sT">
                    Newsletter
                </h1>
                <button className="w-act nw-when" type="button" id="nwWhen" aria-haspopup="dialog" aria-expanded={pop?.kind === 'every'} hidden={tab !== 's'} onClick={(e) => popShow({ kind: 'every', btn: e.currentTarget })}>
                    {I.clock17}
                    <span>{whenLabel}</span>
                </button>
            </div>
            <div className="nw-main" ref={main} style={{ position: 'relative' }}>
                <div className="nw-tools nw-tb">
                    <div className="k-tabs" role="tablist" id="nwT">
                        {(
                            [
                                ['s', 'Sends', 0],
                                ['u', 'Subscribers', subs.total],
                                ['w', 'Welcome', 0],
                            ] as [Props['tab'], string, number][]
                        ).map(([id, label, n]) => (
                            <a
                                key={id}
                                href="#"
                                role="tab"
                                className={tab === id ? 'on' : ''}
                                aria-selected={tab === id}
                                onClick={(e) => {
                                    e.preventDefault();
                                    goTab(id);
                                }}
                            >
                                {label}
                                {n > 0 && <small>{n}</small>}
                            </a>
                        ))}
                    </div>
                </div>
                <div className={`nw-pane${out ? ' nt-out' : ''}`} id="nwP" ref={pane} data-t={tab} aria-live="polite">
                    {tab === 's' ? sends() : tab === 'u' ? subsTab() : welcomeTab()}
                </div>
                {pop && !sheet && popover()}
            </div>
            {createPortal(
                <>
                    <div className={`nt-scrim${pop && sheet && popOn ? ' on' : ''}`} onClick={() => popClose()} />
                    {pop && sheet && popover()}
                    <Preview
                        what={pv}
                        onClose={() => {
                            setPv(null);
                            pvBack.current?.focus();
                        }}
                    />
                </>,
                document.body,
            )}
        </AdminLayout>
    );
}

/* ------------------------------------------------------------------ plain lines (no formatting in the email's opening) */
function Lines({ id, value, placeholder, onChange }: { id: string; value: string; placeholder: string; onChange: (v: string) => void }) {
    const box = useRef<HTMLDivElement>(null);
    const [empty, setEmpty] = useState(!value.trim());

    useEffect(() => {
        if (box.current) {
            box.current.innerText = value;
        }
        /* only the first value: after that the box is hers */
    }, []);

    return (
        <div className="nw-tw">
            <div
                className="nw-ta"
                id={id}
                ref={box}
                contentEditable
                role="textbox"
                aria-multiline="true"
                spellCheck
                suppressContentEditableWarning
                onInput={(e) => {
                    const v = (e.currentTarget as HTMLDivElement).innerText.replace(/\n{3,}/g, '\n\n');
                    setEmpty(!v.trim());
                    onChange(v);
                }}
                onPaste={(e) => {
                    e.preventDefault();
                    document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
                }}
                onKeyDown={(e) => {
                    if ((e.ctrlKey || e.metaKey) && /^[bi]$/i.test(e.key)) {
                        e.preventDefault();
                    }
                }}
            />
            <span className={`nw-ph${empty ? ' on' : ''}`} aria-hidden="true">
                {placeholder}
            </span>
        </div>
    );
}

/* ------------------------------------------------------------------ a day and a time, WAT */
function WhenPop({
    cls,
    style,
    box,
    title,
    days,
    day: day0,
    time: time0,
    past,
    back,
    onBack,
    onClose,
    onSave,
}: {
    cls: string;
    style: CSSProperties;
    box: React.RefObject<HTMLDivElement>;
    title: string;
    days: { value: string; label: string }[];
    day: string;
    time: string;
    past: (d: string, t: string) => boolean;
    back?: string;
    onBack?: () => void;
    onClose: () => void;
    onSave: (d: string, t: string) => Promise<string>;
}) {
    const [day, setDay] = useState(day0);
    const [time, setTime] = useState(time0);
    const [err, setErr] = useState('');
    const uid = title.replace(/\W/g, '');

    useEffect(() => {
        window.setTimeout(() => (box.current?.querySelector('.k-sbtn') as HTMLElement | null)?.focus(), RM() ? 0 : 80);
    }, []);

    return (
        <div className={cls} role="dialog" aria-label={title} ref={box} style={style}>
            <button className="ad-ic x" type="button" aria-label="Close" onClick={onClose}>
                {I.x16}
            </button>
            <h3>{title}</h3>
            <div className="nt-when">
                <div className="nt-f">
                    <span className="k-fl" id={`${uid}D`}>
                        Day
                    </span>
                    <KSelect className="cm-wsw nt-ps" buttonClassName="k-sbtn cm-ws" labelledBy={`${uid}D`} value={day} options={days} onPick={(v) => (setDay(v), setErr(''))} />
                </div>
                <div className="nt-f">
                    <span className="k-fl" id={`${uid}T`}>
                        Time, WAT
                    </span>
                    <KSelect className="cm-wsw nt-ps" buttonClassName="k-sbtn cm-ws" labelledBy={`${uid}T`} value={time} options={TIMES.map((t) => ({ value: t, label: tFmt(t) }))} onPick={(v) => (setTime(v), setErr(''))} />
                </div>
            </div>
            {err && <p className="pp">{err}</p>}
            <div className="pf">
                {back && (
                    <button className="w-act" type="button" onClick={onBack}>
                        {I.undo16}
                        <span>{back}</span>
                    </button>
                )}
                <button
                    className="k-btn sm"
                    type="button"
                    disabled={past(day, time)}
                    onClick={async (e) => {
                        const b = e.currentTarget;
                        b.classList.add('busy');
                        const [msg] = await Promise.all([onSave(day, time), wait(RM() ? 300 : 800)]);
                        b.classList.remove('busy');
                        setErr(msg);
                    }}
                >
                    <span>Save</span>
                    <Ring />
                </button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ Add a work (to the welcome letter) */
function AddWork({ cls, style, box, works, onPick }: { cls: string; style: CSSProperties; box: React.RefObject<HTMLDivElement>; works: WorkRow[]; onPick: (w: WorkRow) => void }) {
    const [find, setFind] = useState('');
    const [act, setAct] = useState(0);
    const input = useRef<HTMLInputElement>(null);
    const vis = works.filter((w) => !find.trim() || w.title.toLowerCase().includes(find.trim().toLowerCase()));
    const cur = vis[Math.min(act, vis.length - 1)];

    useEffect(() => {
        window.setTimeout(() => input.current?.focus(), RM() ? 0 : 60);
    }, []);
    useEffect(() => {
        (box.current?.querySelector('.nt-lbl .act') as HTMLElement | null)?.scrollIntoView({ block: 'nearest' });
    }, [act, find]);

    return (
        <div className={`${cls} nt-lbp`} role="dialog" aria-label="Add a work" ref={box} style={style}>
            <label className="k-search nt-lbq">
                {I.search}
                <input
                    ref={input}
                    placeholder="Find a work"
                    aria-label="Find a work"
                    role="combobox"
                    aria-controls="nwAL"
                    aria-expanded="true"
                    autoComplete="off"
                    aria-activedescendant={cur ? `nwAo${cur.slug}` : undefined}
                    value={find}
                    onChange={(e) => (setFind(e.target.value), setAct(0))}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setAct((a) => Math.min(vis.length - 1, a + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setAct((a) => Math.max(0, a - 1));
                        } else if (e.key === 'Enter' && cur) {
                            e.preventDefault();
                            onPick(cur);
                        }
                    }}
                />
            </label>
            <div className="nt-lbl" role="listbox" id="nwAL">
                {vis.map((w) => (
                    <div role="option" id={`nwAo${w.slug}`} key={w.slug} aria-selected="false" className={w === cur ? 'act' : undefined} onMouseMove={() => setAct(vis.indexOf(w))} onClick={() => onPick(w)}>
                        <span>{w.title}</span>
                        <small>{WORK_KIND[w.kind] ?? ''}</small>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ Preview: the real email, as a kind of reader gets it */
function Preview({ what, onClose }: { what: 'send' | 'welcome' | null; onClose: () => void }) {
    const [kind, setKind] = useState('');
    const [wide, setWide] = useState(true);
    const [dark, setDark] = useState(false);
    const [height, setHeight] = useState(600);
    const [fade, setFade] = useState(false);
    const [shown, setShown] = useState<'send' | 'welcome'>('send');
    const dlg = useRef<HTMLDivElement>(null);
    const on = what !== null;

    useEffect(() => {
        if (!what) {
            return;
        }
        setShown(what);
        setKind('');
        setWide(!phone());
        setDark(false);
        window.setTimeout(() => (dlg.current?.querySelector('[data-v="x"]') as HTMLElement | null)?.focus(), RM() ? 0 : 200);
    }, [what]);

    function change(f: () => void) {
        if (RM()) {
            f();

            return;
        }
        setFade(true);
        window.setTimeout(() => {
            f();
            setFade(false);
        }, 160);
    }
    const src = `/newsletter/preview?${new URLSearchParams({ what: shown, kind: shown === 'send' ? kind : '', dark: dark ? '1' : '0' })}`;

    return (
        <>
            <div className={`k-scrim${on ? ' on' : ''}`} onClick={onClose} />
            <div
                className={`b-dlg nw-pv${on ? ' on' : ''}`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="nwPvH"
                ref={dlg}
                {...(on ? {} : { inert: '' })}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        onClose();
                    }
                }}
            >
                <button className="ad-ic x" type="button" aria-label="Close" data-v="x" onClick={onClose}>
                    {I.x18}
                </button>
                <div className="nw-pvh">
                    <h2 id="nwPvH">Preview</h2>
                    {shown === 'send' && (
                        <KSelect
                            className="cm-wsw nw-pk"
                            buttonClassName="k-sbtn cm-ws"
                            label="Seen by"
                            value={kind}
                            options={[{ value: '', label: 'Gets everything' }, ...KINDS.map(([v, l]) => ({ value: v, label: 'Gets only ' + l }))]}
                            onPick={(v) => change(() => setKind(v))}
                        />
                    )}
                    <div className="nw-seg nw-dev" role="group" aria-label="Width">
                        <button type="button" aria-pressed={wide} className={wide ? 'on' : ''} onClick={() => change(() => setWide(true))}>
                            {I.screen16}
                            <span>Wide</span>
                        </button>
                        <button type="button" aria-pressed={!wide} className={wide ? '' : 'on'} onClick={() => change(() => setWide(false))}>
                            {I.phone16}
                            <span>Phone</span>
                        </button>
                    </div>
                    <div className="nw-seg ic" role="group" aria-label="Mail app theme">
                        <button type="button" aria-label="Light" aria-pressed={!dark} className={dark ? '' : 'on'} onClick={() => change(() => setDark(false))}>
                            {I.sun16}
                        </button>
                        <button type="button" aria-label="Dark" aria-pressed={dark} className={dark ? 'on' : ''} onClick={() => change(() => setDark(true))}>
                            {I.moon16}
                        </button>
                    </div>
                </div>
                <div className={`nw-pvb${wide ? '' : ' ph'}${dark ? ' dark' : ''}`} id="nwPvB">
                    {on && (
                        <iframe
                            className={`nw-frame${fade ? ' out' : ''}`}
                            title="Email preview"
                            src={src}
                            style={{ height, colorScheme: dark ? 'dark' : 'light' }}
                            onLoad={(e) => {
                                const d = (e.currentTarget as HTMLIFrameElement).contentDocument;
                                if (d) {
                                    setHeight(Math.max(200, d.documentElement.scrollHeight));
                                }
                            }}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

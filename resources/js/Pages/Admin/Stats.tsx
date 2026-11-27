import { Link } from '@inertiajs/react';
import { ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { loading } from '../../Components/admin/Controls';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { EASE, grow, settle, shrink } from '../../Components/admin/notes/lines';
import { Bucket, Chart, cn, n, tipN } from '../../Components/admin/stats/Chart';
import AdminLayout from '../../Layouts/AdminLayout';
import { json } from '../../lib/http';
import { prefersReducedMotion as RM } from '../../lib/motion';

/* ------------------------------------------------------------------ data */
type Range = '7' | '30' | '12' | 'all';
type Metric = 'reads' | 'readers' | 'finished';
type Fig = { v: number; before: number | null };
type Series = Record<Metric, number[]>;
type WorkRow = {
    slug: string;
    title: string;
    kind: string;
    cover: CoverData;
    likes: number;
    follows: number;
    reads: number;
    readers: number;
    finished: number;
    before: number | null;
    series: Series;
    multi: boolean;
    keep: number[] | null;
    chapters: { no: number; title: string; reads: number; fin: number }[] | null;
};
type StatsData = {
    range: Range;
    empty: boolean;
    figures: Record<Metric, Fig>;
    chart: { buckets: Bucket[] } & Series;
    works: WorkRow[];
    notes: { reads: number; count: number };
    pages: { visits: number };
    sources: { key: string; label: string; v: number }[];
    countries: { code: string; name: string; v: number }[];
    phone: number | null;
};
type Props = { stats: StatsData; metric: Metric; open: string };

const RANGES: [Range, string][] = [
    ['7', '7 days'],
    ['30', '30 days'],
    ['12', '12 months'],
    ['all', 'All'],
];
const METRICS: [Metric, string][] = [
    ['reads', 'Reads'],
    ['readers', 'Readers'],
    ['finished', 'Finished'],
];
const FLAG = (cc: string) => `https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/flags/4x3/${cc}.svg`;

/* ------------------------------------------------------------------ icons (the prototype's set) */
const ic = (size: number, d: ReactNode) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {d}
    </svg>
);
const P: Record<string, ReactNode> = {
    heart: <path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z" />,
    bell: (
        <>
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </>
    ),
    chev: <path d="m6 9 6 6 6-6" />,
    note: (
        <>
            <path d="M6 3h9l4 4v14H6z" />
            <path d="M14 3v5h5" />
        </>
    ),
    globe: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
        </>
    ),
    phone: (
        <>
            <rect x="7" y="3" width="10" height="18" rx="2.2" />
            <path d="M11 18h2" />
        </>
    ),
    screen: (
        <>
            <rect x="3" y="4.5" width="18" height="12" rx="1.8" />
            <path d="M9 20h6M12 16.5V20" />
        </>
    ),
    direct: <path d="M5 12h12M13 7l5 5-5 5" />,
    ig: (
        <>
            <rect x="3.8" y="3.8" width="16.4" height="16.4" rx="4.8" />
            <circle cx="12" cy="12" r="3.9" />
            <circle cx="17.1" cy="6.9" r=".6" fill="currentColor" />
        </>
    ),
    x_: (
        <>
            <path d="M4.5 4h4.2L19.5 20h-4.2z" />
            <path d="M19 4l-5.9 6.8M5 20l5.9-6.8" />
        </>
    ),
    mail: (
        <>
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3.5 6.5 8.5 6.5 8.5-6.5" />
        </>
    ),
    gg: (
        <>
            <path d="M20 12.2a8 8 0 1 1-2.4-5.7" />
            <path d="M20 12.2h-7.6" />
        </>
    ),
    wa: (
        <>
            <path d="M4 20l1.2-4.1A8.3 8.3 0 1 1 8.4 19z" />
            <path d="M9 8.6c0 3.4 2.9 6.4 6.4 6.4l1.1-1.6-2-1-1 .9a5 5 0 0 1-2.8-2.8l.9-1-1-2z" />
        </>
    ),
    fb: <path d="M14.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.6-1.5h1.6V4.4a20 20 0 0 0-2.4-.1c-2.4 0-4 1.4-4 4.1v2.1H8.6v3h2.7V21" />,
    gr: (
        <>
            <circle cx="11.5" cy="10" r="4.6" />
            <path d="M16.1 4.5v9.8a5 5 0 0 1-9.4 2.4" />
        </>
    ),
    tt: (
        <>
            <path d="M13.5 3.8v11a3.6 3.6 0 1 1-3.6-3.6" />
            <path d="M13.5 3.8c.5 2.8 2.4 4.4 5.3 4.6" />
        </>
    ),
    link: (
        <>
            <path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1" />
            <path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1" />
        </>
    ),
};

/* ------------------------------------------------------------------ pieces */
const sum = (a: number[]) => a.reduce((x, y) => x + y, 0);
/** bars are a share of the whole list (a full bar = everything); the % says it in words */
const share = (v: number, tot: number) => {
    const p = (v / (tot || 1)) * 100;

    return p < 1 ? '<1%' : Math.round(p) + '%';
};
const Bar = ({ v, tot }: { v: number; tot: number }) => (
    <span className="st-b" aria-hidden="true">
        <i style={{ width: `${Math.max(0.6, (v / (tot || 1)) * 100).toFixed(2)}%` }} />
    </span>
);
function Delta({ a, b }: { a: number; b: number | null }) {
    if (!b) {
        return null;
    }
    const p = Math.round(((a - b) / b) * 100);
    if (!p) {
        return <em className="eq">±0%</em>;
    }

    return p > 0 ? <em>+{p}%</em> : <em className="dn">−{-p}%</em>;
}
const Counts = ({ w }: { w: WorkRow }) => (
    <span className="st-cn">
        <span className="st-ic nw-tip" tabIndex={0} data-tip={`${n(w.likes)} ${w.likes === 1 ? 'like' : 'likes'}`}>
            {ic(15, P.heart)}
            {cn(w.likes)}
        </span>
        {w.follows ? (
            <span className="st-ic nw-tip" tabIndex={0} data-tip={`${n(w.follows)} following`}>
                {ic(15, P.bell)}
                {cn(w.follows)}
            </span>
        ) : (
            <span className="st-ic none" aria-hidden="true" />
        )}
    </span>
);
const H2 = ({ t, small }: { t: string; small?: string }) => (
    <div className="st-h2">
        <h2>
            {t}
            {small && <small>{small}</small>}
        </h2>
    </div>
);
const piecesWord = (kind: string) => (kind.includes('collection') ? (kind.startsWith('Poetry') ? 'Poems' : kind.startsWith('Essay') ? 'Essays' : 'Stories') : 'Chapters');
const fadeUp = (els: Element[], step: number, dist = 6) =>
    els.forEach((r, i) =>
        RM()
            ? r.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 })
            : r.animate(
                  [
                      { opacity: 0, transform: `translateY(${dist}px)` },
                      { opacity: 1, transform: 'none' },
                  ],
                  { duration: 340, delay: Math.min(i, 14) * step, easing: EASE, fill: 'backwards' },
              ),
    );
/** A change that makes a block taller or shorter: the block eases to its new height. */
function reflow(el: HTMLElement | null, change: () => void) {
    const h0 = el?.offsetHeight ?? 0;
    flushSync(change);
    if (el && !RM()) {
        el.animate(
            [
                { height: h0 + 'px', overflow: 'hidden' },
                { height: el.offsetHeight + 'px', overflow: 'hidden' },
            ],
            { duration: 320, easing: EASE },
        );
    }
}
const chartH = () => (window.innerWidth <= 600 ? 200 : 240);

/* ------------------------------------------------------------------ the page */
/** Stats: reading counts only (first-party, cookieless). A · Lines: range, figure tiles that switch the chart, works by reads, where from, countries, phone or computer. */
export default function Stats(props: Props) {
    const [data, setData] = useState(props.stats);
    const [rng, setRng] = useState<Range>(props.stats.range);
    const [met, setMet] = useState<Metric>(props.metric);
    const [open, setOpen] = useState(props.stats.works.some((w) => w.slug === props.open) ? props.open : '');
    const [leaving, setLeaving] = useState<string[]>([]);
    const [showAll, setShowAll] = useState(() => props.stats.works.findIndex((w) => w.slug === props.open) >= 6);
    const [folds, setFolds] = useState<Set<string>>(new Set());
    const [dim, setDim] = useState(false);
    const [gen, setGen] = useState(0);
    const [h, setH] = useState(chartH);
    const pane = useRef<HTMLDivElement>(null);
    const req = useRef(0);
    const turnFigs = useRef(false);
    const grown = useRef('');

    useEffect(() => {
        const r = () => setH(chartH());
        window.addEventListener('resize', r);
        settle(pane.current);

        return () => window.removeEventListener('resize', r);
    }, []);
    useEffect(() => {
        const u = new URLSearchParams();
        if (rng !== '30') {
            u.set('r', rng);
        }
        if (met !== 'reads') {
            u.set('m', met);
        }
        if (open) {
            u.set('open', open);
        }
        const q = u.toString();
        window.history.replaceState(window.history.state, '', '/stats' + (q ? '?' + q : ''));
    }, [rng, met, open]);

    /* a card that opens grows; the first one (from the address) is already there */
    useLayoutEffect(() => {
        if (!open || grown.current === open) {
            return;
        }
        const first = grown.current === '' && open === props.open;
        grown.current = open;
        const c = document.getElementById(`sto-${open}`);
        if (!c || first) {
            return;
        }
        grow(c, 0);
        const btn = c.previousElementSibling as HTMLElement | null;
        window.setTimeout(
            () => {
                if (c.getBoundingClientRect().bottom > window.innerHeight) {
                    btn?.scrollIntoView({ block: 'start', behavior: RM() ? 'auto' : 'smooth' });
                }
            },
            RM() ? 0 : 120,
        );
    }, [open]);

    /* range: the page keeps its frame, dims for a beat, then the numbers turn */
    async function pickRange(r: Range, a: HTMLElement) {
        if (r === rng) {
            return;
        }
        setRng(r);
        a.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: RM() ? 'auto' : 'smooth' });
        const id = ++req.current;
        loading(true);
        setDim(true);
        const [res] = await Promise.all([json<{ stats: StatsData }>('GET', `/stats/range?r=${r}`), new Promise((ok) => window.setTimeout(ok, RM() ? 120 : 420))]);
        if (id !== req.current) {
            return;
        }
        loading(false);
        setDim(false);
        if (res.status !== 200 || !res.data.stats) {
            setRng(data.range);

            return;
        }
        const next = res.data.stats;
        const y = window.scrollY;
        turnFigs.current = true;
        flushSync(() => {
            setData(next);
            setGen((g) => g + 1);
            if (open && !next.works.some((w) => w.slug === open)) {
                setOpen('');
            }
        });
        window.scrollTo(0, y);
        if (!RM()) {
            pane.current
                ?.querySelectorAll('.st-wk, .st-3 .st-r')
                .forEach((row, i) => row.animate([{ opacity: 0.4 }, { opacity: 1 }], { duration: 360, delay: Math.min(i, 12) * 20, easing: 'ease', fill: 'backwards' }));
        }
    }
    useLayoutEffect(() => {
        if (!turnFigs.current) {
            return;
        }
        turnFigs.current = false;
        pane.current?.querySelectorAll('.st-f').forEach((b) =>
            RM()
                ? b.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180 })
                : b.firstElementChild &&
                  [...b.children].forEach((c) =>
                      c.animate(
                          [
                              { opacity: 0, transform: 'translateY(4px)' },
                              { opacity: 1, transform: 'none' },
                          ],
                          { duration: 260, easing: EASE },
                      ),
                  ),
        );
    }, [data]);

    function toggle(slug: string) {
        const closeOne = (s: string) => {
            const c = document.getElementById(`sto-${s}`);
            if (!c) {
                return;
            }
            setLeaving((l) => [...l, s]);
            shrink(c, 0, () => setLeaving((l) => l.filter((x) => x !== s)));
        };
        if (open) {
            closeOne(open);
        }
        setOpen(open === slug ? '' : slug);
    }
    function foldWorks(btn: HTMLElement) {
        const L = pane.current?.querySelector('#stWL');
        if (!showAll) {
            flushSync(() => setShowAll(true));
            const rows = [...(L?.children ?? [])].slice(6);
            rows.forEach((r, i) =>
                RM()
                    ? r.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 })
                    : r.animate(
                          [
                              { opacity: 0, transform: 'translateY(6px)', filter: 'blur(3px)' },
                              { opacity: 1, transform: 'none', filter: 'none' },
                          ],
                          { duration: 380, delay: Math.min(i, 14) * 25, easing: EASE, fill: 'backwards' },
                      ),
            );
        } else {
            const rows = [...(L?.children ?? [])].slice(6) as HTMLElement[];
            if (open && data.works.findIndex((w) => w.slug === open) >= 6) {
                setOpen('');
            }
            let left = rows.length;
            const done = () => --left <= 0 && setShowAll(false);
            rows.length ? rows.forEach((r) => shrink(r, 0, done)) : setShowAll(false);
        }
        btn.focus({ preventScroll: true });
    }
    function fold(key: string, box: HTMLElement | null, skip: number) {
        const on = !folds.has(key);
        reflow(box, () =>
            setFolds((f) => {
                const x = new Set(f);
                on ? x.add(key) : x.delete(key);

                return x;
            }),
        );
        if (on && box) {
            fadeUp([...(box.querySelector('.st-list, .st-crs')?.children ?? [])].slice(skip), key.startsWith('ch:') ? 18 : 25);
        }
    }

    const d = data;
    const prev = rng !== 'all';
    const unit = met;
    const rangeName = RANGES.find((r) => r[0] === rng)![1];
    const wr = sum(d.works.map((w) => w.reads)) || 1;
    const shown = showAll ? d.works : d.works.slice(0, 6);

    return (
        <AdminLayout section="stats">
            <div className="b-wh">
                <h1 className="b-h" id="sT">
                    Stats
                </h1>
            </div>
            <div className="st-a">
                <div className="nw-tools st-tools">
                    <div className="k-tabs st-rt" role="tablist" aria-label="Range" id="stR">
                        {RANGES.map(([k, t]) => (
                            <a
                                key={k}
                                href="#"
                                role="tab"
                                className={rng === k ? 'on' : ''}
                                aria-selected={rng === k}
                                onClick={(e) => {
                                    e.preventDefault();
                                    void pickRange(k, e.currentTarget);
                                }}
                            >
                                {t}
                            </a>
                        ))}
                    </div>
                </div>
                <div className={`st-pane${dim ? ' st-dim' : ''}`} id="stP" aria-live="polite" ref={pane}>
                    {d.empty ? (
                        <div className="st-empty">
                            <p>No reads yet.</p>
                            <span>Counts start the day your first work goes public.</span>
                        </div>
                    ) : (
                        <>
                            <div
                                className="st-figs"
                                id="stF"
                                role="group"
                                aria-label="Show on the chart"
                                onKeyDown={(e) => {
                                    const f = (e.target as HTMLElement).closest('.st-f');
                                    if (!f || !['ArrowLeft', 'ArrowRight'].includes(e.key)) {
                                        return;
                                    }
                                    e.preventDefault();
                                    const L = [...e.currentTarget.querySelectorAll<HTMLElement>('.st-f')];
                                    const i = (L.indexOf(f as HTMLElement) + (e.key === 'ArrowRight' ? 1 : -1) + L.length) % L.length;
                                    L[i].focus();
                                }}
                            >
                                {METRICS.map(([k, t]) => {
                                    const f = d.figures[k];
                                    const isBig = Math.round(f.v) >= 1e5;

                                    return (
                                        <button
                                            key={k}
                                            type="button"
                                            className={`st-f${k === met ? ' on' : ''}${isBig ? ' st-tipb' : ''}`}
                                            aria-pressed={k === met}
                                            data-tip={isBig ? `${n(f.v)} ${t.toLowerCase()}` : undefined}
                                            onClick={() => k !== met && setMet(k)}
                                        >
                                            <b>{cn(f.v)}</b>
                                            <span>
                                                <i className="ky" aria-hidden="true" />
                                                {t} {prev && <Delta a={f.v} b={f.before} />}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="st-mc" id="stC">
                                <Chart vals={d.chart[met]} B={d.chart.buckets} h={h} unit={unit} label={`${unit} over ${rangeName}`} drawKey={`${gen}:${met}`} />
                            </div>
                            <section className="st-sec" id="stW">
                                <H2 t="Works" small="by reads" />
                                <div className="st-wrs" id="stWL">
                                    {shown.map((w) => {
                                        const on = open === w.slug;

                                        return (
                                            <div className="st-wk" data-k={w.slug} key={w.slug}>
                                                <button className={`st-wr${on ? ' on' : ''}`} type="button" aria-expanded={on} aria-controls={`sto-${w.slug}`} onClick={() => toggle(w.slug)}>
                                                    <Cover cover={w.cover} title="" className="k-cov st-cv" style={{ width: 26, height: 39 }} />
                                                    <span className="tx">
                                                        <b>{w.title}</b>
                                                        <span>{w.kind}</span>
                                                    </span>
                                                    <Counts w={w} />
                                                    <span className="rd">
                                                        <span className="v" {...tipN(w.reads, 'reads')}>
                                                            {cn(w.reads)}
                                                        </span>
                                                        <span className="p">{share(w.reads, wr)}</span>
                                                        <Bar v={w.reads} tot={wr} />
                                                    </span>
                                                    {ic(18, P.chev)}
                                                </button>
                                                {(on || leaving.includes(w.slug)) && (
                                                    <WorkCard w={w} B={d.chart.buckets} met={met} prev={prev} gen={gen} folds={folds} onFold={(k, box, skip) => fold(k, box, skip)} />
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {d.works.length > 6 && (
                                    <button className={`w-act st-more${showAll ? ' up' : ''}`} type="button" aria-expanded={showAll} onClick={(e) => foldWorks(e.currentTarget)}>
                                        <span>{showAll ? 'Top 6' : `All ${d.works.length} works`}</span>
                                        {ic(16, P.chev)}
                                    </button>
                                )}
                                <div className="st-xrs">
                                    <Link className="st-xr" href="/notes">
                                        <span className="ic">{ic(17, P.note)}</span>
                                        <span className="nm">
                                            Diary notes <small>{d.notes.count} notes read</small>
                                        </span>
                                        <span className="v" {...tipN(d.notes.reads, 'reads')}>
                                            {cn(d.notes.reads)}
                                        </span>
                                    </Link>
                                    <Link className="st-xr" href="/site">
                                        <span className="ic">{ic(17, P.globe)}</span>
                                        <span className="nm">
                                            Site pages <small>Home, About, In print…</small>
                                        </span>
                                        <span className="v" {...tipN(d.pages.visits, 'visits')}>
                                            {cn(d.pages.visits)} visits
                                        </span>
                                    </Link>
                                </div>
                            </section>
                            <div className="st-3">
                                <List
                                    title="Where from"
                                    k="src"
                                    lim={6}
                                    rows={d.sources.map((s) => ({ id: s.label, icon: ic(17, P[s.key] ?? P.link), name: s.label, v: s.v }))}
                                    open={folds.has('src')}
                                    onFold={(box) => fold('src', box, 6)}
                                />
                                <List
                                    title="Countries"
                                    k="cty"
                                    lim={8}
                                    rows={d.countries.map((c) => ({ id: c.code, icon: <img className="st-fl" src={FLAG(c.code)} alt="" />, name: c.name, v: c.v }))}
                                    open={folds.has('cty')}
                                    onFold={(box) => fold('cty', box, 8)}
                                />
                                <section>
                                    <H2 t="Phone or computer" />
                                    {d.phone !== null && (
                                        <>
                                            <div className="st-dv" role="img" aria-label={`Phone ${d.phone}%, computer ${100 - d.phone}%`}>
                                                <span className="sg a" style={{ width: `${d.phone}%` }} />
                                                <span className="sg b" style={{ width: `${100 - d.phone}%` }} />
                                            </div>
                                            <div className="st-dvl">
                                                <span>
                                                    {ic(17, P.phone)}Phone <b>{d.phone}%</b>
                                                </span>
                                                <span>
                                                    {ic(17, P.screen)}Computer <b>{100 - d.phone}%</b>
                                                </span>
                                            </div>
                                        </>
                                    )}
                                </section>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

type Row = { id: string; icon: ReactNode; name: string; v: number };
function List({ title, k, lim, rows, open, onFold }: { title: string; k: string; lim: number; rows: Row[]; open: boolean; onFold: (box: HTMLElement | null) => void }) {
    const box = useRef<HTMLElement>(null);
    const mx = sum(rows.map((r) => r.v)) || 1;
    const shown = open ? rows : rows.slice(0, lim);
    const more = rows.length - lim;

    return (
        <section ref={box}>
            <H2 t={title} />
            <div className="st-list">
                {shown.map((r) => (
                    <div className="st-r" key={r.id}>
                        <span className="ic">{r.icon}</span>
                        <span className="nm">{r.name}</span>
                        <span className="v" {...tipN(r.v, 'reads')}>
                            {cn(r.v)}
                        </span>
                        <span className="p">{share(r.v, mx)}</span>
                        <Bar v={r.v} tot={mx} />
                    </div>
                ))}
            </div>
            {more > 0 && (
                <button
                    className={`w-act st-more${open ? ' up' : ''}`}
                    type="button"
                    data-f={k}
                    aria-expanded={open}
                    onClick={(e) => {
                        onFold(box.current);
                        e.currentTarget.focus({ preventScroll: true });
                    }}
                >
                    <span>{open ? 'Fewer' : `${more} more`}</span>
                    {ic(16, P.chev)}
                </button>
            )}
        </section>
    );
}

/** A work, unfolded: its figures, its chart, how many readers keep going, and its chapters (newest first). */
function WorkCard({
    w,
    B,
    met,
    prev,
    gen,
    folds,
    onFold,
}: {
    w: WorkRow;
    B: Bucket[];
    met: Metric;
    prev: boolean;
    gen: number;
    folds: Set<string>;
    onFold: (k: string, box: HTMLElement | null, skip: number) => void;
}) {
    const card = useRef<HTMLDivElement>(null);
    const fk = 'ch:' + w.slug;
    const allCh = folds.has(fk);
    const C = w.chapters ?? [];
    const N = C.length;
    const shown = allCh ? C : C.slice(0, 6);
    const mx = sum(C.map((c) => c.reads)) || 1;
    const word = piecesWord(w.kind);
    const keep = w.keep;
    const kN = keep?.length ?? 0;

    return (
        <div className="nl-o st-o" id={`sto-${w.slug}`} role="region" aria-label={w.title} ref={card}>
            <div className="st-oh">
                <div className="st-mini">
                    <div>
                        <b {...tipN(w.reads, 'reads')}>{cn(w.reads)}</b>
                        <span>Reads {prev && <Delta a={w.reads} b={w.before} />}</span>
                    </div>
                    <div>
                        <b {...tipN(w.readers, 'readers')}>{cn(w.readers)}</b>
                        <span>Readers</span>
                    </div>
                    <div>
                        <b {...tipN(w.finished, 'finished')}>{cn(w.finished)}</b>
                        <span>Finished</span>
                    </div>
                </div>
                <div className="st-ohr">
                    <Counts w={w} />
                    <Link className="k-l st-ow" href={`/works/${w.slug}`}>
                        <span>Open the work</span>
                    </Link>
                </div>
            </div>
            <div className="st-wch">
                <Chart vals={w.series[met]} B={B} h={150} cls="sm" unit={met} label={`${w.title}: ${met} over the range`} drawKey={`${gen}:${met}`} />
            </div>
            {keep && kN > 1 && (
                <div className="st-kp">
                    <span className="k-fl">Readers who keep going</span>
                    <div className="st-kpc">
                        <Chart
                            vals={keep}
                            B={keep.map((_, i) => ({ lab: '', tip: `Chapter ${i + 1}` }))}
                            labs={[0, Math.round((kN - 1) / 3), Math.round((2 * (kN - 1)) / 3), kN - 1].filter((v, i, a) => a.indexOf(v) === i).map((i) => [i, `Ch ${i + 1}`])}
                            h={150}
                            ymax={100}
                            ticks={2}
                            pct
                            cls="kp"
                            end={Math.round(keep[kN - 1]) + '%'}
                            unit="% still reading"
                            label={`Of the readers who start chapter 1, ${Math.round(keep[kN - 1])}% reach chapter ${kN}`}
                            drawKey={String(gen)}
                        />
                    </div>
                </div>
            )}
            {w.multi && N > 0 && (
                <div className="st-chs">
                    <span className="k-fl">{word}</span>
                    <div className="st-crs">
                        {shown.map((c) => (
                            <div className="st-cr" key={c.no}>
                                <span className="no">{c.no}</span>
                                <span className="ct">{c.title || `Chapter ${c.no}`}</span>
                                <span className="fi">{c.fin}% finished</span>
                                <span className="rd">
                                    <span className="v" {...tipN(c.reads, 'reads')}>
                                        {cn(c.reads)}
                                    </span>
                                    <span className="p">{share(c.reads, mx)}</span>
                                    <Bar v={c.reads} tot={mx} />
                                </span>
                            </div>
                        ))}
                    </div>
                    {N > 6 && (
                        <button
                            className={`w-act st-more${allCh ? ' up' : ''}`}
                            type="button"
                            aria-expanded={allCh}
                            onClick={(e) => {
                                const box = card.current;
                                const btn = e.currentTarget;
                                onFold(fk, box, 6);
                                if (allCh) {
                                    box?.querySelector('.st-chs')?.scrollIntoView({ block: 'nearest', behavior: RM() ? 'auto' : 'smooth' });
                                }
                                btn.focus({ preventScroll: true });
                            }}
                        >
                            <span>{allCh ? 'Newest 6' : `All ${N} ${word.toLowerCase()}`}</span>
                            {ic(16, P.chev)}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

import { CSSProperties, RefObject, useEffect, useRef, useState } from 'react';
import { KSelect } from '../Controls';
import { bye, DAY, grow, inA, MON, MONTH, pd, tFmt, TIMES, turn } from '../notes/lines';
import { json } from '../../../lib/http';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { bareUrl, Book, Card, Coming, Cov, CURS, Field, fromPrice, fullUrl, I, InPrint, isLink, Money, newId, pad, plural, priceOf, priceText, Reading, Row, same, Store, Work } from './kit';

const FMTS = ['Paperback', 'Hardback', 'Ebook', 'Audiobook', 'Signed'];
const KIND: Record<string, string> = { novel: 'A novel', poem: 'Poems', story: 'Short stories', essay: 'Essays', collection: 'A collection' };
const WKIND: Record<string, string> = { novel: 'Novel', poem: 'Poem', story: 'Story', essay: 'Essay', collection: 'Collection' };

/* West Africa Time is UTC+1 all year */
const watNow = () => new Date(Date.now() + 3600e3).toISOString();
const today = () => watNow().slice(0, 10);
const watParts = (iso: string) => {
    const x = new Date(new Date(iso).getTime() + 3600e3).toISOString();

    return { d: x.slice(0, 10), tm: x.slice(11, 16) };
};
const utcOf = (d: string, tm: string) => new Date(`${d}T${tm}:00+01:00`).toISOString().replace('.000Z', 'Z');
const outWords = (o: Coming['out']) => (!o || !o.y ? '' : o.m ? (o.d ? `${+o.d} ${MONTH[+o.m - 1]} ${o.y}` : `${MONTH[+o.m - 1]} ${o.y}`) : o.y);
function outPast(o: Coming['out']) {
    if (!o || !o.y) {
        return false;
    }
    const [Y, M, D] = today().split('-').map(Number);
    if (+o.y !== Y) {
        return +o.y < Y;
    }
    if (!o.m) {
        return false;
    }
    if (+o.m !== M) {
        return +o.m < M;
    }

    return o.d ? +o.d < D : false;
}
/** An older "March 2027" becomes a real date. */
export function outOf(b: Coming): NonNullable<Coming['out']> {
    if (b.out) {
        return b.out;
    }
    const t = b.when ?? '';
    const y = t.match(/\b(20\d\d)\b/)?.[1] ?? '';
    const mi = MONTH.findIndex((m) => new RegExp('\\b' + m.slice(0, 3), 'i').test(t));
    const d = t.replace(y, '').match(/\b(\d{1,2})\b/)?.[1] ?? '';

    return { d: mi >= 0 && d && +d <= 31 ? pad(+d) : '', m: mi >= 0 ? pad(mi + 1) : '', y };
}

/** Coming books' typed dates become real ones, in the draft and what readers see alike (so it isn't an edit). */
export function withOut<T extends InPrint | null>(p: T): T {
    p?.coming.forEach((b) => {
        if (!b.out) {
            b.out = outOf(b);
        }
    });

    return p;
}

/** In print: her books and where to buy each format, what's coming, and her readings. */
export default function InPrintTab({ s }: { s: SiteCtx }) {
    const page = s.page<InPrint>('in_print');
    const p = page.draft;
    const live = page.live;
    const [folds, setFolds] = useState<Set<string>>(new Set());
    const [out, setOut] = useState(false);
    const subPane = useRef<HTMLDivElement>(null);
    const upd = (fn: (d: InPrint) => void) => s.update<InPrint>('in_print', fn);
    const work = (slug: string) => s.works.find((w) => w.slug === slug);
    const look = (b: { slug: string; cover: string }) => ({ image: work(b.slug)?.image ?? null, color: b.cover });

    function edited(k: string) {
        const f = (x: InPrint | null) => x?.books.find((b) => b.slug === k) ?? x?.coming.find((b) => b.slug === k) ?? x?.events.find((e) => e.id === k);

        return !same(f(p), f(live));
    }
    const storeBad = (st: Store) => !!st.url && (s.links[st.url] ? !s.links[st.url].ok : !st.ok);

    function goSub(w: 'b' | 'r') {
        if (w === s.sub) {
            return;
        }
        setOut(true);
        window.setTimeout(() => {
            s.setSub(w);
            setOut(false);
        }, 160);
    }

    /* ---------- books ---------- */
    function bookRow(b: Book) {
        const all = b.formats.flatMap((f) => f.stores);
        const from = fromPrice(all);

        return (
            <Row
                key={b.slug}
                k={b.slug}
                icon={<Cov w={look(b)} width={26} />}
                title={b.title}
                sub={b.formats.length ? b.formats.map((f) => f.format).join(', ') : 'No formats yet'}
                flag={
                    all.some(storeBad) ? (
                        <span className="nw-tip sp-bad" tabIndex={0} data-tip="A store link didn’t open">
                            {I.broken15}
                        </span>
                    ) : null
                }
                meta={from ? `from ${from}` : ''}
                edited={edited(b.slug)}
                onOpen={() => s.openCard(b.slug)}
            />
        );
    }
    function comRow(b: Coming) {
        return (
            <Row
                key={b.slug}
                k={b.slug}
                icon={<Cov w={look(b)} width={26} />}
                title={b.title}
                sub={b.mode === 'pre' ? (b.store ? `Pre-order · ${b.store}` : 'Pre-order') : 'Follow'}
                meta={
                    <span className="sp-cl">
                        {I.clock14}
                        {b.when}
                    </span>
                }
                edited={edited(b.slug)}
                onOpen={() => s.openCard(b.slug)}
            />
        );
    }
    function pickWork(btn: HTMLElement, coming: boolean) {
        const have = new Set([...p.books, ...p.coming].map((b) => b.slug));
        const opts = s.works.filter((w) => (coming || w.public) && !have.has(w.slug));
        s.popShow({
            id: 'pick',
            btn,
            side: 'left',
            render: (cls, style, box) => (
                <WorkPick
                    cls={cls}
                    style={style}
                    box={box}
                    works={opts}
                    onPick={(w) => {
                        s.popClose();
                        const base = { slug: w.slug, title: w.title, kind: KIND[w.kind] ?? '', cover: w.color ?? '#C46F8A' };
                        upd((d) => {
                            if (coming) {
                                d.coming.push({ ...base, when: '', out: null, mode: 'follow', store: '', price: null, url: null });
                            } else {
                                d.books.push({ ...base, formats: [] });
                            }
                        });
                        s.after(() => s.openCard(w.slug));
                    }}
                />
            ),
        });
    }

    /* ---------- readings ---------- */
    const T = today();
    const evDay = (e: Reading) => watParts(e.starts_at).d;
    function evRow(e: Reading, past: boolean) {
        const { d, tm } = watParts(e.starts_at);
        const x = pd(d);
        const yr = d.slice(0, 4) !== T.slice(0, 4) ? ` · ${d.slice(0, 4)}` : '';
        const where = past ? (e.online ? 'Online' : e.place) : (e.online ? `Online · ${tFmt(tm)}` : `${e.place || 'Somewhere'} · ${tFmt(tm)}`) + yr;

        return (
            <Row
                key={e.id}
                k={e.id}
                cls={`ev${e.cancelled ? ' x' : ''}`}
                icon={
                    <span className="sp-ed">
                        <b>{x.getDate()}</b>
                        <small>{MON[x.getMonth()]}</small>
                    </span>
                }
                title={e.title}
                sub={
                    <>
                        {e.online && I.globe14}
                        {where}
                    </>
                }
                meta={
                    e.cancelled ? (
                        'Cancelled'
                    ) : !past && e.url ? (
                        <>
                            {I.ext14}
                            {e.online ? 'Join' : 'Details'}
                        </>
                    ) : (
                        ''
                    )
                }
                edited={edited(e.id)}
                onOpen={() => s.openCard(e.id)}
            />
        );
    }
    function addReading() {
        const id = newId('e');
        const x = pd(T);
        x.setDate(x.getDate() + 14);
        const d = `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`;
        upd((dd) => dd.events.push({ id, title: '', starts_at: utcOf(d, '16:00'), place: '', online: false, url: null, cancelled: false }));
        s.after(() => s.openCard(id));
    }
    const item = (k: string, node: () => React.ReactNode) => (k === s.open ? <PrintCard key={'o' + k} k={k} s={s} p={p} upd={upd} /> : node());

    function books() {
        return (
            <>
                <section className="nw-m sp-m">
                    <h2>
                        In print <small>{p.books.length}</small>
                    </h2>
                    <div className="sp-rows" id="spBooks">
                        {p.books.length ? p.books.map((b) => item(b.slug, () => bookRow(b))) : <p className="sp-none">Nothing in print yet.</p>}
                    </div>
                    <div className="sp-addrow">
                        <button className="w-act sp-add" type="button" aria-haspopup="dialog" onClick={(e) => pickWork(e.currentTarget, false)}>
                            {I.plus16}
                            <span>Add a book</span>
                        </button>
                    </div>
                </section>
                <section className="nw-m sp-m">
                    <h2>
                        Coming <small>{p.coming.length}</small>
                    </h2>
                    <div className="sp-rows" id="spCom">
                        {p.coming.length ? p.coming.map((b) => item(b.slug, () => comRow(b))) : <p className="sp-none">Nothing coming.</p>}
                    </div>
                    <div className="sp-addrow">
                        <button className="w-act sp-add" type="button" aria-haspopup="dialog" onClick={(e) => pickWork(e.currentTarget, true)}>
                            {I.plus16}
                            <span>Add a book</span>
                        </button>
                    </div>
                </section>
            </>
        );
    }
    function readings() {
        const up = p.events.filter((e) => evDay(e) >= T).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        const past = p.events.filter((e) => evDay(e) < T).sort((a, b) => b.starts_at.localeCompare(a.starts_at));
        const byY = new Map<string, Reading[]>();
        past.forEach((e) => byY.set(evDay(e).slice(0, 4), [...(byY.get(evDay(e).slice(0, 4)) ?? []), e]));
        const years = [...byY.keys()].sort().reverse();

        return (
            <>
                <section className="nw-m sp-m">
                    <h2>
                        Coming up <small>{up.length}</small>
                    </h2>
                    <div className="sp-rows" id="spUp">
                        {up.length ? up.map((e) => item(e.id, () => evRow(e, false))) : <p className="sp-none">No readings coming up.</p>}
                    </div>
                    <div className="sp-addrow">
                        <button className="w-act sp-add" type="button" onClick={addReading}>
                            {I.plus16}
                            <span>Add a reading</span>
                        </button>
                    </div>
                </section>
                {years.map((y) =>
                    y === T.slice(0, 4) || folds.has(y) || byY.get(y)!.some((e) => e.id === s.open) ? (
                        <section className="nw-m sp-m" key={y} data-y={y}>
                            <h2>
                                Past <small>{y}</small>
                            </h2>
                            <div className="sp-rows">{byY.get(y)!.map((e) => item(e.id, () => evRow(e, true)))}</div>
                        </section>
                    ) : (
                        <button
                            className="nt-fold y"
                            type="button"
                            key={y}
                            aria-expanded="false"
                            onClick={() => {
                                setFolds((f) => new Set(f).add(y));
                                s.after(() => document.querySelectorAll(`[data-y="${y}"]`).forEach((el) => inA(el)));
                            }}
                        >
                            <span className="m">{y}</span>
                            <span className="n">{plural(byY.get(y)!.length, 'reading')}</span>
                            {I.chev18}
                        </button>
                    ),
                )}
            </>
        );
    }

    return (
        <>
            <div className="sp-ptabs">
                <div className="sp-sub" role="tablist">
                    {(
                        [
                            ['b', 'Books'],
                            ['r', 'Readings'],
                        ] as const
                    ).map(([k, t]) => (
                        <a
                            key={k}
                            href="#"
                            role="tab"
                            className={s.sub === k ? 'on' : ''}
                            aria-selected={s.sub === k}
                            onClick={(e) => {
                                e.preventDefault();
                                goSub(k);
                            }}
                        >
                            {t}
                        </a>
                    ))}
                </div>
            </div>
            <div id="spSP" ref={subPane} className={out ? 'nt-out' : ''}>
                {s.sub === 'b' ? books() : readings()}
            </div>
        </>
    );
}

/* ------------------------------------------------------------------ find a work */
function WorkPick({ cls, style, box, works, onPick }: { cls: string; style: CSSProperties; box: RefObject<HTMLDivElement>; works: Work[]; onPick: (w: Work) => void }) {
    const [q, setQ] = useState('');
    const [act, setAct] = useState(0);
    const ip = useRef<HTMLInputElement>(null);
    const vis = works.filter((w) => !q.trim() || w.title.toLowerCase().includes(q.trim().toLowerCase()));
    useEffect(() => {
        window.setTimeout(() => ip.current?.focus(), 60);
    }, []);
    useEffect(() => {
        document.getElementById('spWo' + act)?.scrollIntoView({ block: 'nearest' });
    }, [act]);

    return (
        <div className={`${cls} nt-lbp`} role="dialog" aria-label="Add a book" ref={box} style={style}>
            <label className="k-search nt-lbq">
                {I.search}
                <input
                    ref={ip}
                    placeholder="Find a work"
                    aria-label="Find a work"
                    role="combobox"
                    aria-controls="spWL"
                    aria-expanded="true"
                    aria-activedescendant={vis[act] ? 'spWo' + act : undefined}
                    autoComplete="off"
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setAct(0);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setAct(Math.min(vis.length - 1, act + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setAct(Math.max(0, act - 1));
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            if (vis[act]) {
                                onPick(vis[act]);
                            }
                        }
                    }}
                />
            </label>
            <div className="nt-lbl" role="listbox" id="spWL">
                {works.length ? (
                    vis.map((w, i) => (
                        <div role="option" id={'spWo' + i} key={w.slug} aria-selected={i === act} className={i === act ? 'act' : undefined} onMouseMove={() => setAct(i)} onClick={() => onPick(w)}>
                            <span>{w.title}</span>
                            <small>{WKIND[w.kind] ?? ''}</small>
                        </div>
                    ))
                ) : (
                    <p className="sp-none">Every public work is already here.</p>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ the open card: a book, a coming book or a reading */
function PrintCard({ k, s, p, upd }: { k: string; s: SiteCtx; p: InPrint; upd: (fn: (d: InPrint) => void) => void }) {
    const b = p.books.find((x) => x.slug === k);
    const c = p.coming.find((x) => x.slug === k);
    const e = p.events.find((x) => x.id === k);
    if (b) {
        return <BookCard b={b} s={s} upd={upd} />;
    }
    if (c) {
        return <ComingCard b={c} s={s} upd={upd} />;
    }
    if (e) {
        return <ReadingCard e={e} s={s} upd={upd} />;
    }

    return null;
}

const head = (s: SiteCtx, b: { slug: string; title: string; cover: string }) => (
    <span className="sp-ot">
        <Cov w={{ image: s.works.find((w) => w.slug === b.slug)?.image ?? null, color: b.cover }} width={30} />
        <b>{b.title}</b>
    </span>
);

/** One store: its name, price (in a currency) and link, with whether the link opens. */
function StoreRow({ st, s, onChange, onRemove, removable = true }: { st: Store; s: SiteCtx; onChange: (st: Store) => void; onRemove?: (el: HTMLElement) => void; removable?: boolean }) {
    const [price, setPrice] = useState(priceText(st.price));
    const [cur, setCur] = useState(st.price?.currency ?? 'NGN');
    const [url, setUrl] = useState(bareUrl(st.url));
    const [checking, setChecking] = useState(false);
    const t = useRef(0);
    const mark = useRef<HTMLSpanElement>(null);
    const known = st.url ? s.links[st.url] : undefined;
    const ok = known ? known.ok : st.ok;
    const latest = useRef(st);
    latest.current = st;

    function check(v: string) {
        window.clearTimeout(t.current);
        const u = fullUrl(v);
        if (!u) {
            setChecking(false);

            return;
        }
        setChecking(true);
        t.current = window.setTimeout(async () => {
            if (!isLink(v)) {
                setChecking(false);
                onChange({ ...latest.current, ok: false });

                return;
            }
            const { status, data } = await json<{ ok: boolean; checkedOn: string }>('POST', '/site/link-check', { url: u });
            setChecking(false);
            if (status === 200 && typeof data.ok === 'boolean') {
                s.setLink(u, { ok: data.ok, checkedOn: data.checkedOn ?? '' });
                onChange({ ...latest.current, ok: data.ok });
                inA(mark.current?.firstElementChild ?? null);
            }
        }, 1200);
    }
    useEffect(() => () => window.clearTimeout(t.current), []);
    const day = known?.checkedOn ? `${DAY[pd(known.checkedOn).getDay()]} ${pd(known.checkedOn).getDate()} ${MON[pd(known.checkedOn).getMonth()]}` : '';

    return (
        <div className="sp-sr">
            <span className="k-in sp-in sm">
                <input value={st.store} placeholder="Store" aria-label="Store" onChange={(e) => onChange({ ...st, store: e.target.value })} />
            </span>
            <span className="sp-cur">
                <KSelect
                    className="sp-ks"
                    buttonClassName="k-sbtn sp-sel sm"
                    label="Currency"
                    value={cur}
                    options={CURS.map(([v, sym]) => ({ value: v, label: sym }))}
                    onPick={(v) => {
                        setCur(v);
                        onChange({ ...st, price: priceOf(price, v) });
                    }}
                />
                <span className="k-in sp-in sm">
                    <input
                        value={price}
                        placeholder="Price"
                        aria-label="Price"
                        inputMode="decimal"
                        onChange={(e) => {
                            const v = e.target.value.replace(/[^\d.,]/g, '');
                            setPrice(v);
                            onChange({ ...st, price: priceOf(v, cur) });
                        }}
                    />
                </span>
            </span>
            <span className={`k-in sp-in sm sp-url${!checking && url && !ok ? ' bad' : ''}`}>
                <input
                    value={url}
                    placeholder="Link"
                    aria-label="Link"
                    type="url"
                    inputMode="url"
                    spellCheck={false}
                    onChange={(e) => {
                        const v = e.target.value.trim();
                        setUrl(v);
                        onChange({ ...st, url: fullUrl(v) });
                        check(v);
                    }}
                />
            </span>
            <span ref={mark} className="sp-okw">
                {!url ? (
                    <span className="sp-ok" />
                ) : checking ? (
                    <span className="sp-ok chk" title="Checking the link">
                        {I.dot}
                    </span>
                ) : ok ? (
                    <span className="sp-ok" title="The link opens">
                        {I.tick15}
                    </span>
                ) : (
                    <span className="nw-tip sp-bad" tabIndex={0} data-tip={day ? `This link didn’t open on ${day}` : 'This link doesn’t open'}>
                        {I.broken15}
                    </span>
                )}
            </span>
            {removable && (
                <button className="ad-ic" type="button" aria-label={`Remove ${st.store || 'this store'}`} onClick={(ev) => onRemove?.(ev.currentTarget.closest('.sp-sr') as HTMLElement)}>
                    {I.x15}
                </button>
            )}
        </div>
    );
}

function BookCard({ b, s, upd }: { b: Book; s: SiteCtx; upd: (fn: (d: InPrint) => void) => void }) {
    const [gen, setGen] = useState(0);
    const fm = useRef<HTMLDivElement>(null);
    const focus = useRef<string | null>(null);
    const missing = FMTS.filter((f) => !b.formats.some((x) => x.format === f));
    const ub = (fn: (x: Book) => void) => upd((d) => fn(d.books.find((x) => x.slug === b.slug)!));
    const restructure = (fn: (x: Book) => void, sel: string | null) => {
        ub(fn);
        focus.current = sel;
        setGen((g) => g + 1);
    };
    useEffect(() => {
        const sel = focus.current;
        focus.current = null;
        if (sel) {
            const el = fm.current?.querySelector<HTMLElement>(sel);
            inA(el?.closest('.sp-sr,.sp-fb') ?? null);
            el?.focus();
        }
    }, [gen]);

    function addFormat(btn: HTMLElement) {
        s.popShow({
            id: 'fmt',
            btn,
            side: 'left',
            render: (cls, style, box) => (
                <FormatPick
                    cls={cls}
                    style={style}
                    box={box}
                    formats={missing}
                    onPick={(f) => {
                        s.popClose();
                        restructure((x) => {
                            x.formats.push({ format: f, stores: [{ store: '', price: null, url: null, ok: true }] });
                            x.formats.sort((a, c) => FMTS.indexOf(a.format) - FMTS.indexOf(c.format));
                        }, `.sp-fb[data-f="${f}"] input`);
                    }}
                />
            ),
        });
    }

    return (
        <Card
            k={b.slug}
            head={head(s, b)}
            saved={s.saved}
            onClose={() => s.closeCard(true)}
            foot={
                <>
                    <div className="l">
                        {missing.length > 0 && (
                            <button className="w-act" type="button" aria-haspopup="listbox" onClick={(e) => addFormat(e.currentTarget)}>
                                {I.plus15}
                                <span>Add a format</span>
                            </button>
                        )}
                    </div>
                    <div className="r">
                        <button
                            className="w-act nt-del"
                            type="button"
                            aria-haspopup="dialog"
                            onClick={(e) =>
                                s.confirm(e.currentTarget, 'Take this out of In print?', 'Readers stop seeing it there once you publish.', 'Take it out', () => {
                                    const c = document.getElementById('spO');
                                    const go = () => {
                                        s.setOpen('');
                                        upd((d) => (d.books = d.books.filter((x) => x.slug !== b.slug)));
                                    };
                                    if (c) {
                                        bye(c, go);
                                    } else {
                                        go();
                                    }
                                })
                            }
                        >
                            {I.x15}
                            <span>Take out of In print</span>
                        </button>
                    </div>
                </>
            }
        >
            <div className="sp-srh">
                <span>Store</span>
                <span>Price</span>
                <span>Link</span>
            </div>
            <div id="spFm" ref={fm}>
                {b.formats.length ? (
                    b.formats.map((f, fi) => (
                        <div className="sp-fb" data-f={f.format} key={`${gen}-${f.format}`}>
                            <div className="sp-fh">
                                <b>{f.format}</b>
                                <span className="k-meta">{f.stores.length > 1 && fromPrice(f.stores) ? 'from ' + fromPrice(f.stores) : ''}</span>
                                <button
                                    className="w-act sp-frm"
                                    type="button"
                                    aria-haspopup="dialog"
                                    onClick={(e) => {
                                        const el = e.currentTarget.closest<HTMLElement>('.sp-fb');
                                        s.confirm(e.currentTarget, `Remove ${f.format}?`, 'Its stores go with it.', 'Remove', () => {
                                            const go = () => restructure((x) => x.formats.splice(fi, 1), null);
                                            if (el) {
                                                bye(el, go);
                                            } else {
                                                go();
                                            }
                                        });
                                    }}
                                >
                                    {I.x14}
                                    <span>Remove</span>
                                </button>
                            </div>
                            {f.stores.map((st, si) => (
                                <StoreRow
                                    key={`${gen}-${si}`}
                                    st={st}
                                    s={s}
                                    onChange={(n) => ub((x) => (x.formats[fi].stores[si] = n))}
                                    onRemove={(el) => bye(el, () => restructure((x) => x.formats[fi].stores.splice(si, 1), null))}
                                />
                            ))}
                            <button className="w-act sp-add" type="button" onClick={() => restructure((x) => x.formats[fi].stores.push({ store: '', price: null, url: null, ok: true }), `.sp-fb[data-f="${f.format}"] .sp-sr:last-of-type input`)}>
                                {I.plus15}
                                <span>Add a store</span>
                            </button>
                        </div>
                    ))
                ) : (
                    <p className="sp-none">Add a format, then its stores.</p>
                )}
            </div>
        </Card>
    );
}

function FormatPick({ cls, style, box, formats, onPick }: { cls: string; style: CSSProperties; box: RefObject<HTMLDivElement>; formats: string[]; onPick: (f: string) => void }) {
    const [act, setAct] = useState(0);
    const ops = useRef<(HTMLDivElement | null)[]>([]);
    useEffect(() => {
        window.setTimeout(() => ops.current[0]?.focus(), 60);
    }, []);

    return (
        <div className={`${cls} sp-fp`} role="dialog" aria-label="Add a format" ref={box} style={style}>
            <div
                className="nt-lbl"
                role="listbox"
                aria-label="Formats"
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        const i = Math.max(0, Math.min(formats.length - 1, act + (e.key === 'ArrowDown' ? 1 : -1)));
                        setAct(i);
                        ops.current[i]?.focus();
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        onPick(formats[act]);
                    }
                }}
            >
                {formats.map((f, i) => (
                    <div
                        role="option"
                        key={f}
                        tabIndex={-1}
                        aria-selected={false}
                        className={i === act ? 'act' : undefined}
                        ref={(el) => {
                            ops.current[i] = el;
                        }}
                        onFocus={() => setAct(i)}
                        onClick={() => onPick(f)}
                    >
                        <span>{f}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ComingCard({ b, s, upd }: { b: Coming; s: SiteCtx; upd: (fn: (d: InPrint) => void) => void }) {
    const uc = (fn: (x: Coming) => void) => upd((d) => fn(d.coming.find((x) => x.slug === b.slug)!));
    const o = { ...outOf(b) };
    if (!o.y) {
        o.y = String(new Date().getFullYear() + 1);
    }
    const pre = useRef<HTMLDivElement>(null);
    const [T0y, T0m, T0d] = today().split('-');
    const years = [0, 1, 2, 3, 4].map((i) => String(+T0y + i));
    const len = o.m ? new Date(+o.y, +o.m, 0).getDate() : 31;
    const past = outPast(o);

    function setOut(n: { d: string; m: string; y: string }) {
        if (n.m && n.y === T0y && n.m < T0m) {
            n.m = '';
        }
        if (!n.m) {
            n.d = '';
        }
        const l = n.m ? new Date(+n.y, +n.m, 0).getDate() : 31;
        if (n.d && +n.d > l) {
            n.d = pad(l);
        }
        uc((x) => {
            x.out = n;
            x.when = outWords(n);
        });
    }

    return (
        <Card
            k={b.slug}
            head={head(s, b)}
            saved={s.saved}
            onClose={() => s.closeCard(true)}
            foot={
                <>
                    <div className="l">
                        <button
                            className="w-act"
                            type="button"
                            onClick={() => {
                                upd((d) => {
                                    d.coming = d.coming.filter((x) => x.slug !== b.slug);
                                    d.books.push({ slug: b.slug, title: b.title, kind: b.kind, cover: b.cover, formats: b.store ? [{ format: 'Paperback', stores: [{ store: b.store, price: b.price, url: b.url, ok: true }] }] : [] });
                                });
                                s.setOpen(b.slug);
                                s.after(() => inA(document.getElementById('spO')));
                            }}
                        >
                            {I.book15}
                            <span>It’s out: move to In print</span>
                        </button>
                    </div>
                    <div className="r">
                        <button
                            className="w-act nt-del"
                            type="button"
                            aria-haspopup="dialog"
                            onClick={(e) =>
                                s.confirm(e.currentTarget, 'Take this off Coming?', '', 'Take it off', () => {
                                    const c = document.getElementById('spO');
                                    const go = () => {
                                        s.setOpen('');
                                        upd((d) => (d.coming = d.coming.filter((x) => x.slug !== b.slug)));
                                    };
                                    if (c) {
                                        bye(c, go);
                                    } else {
                                        go();
                                    }
                                })
                            }
                        >
                            {I.x15}
                            <span>Take off Coming</span>
                        </button>
                    </div>
                </>
            }
        >
            <div className="sp-seg2">
                <span className="k-fl">Readers can</span>
                <div className="nw-seg sp-seg" role="radiogroup" aria-label="Readers can">
                    {(
                        [
                            ['pre', 'Pre-order'],
                            ['follow', 'Follow'],
                        ] as const
                    ).map(([m, t]) => (
                        <button
                            key={m}
                            type="button"
                            role="radio"
                            aria-checked={b.mode === m}
                            className={b.mode === m ? 'on' : ''}
                            onClick={() => {
                                if (b.mode === m) {
                                    return;
                                }
                                uc((x) => (x.mode = m));
                                if (m === 'pre') {
                                    s.after(() => pre.current && grow(pre.current, 0));
                                }
                            }}
                        >
                            {t}
                        </button>
                    ))}
                </div>
            </div>
            <div className="sp-out">
                <div className="k-f sp-f">
                    <span className="k-fl" id="spOutL">
                        Out
                    </span>
                    <div className="sp-dt sp-odt">
                        <KSelect
                            className="sp-ks"
                            buttonClassName="k-sbtn sp-sel sm"
                            label="Day"
                            disabled={!o.m}
                            value={o.d}
                            options={['', ...[...Array(31)].map((_, i) => pad(i + 1))].map((v) => ({ value: v, label: v ? String(+v) : 'Any day', disabled: !!v && (+v > len || (o.y === T0y && o.m === T0m && v < T0d)) }))}
                            onPick={(v) => setOut({ ...o, d: v })}
                        />
                        <KSelect
                            className="sp-ks"
                            buttonClassName="k-sbtn sp-sel sm"
                            label="Month"
                            value={o.m}
                            options={['', ...MON.map((_, i) => pad(i + 1))].map((v) => ({ value: v, label: v ? MONTH[+v - 1] : 'Any month', disabled: !!v && o.y === T0y && v < T0m }))}
                            onPick={(v) => setOut({ ...o, m: v })}
                        />
                        <KSelect className="sp-ks" buttonClassName="k-sbtn sp-sel sm" label="Year" value={o.y} options={years.map((v) => ({ value: v, label: v }))} onPick={(v) => setOut({ ...o, y: v })} />
                    </div>
                    <p className="k-err sp-oerr" id="spOE" hidden={!past}>
                        That date has passed.
                    </p>
                </div>
            </div>
            <div className="sp-pre" ref={pre} hidden={b.mode !== 'pre'}>
                <div className="sp-srh">
                    <span>Store</span>
                    <span>Price</span>
                    <span>Link</span>
                </div>
                <StoreRow
                    st={{ store: b.store, price: b.price, url: b.url, ok: true }}
                    s={s}
                    removable={false}
                    onChange={(n) =>
                        uc((x) => {
                            x.store = n.store;
                            x.price = n.price as Money;
                            x.url = n.url;
                        })
                    }
                />
            </div>
        </Card>
    );
}

function ReadingCard({ e, s, upd }: { e: Reading; s: SiteCtx; upd: (fn: (d: InPrint) => void) => void }) {
    const ue = (fn: (x: Reading) => void) => upd((d) => fn(d.events.find((x) => x.id === e.id)!));
    const { d, tm } = watParts(e.starts_at);
    const [y, m, dd] = d.split('-');
    const T = today();
    const [fut] = useState(d >= T);
    const [Ty, Tm, Td] = T.split('-');
    const Y0 = +Ty;
    const yrs = fut ? [0, 1, 2].map((i) => String(Y0 + i)) : [...Array(5)].map((_, i) => String(Y0 - 3 + i));
    if (!yrs.includes(y)) {
        yrs.push(y);
        yrs.sort();
    }
    const len = new Date(+y, +m, 0).getDate();
    const [link, setLink] = useState(bareUrl(e.url));
    const [bad, setBad] = useState(false);
    const t = useRef(0);
    const n2 = useRef<HTMLDivElement>(null);
    const cancelBtn = useRef<HTMLButtonElement>(null);

    function setDate(Y: string, M: string, D: string) {
        if (fut) {
            if (Y < Ty) {
                Y = Ty;
            }
            if (Y === Ty && M < Tm) {
                M = Tm;
            }
        }
        const l = new Date(+Y, +M, 0).getDate();
        if (+D > l) {
            D = pad(l);
        }
        if (fut && Y === Ty && M === Tm && D < Td) {
            D = Td;
        }
        ue((x) => (x.starts_at = utcOf(`${Y}-${M}-${D}`, tm)));
    }
    function checkLink(v: string, show: boolean) {
        const good = !v || isLink(v);
        if (good) {
            ue((x) => (x.url = fullUrl(v)));
        }
        if (good || show) {
            setBad(!good);
        }
    }

    return (
        <Card
            k={e.id}
            head={
                <span className="nt-st">
                    {I.cal15}
                    <span>{d < T ? 'Past reading' : 'Reading'}</span>
                </span>
            }
            saved={s.saved}
            onClose={() => s.closeCard(true)}
            foot={
                <>
                    <div className="l">
                        <button
                            className="w-act"
                            type="button"
                            ref={cancelBtn}
                            onClick={() => {
                                turn(cancelBtn.current, () => ue((x) => (x.cancelled = !x.cancelled)));
                            }}
                        >
                            {e.cancelled ? I.undo15 : I.x15}
                            <span>{e.cancelled ? 'It’s back on' : 'Mark as cancelled'}</span>
                        </button>
                    </div>
                    <div className="r">
                        <button
                            className="w-act nt-del"
                            type="button"
                            aria-haspopup="dialog"
                            onClick={(ev) =>
                                s.confirm(ev.currentTarget, 'Delete this reading?', '', 'Delete', () => {
                                    const c = document.getElementById('spO');
                                    const go = () => {
                                        s.setOpen('');
                                        upd((dd2) => (dd2.events = dd2.events.filter((x) => x.id !== e.id)));
                                    };
                                    if (c) {
                                        bye(c, go);
                                    } else {
                                        go();
                                    }
                                })
                            }
                        >
                            {I.x15}
                            <span>Delete</span>
                        </button>
                    </div>
                </>
            }
        >
            <Field id="spEt" label="Name" value={e.title} placeholder="A reading" onChange={(v) => ue((x) => (x.title = v))} />
            <div className="sp-n3">
                <div className="k-f sp-f">
                    <span className="k-fl" id="spEdL">
                        Day
                    </span>
                    <div className="sp-dt">
                        <KSelect
                            className="sp-ks"
                            buttonClassName="k-sbtn sp-sel sm"
                            label="Day"
                            value={dd}
                            options={[...Array(31)].map((_, i) => pad(i + 1)).map((v) => ({ value: v, label: String(+v), disabled: +v > len || (fut && y === Ty && m === Tm && v < Td) }))}
                            onPick={(v) => setDate(y, m, v)}
                        />
                        <KSelect
                            className="sp-ks"
                            buttonClassName="k-sbtn sp-sel sm"
                            label="Month"
                            value={m}
                            options={MON.map((mm, i) => ({ value: pad(i + 1), label: mm, disabled: fut && y === Ty && pad(i + 1) < Tm }))}
                            onPick={(v) => setDate(y, v, dd)}
                        />
                        <KSelect className="sp-ks" buttonClassName="k-sbtn sp-sel sm" label="Year" value={y} options={yrs.map((v) => ({ value: v, label: v }))} onPick={(v) => setDate(v, m, dd)} />
                    </div>
                </div>
                <div className="k-f sp-f">
                    <span className="k-fl" id="spEtmL">
                        Time, WAT
                    </span>
                    <KSelect className="sp-ks" buttonClassName="k-sbtn sp-sel" labelledBy="spEtmL" value={tm} options={TIMES.map((v) => ({ value: v, label: tFmt(v) }))} onPick={(v) => ue((x) => (x.starts_at = utcOf(d, v)))} />
                </div>
                <div className="k-f sp-f">
                    <span className="k-fl">Where</span>
                    <div className="nw-seg sp-seg sm" role="radiogroup" aria-label="Where">
                        {(
                            [
                                [false, 'A place'],
                                [true, 'Online'],
                            ] as const
                        ).map(([on, label]) => (
                            <button
                                key={label}
                                type="button"
                                role="radio"
                                aria-checked={e.online === on}
                                className={e.online === on ? 'on' : ''}
                                onClick={() => {
                                    if (e.online === on) {
                                        return;
                                    }
                                    ue((x) => (x.online = on));
                                    s.after(() => inA(n2.current));
                                }}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
            <div className="sp-n2" ref={n2} key={e.online ? 'on' : 'place'}>
                {!e.online && <Field id="spEp" label="Place" value={e.place} placeholder="Town or venue" onChange={(v) => ue((x) => (x.place = v))} />}
                <Field
                    id="spEu"
                    label={e.online ? 'Link to join' : 'Details link'}
                    value={link}
                    placeholder={e.online ? 'youtube.com/live/…' : 'A page about it'}
                    inputMode="url"
                    bad={bad}
                    onChange={(v) => {
                        setLink(v);
                        window.clearTimeout(t.current);
                        if (bad) {
                            checkLink(v.trim(), true);
                        } else {
                            checkLink(v.trim(), false);
                            t.current = window.setTimeout(() => checkLink(v.trim(), true), 900);
                        }
                    }}
                    after={
                        <p className="k-err sp-uerr" hidden={!bad}>
                            That isn’t a link.
                        </p>
                    }
                />
            </div>
        </Card>
    );
}

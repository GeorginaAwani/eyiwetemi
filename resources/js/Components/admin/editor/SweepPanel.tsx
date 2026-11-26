import { Link } from '@inertiajs/react';
import { EditorView } from 'prosemirror-view';
import { useEffect, useRef, useState } from 'react';
import { json } from '../../../lib/http';
import { prefersReducedMotion } from '../../../lib/motion';
import { ACCENTS, tone } from '../../../lib/tone';
import { Unfold } from '../Controls';
import { BoxTick, Close, Plus, Ring } from '../Icons';
import { FoldIcon, PinIcon, PovIcon } from './icons';
import { Hit, hitsInJson, hitsInView, keepCase, replaceInJson, replaceInView, SweepName, termsOf } from './sweep';

type Page = { id: number; number: number; body: Record<string, unknown> | null };
type Props = {
    view: EditorView | null;
    slug: string;
    itemId: number;
    multi: boolean;
    novel: boolean;
    unit: string;
    names: SweepName[];
    setNames: (n: SweepName[]) => void;
    mark: boolean;
    setMark: (on: boolean) => void;
    say: (t: string) => void;
    startOnNames?: boolean;
    kick: (page: number) => string;
};

/**
 * Sweep: the names she typed plain, found in this chapter (or the whole book), each with its marked form; open a
 * name to see every place, skip any, pick a reading where a name has two; then Replace. Names lists them all.
 */
export default function SweepPanel({ view, slug, itemId, multi, novel, unit, names, setNames, mark, setMark, say, startOnNames, kick }: Props) {
    const [scope, setScope] = useState<'ch' | 'bk' | 'names'>(startOnNames ? 'names' : 'ch');
    const [open, setOpen] = useState(0);
    const [skips, setSkips] = useState<Set<string>>(new Set());
    const [picks, setPicks] = useState<Record<string, string>>({});
    const [once, setOnce] = useState<[string, string][]>([]);
    const [pages, setPages] = useState<Page[] | null>(null);
    const [busy, setBusy] = useState<string>('');
    const [fr, setFr] = useState(false);
    const [frA, setFrA] = useState('');
    const [frB, setFrB] = useState('');

    useEffect(() => {
        if (scope === 'bk' && pages === null) {
            json<{ pages: Page[] }>('GET', `/works/${slug}/sweep/pages`).then(({ data }) => setPages(data.pages ?? []));
        }
    }, [scope, pages, slug]);

    const terms = termsOf(names, once);
    const hits: Hit[] = [];
    if (view && scope !== 'names') {
        hits.push(...hitsInView(view, itemId, terms));
        if (scope === 'bk') {
            (pages ?? []).filter((p) => p.id !== itemId).forEach((p) => hits.push(...hitsInJson(p.body as never, p.id, terms)));
        }
    }
    const byTerm = terms.map((t, i) => ({ t, list: hits.filter((h) => h.term === i) }));
    const total = hits.length;
    const ready = (h: Hit) => !skips.has(h.key) && (terms[h.term].opts.length < 2 || picks[h.key]);
    const left = hits.filter(ready).length;

    async function replace(only: number | null, key: string) {
        setBusy(key);
        const chosen = hits.filter((h) => (only === null || h.term === only) && ready(h)).map((h) => ({ hit: h, rep: keepCase(h.match, terms[h.term].opts.length > 1 ? picks[h.key] : terms[h.term].opts[0]) }));
        if (view) {
            replaceInView(view, chosen.filter((c) => c.hit.page === itemId));
        }
        const others = new Map<number, typeof chosen>();
        chosen.filter((c) => c.hit.page !== itemId).forEach((c) => others.set(c.hit.page, [...(others.get(c.hit.page) ?? []), c]));
        for (const [id, list] of others) {
            const page = pages?.find((p) => p.id === id);
            if (page?.body) {
                await json('PATCH', `/works/${slug}/write/${id}`, { body: replaceInJson(page.body as never, list) });
            }
        }
        if (others.size) {
            setPages(null);
        }
        setSkips(new Set());
        setPicks({});
        if (only === null) {
            setOnce([]);
        }
        setBusy('');
        say(chosen.length ? `Replaced ${chosen.length}` : 'Nothing replaced');
    }

    const tabs: ['ch' | 'bk' | 'names', string][] = [['ch', `This ${multi ? unit : 'piece'}`], ...(multi ? [['bk', `Whole ${novel ? 'book' : 'collection'}`] as ['bk', string]] : []), ['names', 'Names']];

    return (
        <>
            <div className="w-ph">
                <h2>Sweep</h2>
                <span className="k-meta" id="swN">
                    {scope !== 'names' && total ? `${total} ${total === 1 ? 'match' : 'matches'}` : ''}
                </span>
            </div>
            <nav className="k-wtabs w-st" role="tablist">
                {tabs.map(([k, t]) => (
                    <a
                        key={k}
                        href="#"
                        role="tab"
                        className={scope === k ? 'on' : ''}
                        aria-selected={scope === k}
                        onClick={(e) => {
                            e.preventDefault();
                            setScope(k);
                            setOpen(0);
                        }}
                    >
                        {t}
                    </a>
                ))}
            </nav>
            {scope === 'names' ? (
                <Names slug={slug} names={names} setNames={setNames} mark={mark} setMark={setMark} />
            ) : !terms.length ? (
                <p className="w-empty">
                    No names to sweep yet. Add them in{' '}
                    <a
                        className="k-l"
                        href="#"
                        onClick={(e) => {
                            e.preventDefault();
                            setScope('names');
                        }}
                    >
                        <span>Names</span>
                    </a>
                    .
                </p>
            ) : (
                <>
                    <div className="w-sws">
                        {byTerm.map(({ t, list }, i) => {
                            const n = list.length;
                            const clash = t.opts.length > 1;
                            const isOpen = i === open && n > 0;

                            return (
                                <div className={`w-sw${isOpen ? ' open' : ''}`} key={`${t.a}${i}`}>
                                    <div className="w-swh">
                                        <button type="button" className="w-swt" aria-expanded={isOpen} onClick={() => setOpen(open === i ? -1 : i)}>
                                            <span style={{ display: 'grid', transform: isOpen ? 'none' : 'rotate(-90deg)', transition: 'transform var(--d2) var(--ease-dream)' }}>
                                                <FoldIcon />
                                            </span>
                                            <span className="fr">{t.a}</span>
                                            <span className="ar">→</span>
                                            <span className={`w-to${clash ? ' clash' : ''}`}>{t.opts.join(' or ')}</span>
                                        </button>
                                        <span className="w-cnt">{n}</span>
                                        {!n ? (
                                            <span className="k-fact">None</span>
                                        ) : clash && !list.every((x) => picks[x.key] || skips.has(x.key)) ? (
                                            <span className="k-fact w-rose">Pick each</span>
                                        ) : (
                                            <button className={`w-act${busy === `t${i}` ? ' busy' : ''}`} type="button" onClick={() => replace(i, `t${i}`)}>
                                                Replace
                                            </button>
                                        )}
                                    </div>
                                    {isOpen && (
                                        <div className="w-ctxs">
                                            {list.map((x) => {
                                                const sk = skips.has(x.key);

                                                return (
                                                    <div className={`w-ctx${sk ? ' sk' : ''}`} key={x.key}>
                                                        <span className="c">
                                                            {x.pre ? '…' : ''}
                                                            {x.ctx[0]}
                                                            <b className="s">{x.ctx[1]}</b>
                                                            {x.ctx[2]}
                                                            {x.post ? '…' : ''}
                                                            {scope === 'bk' && x.page !== itemId && <small> · {kick(x.page)}</small>}
                                                            {x.note && <small> · note</small>}
                                                        </span>
                                                        {clash && !sk && (
                                                            <span className="w-pk">
                                                                {t.opts.map((op) => (
                                                                    <button key={op} type="button" className={picks[x.key] === op ? 'on' : ''} onClick={() => setPicks({ ...picks, [x.key]: op })}>
                                                                        {keepCase(x.match, op)}
                                                                    </button>
                                                                ))}
                                                            </span>
                                                        )}
                                                        <button
                                                            className="w-act"
                                                            type="button"
                                                            onClick={() => {
                                                                const next = new Set(skips);
                                                                if (sk) {
                                                                    next.delete(x.key);
                                                                } else {
                                                                    next.add(x.key);
                                                                }
                                                                setSkips(next);
                                                            }}
                                                        >
                                                            {sk ? 'Undo' : 'Skip'}
                                                        </button>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <Unfold show={fr} id="frU">
                        <div className="w-fr">
                            <span className="k-in sm">
                                <input id="frA" placeholder="Find" autoComplete="off" value={frA} onChange={(e) => setFrA(e.target.value)} />
                            </span>
                            <span className="k-in sm">
                                <input id="frB" placeholder="Replace with" autoComplete="off" value={frB} onChange={(e) => setFrB(e.target.value)} />
                            </span>
                            <button
                                className="k-btn sm line"
                                type="button"
                                onClick={() => {
                                    if (frA.trim()) {
                                        setOnce([...once, [frA.trim(), frB]]);
                                        setOpen(terms.length);
                                        setFrA('');
                                        setFrB('');
                                    }
                                }}
                            >
                                <span>Add</span>
                            </button>
                        </div>
                    </Unfold>
                    <div className="w-pf">
                        <button className="w-act" type="button" onClick={() => setFr(!fr)}>
                            <Plus />
                            Find and replace
                        </button>
                        <button className={`k-btn line sm${busy === 'all' ? ' busy' : ''}`} type="button" disabled={!left} onClick={() => replace(null, 'all')}>
                            <span>{left ? `Replace ${left}` : 'Nothing to replace'}</span>
                            <Ring />
                        </button>
                    </div>
                </>
            )}
        </>
    );
}

/** Names: every name Sweep knows. The Plan's come by themselves (open them there); others are added here. */
function Names({ slug, names, setNames, mark, setMark }: { slug: string; names: SweepName[]; setNames: (n: SweepName[]) => void; mark: boolean; setMark: (on: boolean) => void }) {
    const [a, setA] = useState('');
    const [b, setB] = useState('');
    const bRef = useRef<HTMLInputElement>(null);
    const aRef = useRef<HTMLInputElement>(null);

    async function add() {
        const plain = a.trim().replace(/\s+/g, ' ');
        if (!plain) {
            return;
        }
        const readings = tone(b.trim())
            .split(/\s*\|\s*/)
            .filter(Boolean)
            .join('|');
        const { data } = await json<{ names: SweepName[] }>('POST', `/works/${slug}/sweep/names`, { plain, readings: readings || plain });
        if (data.names) {
            setNames(data.names);
            setA('');
            setB('');
            window.setTimeout(() => aRef.current?.focus(), prefersReducedMotion() ? 0 : 20);
        }
    }

    return (
        <>
            <div className="w-nms">
                {names.length ? (
                    names.map((n) => (
                        <div className="w-nm" key={`${n.plain}${n.id ?? n.from}`}>
                            <span className="fr">{n.plain}</span>
                            <span className="ar">→</span>
                            <span className="to">{n.readings.join(' or ')}</span>
                            {n.from ? (
                                <Link className="ad-ic w-nmp" href={`/works/${slug}?tab=plan&s=${n.from.startsWith('c:') ? 'ch' : 'world'}&e=${n.from.slice(2)}`} aria-label={`Open ${n.readings[0]} in Plan`} title="From the plan">
                                    {n.from.startsWith('c:') ? <PovIcon /> : <PinIcon size={15} />}
                                </Link>
                            ) : (
                                <button
                                    className="ad-ic"
                                    type="button"
                                    aria-label={`Remove ${n.plain}`}
                                    onClick={async () => {
                                        const { data } = await json<{ names: SweepName[] }>('DELETE', `/works/${slug}/sweep/names/${n.id}`);
                                        if (data.names) {
                                            setNames(data.names);
                                        }
                                    }}
                                >
                                    <Close size={16} width={2} />
                                </button>
                            )}
                        </div>
                    ))
                ) : (
                    <p className="w-empty">No names yet.</p>
                )}
            </div>
            <div className="w-nadd">
                <span className="k-in sm">
                    <input ref={aRef} id="nA" placeholder="Plain, e.g. Xu Wei" autoComplete="off" spellCheck={false} aria-label="Plain name" value={a} onChange={(e) => setA(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), add())} />
                </span>
                <span className="k-in sm">
                    <input
                        ref={bRef}
                        id="nB"
                        placeholder="With accents, e.g. Xu2 Wei1"
                        autoComplete="off"
                        spellCheck={false}
                        aria-label="Name with accents"
                        value={b}
                        onChange={(e) => setB(tone(e.target.value))}
                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), add())}
                    />
                </span>
                <button className="k-btn sm line" type="button" id="nGo" disabled={!a.trim()} onClick={add}>
                    <span>Add</span>
                </button>
            </div>
            <div className="w-acc" aria-label="Accents">
                {ACCENTS.map((c) => (
                    <button
                        key={c}
                        type="button"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => {
                            const i = bRef.current;
                            const s = i?.selectionStart ?? b.length;
                            setB(b.slice(0, s) + c + b.slice(i?.selectionEnd ?? s));
                            window.setTimeout(() => (i?.focus(), i?.setSelectionRange(s + 1, s + 1)), 0);
                        }}
                    >
                        {c}
                    </button>
                ))}
            </div>
            <p className="w-hint">Tone numbers become marks: Xu2 Wei1 → Xú Wēi. For a name with two readings, write both: Li3 | Li2.</p>
            <div className="w-pf">
                <label className="ad-cb">
                    <input type="checkbox" checked={mark} onChange={(e) => setMark(e.target.checked)} />
                    <span className="ad-box">
                        <BoxTick />
                    </span>
                    Mark names while writing
                </label>
            </div>
        </>
    );
}

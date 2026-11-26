import { Link } from '@inertiajs/react';
import { useLayoutEffect, useRef, useState } from 'react';
import { json } from '../../../lib/http';
import { plainName } from '../../../lib/tone';
import { Unfold } from '../Controls';
import { Plus, Search } from '../Icons';
import type { Plan } from '../PlanTab';
import { ExtIcon, FileIcon19, FoldIcon, ImgIcon19, LinkIcon19, NoteIcon19, PinIcon } from './icons';
import { wordRx } from './sweep';

type Char = Plan['chars'][number];
const COLS = ['var(--accent)', 'var(--plum)', 'var(--wine)', 'var(--muted)', 'var(--deep)', 'var(--blush)'];
const textOf = (html: string) => {
    const t = document.createElement('template');
    t.innerHTML = html;

    return t.content.textContent ?? '';
};
const namesOf = (c: { name: string; typedAs: string; aliases?: string[] }) => [...new Set([c.name, c.typedAs || plainName(c.name), ...(c.aliases ?? [])].filter(Boolean))];

/** The small chapter strip in a card: POV, in it, not written. */
function MiniStrip({ plan, pov, inIt, slug }: { plan: Plan; pov: Set<number>; inIt: Set<number>; slug: string }) {
    return (
        <div className="pl-strip sm">
            <div className="pl-sqs">
                {plan.chapters.map((c) => (
                    <Link key={c.id} href={`/works/${slug}/write/${c.id}`} className={`sq ${pov.has(c.id) ? 'p' : inIt.has(c.id) ? 'i' : c.written ? '' : 'u'}`} title={c.title} aria-label={`Chapter ${c.number}`}>
                        {c.number}
                    </Link>
                ))}
            </div>
        </div>
    );
}

/** A character's card: role, a couple of relationships, the chapter strip, Open in Plan. */
export function CardBody({ c, plan, slug, full }: { c: Char; plan: Plan; slug: string; full: boolean }) {
    const rels = c.links.slice(0, full ? 9 : 2);
    const notes = textOf(c.notes);

    return (
        <>
            {c.role && !full && <span className="rl">{c.role}</span>}
            {rels.length > 0 && (
                <div className="pl-rels sm">
                    {rels.map((r) => (
                        <div className="pl-rel" key={r.id}>
                            <span className="nm">{plan.chars.find((o) => o.id === r.to)?.name}</span>
                            <span className="lb">{r.label}</span>
                        </div>
                    ))}
                </div>
            )}
            {full && notes && (
                <p className="pl-nx">
                    {notes.slice(0, 240)}
                    {notes.length > 240 ? '…' : ''}
                </p>
            )}
            <MiniStrip plan={plan} slug={slug} pov={new Set(c.pov)} inIt={new Set(c.in)} />
            <div className="ft">
                <span className="k-meta">
                    POV {c.pov.length} · in {new Set([...c.pov, ...c.in]).size}
                </span>
                <Link className="k-l" href={`/works/${slug}?tab=plan&s=ch&e=${c.id}`}>
                    <span>Open in Plan</span>
                </Link>
            </div>
        </>
    );
}

/**
 * The Plan beside the page: for a novel, what's in this chapter (its plot points, who's in it, research tied to it),
 * then Characters, World and Research; for other works, Notes and Research. Search reaches the whole plan.
 */
export default function PlanPanel({ plan, setPlan, slug, itemId, isPart, text, pov }: { plan: Plan; setPlan: (p: Plan) => void; slug: string; itemId: number; isPart: boolean; text: string; pov: number | null }) {
    const novel = plan.sections.includes('ch');
    const tabs: [string, string][] = novel ? [['this', isPart ? 'This part' : 'This chapter'], ['ch', 'Characters'], ['world', 'World'], ['res', 'Research']] : [['notes', 'Notes'], ['res', 'Research']];
    const [tab, setTab] = useState(tabs[0][0]);
    const [open, setOpen] = useState<string>('');
    const [card, setCard] = useState<number | null>(null);
    const [search, setSearch] = useState(false);
    const [q, setQ] = useState('');
    const base = `/works/${slug}/plan`;

    async function api(method: 'POST' | 'PATCH', path: string, body: Record<string, unknown>) {
        const { data } = await json<{ plan: Plan }>(method, `${base}/${path}`, body);
        if (data.plan) {
            setPlan(data.plan);
        }

        return data.plan ?? null;
    }

    const here = (n: string[]) => n.some((x) => wordRx(x).test(text));
    const inCh = plan.chars.filter((c) => c.id === pov || here(namesOf(c)));
    const ents = plan.groups.flatMap((g) => g.entries).filter((e) => here(namesOf(e)));
    const ties = new Set([`i:${itemId}`, ...inCh.map((c) => `c:${c.id}`), ...ents.map((e) => `e:${e.id}`)]);
    const research = (list: Plan['research']) => (
        <div className="pl-ris pan">
            {list.map((r) => (
                <ResRow key={r.id} r={r} slug={slug} />
            ))}
        </div>
    );

    let body: JSX.Element;
    const k = plainName(q.trim()).toLowerCase();
    if (k) {
        const hit = (...s: string[]) => s.some((x) => x && plainName(x).toLowerCase().includes(k));
        const rows = [
            ...plan.chars.filter((c) => hit(c.name, c.typedAs, c.role, textOf(c.notes), ...c.aliases)).map((c) => ({ key: `c${c.id}`, name: c.name, sub: 'Character', href: `?tab=plan&s=ch&e=${c.id}` })),
            ...plan.groups.flatMap((g) => g.entries.filter((e) => hit(e.name, e.typedAs, e.description, textOf(e.notes))).map((e) => ({ key: `e${e.id}`, name: e.name, sub: g.name, href: `?tab=plan&s=world&e=${e.id}` }))),
            ...plan.points.filter((p) => hit(p.text)).map((p) => ({ key: `p${p.id}`, name: p.text, sub: 'Plot', href: '?tab=plan&s=plot' })),
            ...plan.research.filter((r) => hit(r.title, textOf(r.note))).map((r) => ({ key: `r${r.id}`, name: r.title || 'Untitled', sub: 'Research', href: `?tab=plan&s=res&e=${r.id}` })),
        ];
        body = rows.length ? (
            <div className="pp-s pp-rows">
                {rows.map((r) => (
                    <Link key={r.key} className="pp-h pl-hit" href={`/works/${slug}${r.href}`}>
                        <span className="nm">{r.name}</span>
                        <span className="rl">{r.sub}</span>
                    </Link>
                ))}
            </div>
        ) : (
            <p className="w-empty">
                Nothing called <b>{q.trim()}</b>.
            </p>
        );
    } else if (tab === 'notes') {
        body = <PanelNotes key="notes" html={plan.notes} onSave={(h) => api('PATCH', 'notes', { html: h })} />;
    } else if (tab === 'this') {
        const pts = plan.points.filter((p) => p.item === itemId);
        const cardChar = plan.chars.find((c) => c.id === card);
        body = (
            <>
                {!isPart && (
                    <div className="pl-wsec">
                        <h4>Plot</h4>
                        <div className="pl-bts pan">
                            {pts.map((p) => (
                                <div className="pl-bt" key={p.id} style={{ '--tc': COLS[(plan.threads.find((t) => t.id === p.thread)?.colour ?? 0) % 6] } as React.CSSProperties}>
                                    <span className="bar" />
                                    <PointText initial={p.text === '…' ? '' : p.text} onSave={(v) => api('PATCH', `points/${p.id}`, { text: v || '…' })} />
                                </div>
                            ))}
                        </div>
                        <button className="w-act" type="button" onClick={() => plan.threads[0] && api('POST', 'points', { thread: plan.threads[0].id, item: itemId, text: '…' })}>
                            <Plus />
                            Add a point
                        </button>
                    </div>
                )}
                <div className="pl-wsec">
                    <h4>In it</h4>
                    {inCh.length || ents.length ? (
                        <div className="pl-chips">
                            {inCh.map((c) => (
                                <button key={c.id} type="button" className="pl-chip lk" onClick={() => setCard(card === c.id ? null : c.id)}>
                                    {c.name}
                                    {c.id === pov && <small>POV</small>}
                                </button>
                            ))}
                            {ents.map((e) => (
                                <Link key={e.id} className="pl-chip lk" href={`/works/${slug}?tab=plan&s=world&e=${e.id}`}>
                                    <PinIcon size={14} />
                                    {e.name}
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <p className="w-empty">Nobody from the plan yet.</p>
                    )}
                    <div id="ppCard">
                        {cardChar && (
                            <div className="pl-card in">
                                <div className="hd">
                                    <b>{cardChar.name}</b>
                                </div>
                                <CardBody c={cardChar} plan={plan} slug={slug} full={false} />
                            </div>
                        )}
                    </div>
                </div>
                {plan.research.some((r) => r.ties.some((t) => ties.has(t))) && (
                    <div className="pl-wsec">
                        <h4>Research</h4>
                        {research(plan.research.filter((r) => r.ties.some((t) => ties.has(t))))}
                    </div>
                )}
            </>
        );
    } else if (tab === 'ch') {
        body = plan.chars.length ? (
            <div className="pp-rows">
                {plan.chars.map((c) => (
                    <div className={`pp-r${open === `c${c.id}` ? ' open' : ''}`} key={c.id}>
                        <button type="button" className="pp-h" aria-expanded={open === `c${c.id}`} onClick={() => setOpen(open === `c${c.id}` ? '' : `c${c.id}`)}>
                            <span className="nm">{c.name}</span>
                            <span className="rl">{c.role}</span>
                            <FoldIcon />
                        </button>
                        <Unfold show={open === `c${c.id}`}>
                            <div className="pl-card in">
                                <CardBody c={c} plan={plan} slug={slug} full />
                            </div>
                        </Unfold>
                    </div>
                ))}
            </div>
        ) : (
            <p className="w-empty">No characters yet.</p>
        );
    } else if (tab === 'world') {
        const groups = plan.groups.filter((g) => g.entries.length);
        body = groups.length ? (
            <>
                {groups.map((g) => (
                    <div key={g.id}>
                        <h4 className="pp-g">{g.name}</h4>
                        <div className="pp-rows">
                            {g.entries.map((e) => (
                                <div className={`pp-r${open === `e${e.id}` ? ' open' : ''}`} key={e.id}>
                                    <button type="button" className="pp-h" aria-expanded={open === `e${e.id}`} onClick={() => setOpen(open === `e${e.id}` ? '' : `e${e.id}`)}>
                                        <span className="nm">{e.name}</span>
                                        <span className="rl">{e.description}</span>
                                        <FoldIcon />
                                    </button>
                                    <Unfold show={open === `e${e.id}`}>
                                        <div className="pl-card in">
                                            {textOf(e.notes) && <p className="pl-nx">{textOf(e.notes).slice(0, 240)}</p>}
                                            <MiniStrip plan={plan} slug={slug} pov={new Set()} inIt={new Set(e.in)} />
                                            <div className="ft">
                                                <span />
                                                <Link className="k-l" href={`/works/${slug}?tab=plan&s=world&e=${e.id}`}>
                                                    <span>Open in Plan</span>
                                                </Link>
                                            </div>
                                        </div>
                                    </Unfold>
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </>
        ) : (
            <p className="w-empty">Nothing yet.</p>
        );
    } else {
        body = plan.research.length ? research(plan.research) : <p className="w-empty">Nothing saved yet.</p>;
    }

    return (
        <>
            <div className="w-ph pl-ph2">
                <h2>Plan</h2>
                <span className="pl-pha">
                    <button className="ad-ic" type="button" aria-label="Search the plan" aria-expanded={search} onClick={() => (setSearch(!search), search && setQ(''))}>
                        <Search />
                    </button>
                    <Link className="k-l pl-op" href={`/works/${slug}?tab=plan`}>
                        <span>Open Plan</span>
                    </Link>
                </span>
            </div>
            <Unfold show={search} id="ppQU">
                <label className="pl-q has">
                    <span className="sr">Search the plan</span>
                    <Search />
                    <input className="pl-qi" id="ppQ" value={q} placeholder="Search the plan" autoComplete="off" onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Escape' && q && (e.stopPropagation(), setQ(''))} />
                </label>
            </Unfold>
            <nav className="k-wtabs w-st" role="tablist">
                {tabs.map(([key, label]) => (
                    <a
                        key={key}
                        href="#"
                        role="tab"
                        className={tab === key ? 'on' : ''}
                        aria-selected={tab === key}
                        onClick={(e) => {
                            e.preventDefault();
                            setTab(key);
                            setOpen('');
                        }}
                    >
                        {label}
                    </a>
                ))}
            </nav>
            <div id="ppB">{body}</div>
        </>
    );
}

function ResRow({ r, slug }: { r: Plan['research'][number]; slug: string }) {
    const Icon = { link: LinkIcon19, img: ImgIcon19, note: NoteIcon19, file: FileIcon19 }[r.kind];
    const inner = (
        <>
            <span className="lead">
                {r.kind === 'img' && r.src ? (
                    <span className="th">
                        <img src={r.src} alt="" loading="lazy" />
                    </span>
                ) : (
                    <span className="th ic">
                        <Icon />
                    </span>
                )}
            </span>
            <span className="t">{r.title || 'Untitled'}</span>
            {r.kind === 'link' ? (
                <span className="k-fact">
                    <ExtIcon />
                    {(() => {
                        try {
                            return new URL(r.url).host.replace(/^www\./, '');
                        } catch {
                            return r.url;
                        }
                    })()}
                </span>
            ) : (
                <span className="k-meta">{{ img: 'Image', note: 'Note', file: 'File', link: 'Link' }[r.kind]}</span>
            )}
            <span />
        </>
    );

    return r.kind === 'link' && r.url ? (
        <a className="pl-ri" href={r.url} target="_blank" rel="noopener noreferrer">
            {inner}
        </a>
    ) : (
        <Link className="pl-ri" href={`/works/${slug}?tab=plan&s=res&e=${r.id}`}>
            {inner}
        </Link>
    );
}

function PointText({ initial, onSave }: { initial: string; onSave: (v: string) => void }) {
    const el = useRef<HTMLParagraphElement>(null);
    const timer = useRef(0);
    useLayoutEffect(() => {
        if (el.current) {
            el.current.textContent = initial;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <p
            ref={el}
            className="x"
            contentEditable="plaintext-only"
            suppressContentEditableWarning
            data-ph="What happens"
            onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), el.current?.blur())}
            onInput={() => {
                window.clearTimeout(timer.current);
                timer.current = window.setTimeout(() => onSave(el.current?.textContent?.trim() ?? ''), 500);
            }}
        />
    );
}

function PanelNotes({ html, onSave }: { html: string; onSave: (h: string) => void }) {
    const el = useRef<HTMLDivElement>(null);
    const timer = useRef(0);
    useLayoutEffect(() => {
        if (el.current) {
            el.current.innerHTML = html;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div
            ref={el}
            className="pl-notes ed md pan"
            contentEditable
            suppressContentEditableWarning
            role="textbox"
            aria-multiline="true"
            aria-label="Notes"
            data-ph="Notes"
            spellCheck
            onKeyDown={(e) => {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'i')) {
                    e.preventDefault();
                    document.execCommand(e.key === 'b' ? 'bold' : 'italic');
                }
            }}
            onInput={() => {
                window.clearTimeout(timer.current);
                timer.current = window.setTimeout(() => onSave(el.current?.innerHTML ?? ''), 600);
            }}
        />
    );
}

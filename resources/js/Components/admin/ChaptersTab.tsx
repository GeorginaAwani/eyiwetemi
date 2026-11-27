import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { inertWhen } from '../../lib/dom';
import { heading } from '../../lib/headings';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';
import { dropWork, isKept, keepWork, keptSize, megabytes, useOnline } from '../../lib/offline';
import { loading, Unfold } from './Controls';
import { ChapterRow, StateFact } from './editor/chapters';
import { FoldIcon, GripIcon, Link15, PovIcon } from './editor/icons';
import { BoxTick, CloudOff, Plus, Ring, Search } from './Icons';

export type Contents = { rows: ChapterRow[]; opening: { q: string; by: string } | null; pool: { id: number; title: string; meta: string }[] };
type Look = { slug: string; kind: string; collectionKind: string; headingStyle: string; partWord: string; partStyle: string; showPartNumbers: boolean };

const UNIT: Record<string, [string, string]> = { story: ['story', 'stories'], poem: ['poem', 'poems'], essay: ['essay', 'essays'] };

/**
 * A novel's or collection's contents: parts that fold (folded once all their chapters are out), chapter rows with
 * POV, printed pages and where each stands. New chapter or part goes straight to its page; rows drag to a new
 * place (or Alt + arrow keys); a collection can take in pieces already written.
 */
export default function ChaptersTab({ work, contents }: { work: Look; contents: Contents }) {
    const novel = work.kind === 'novel';
    const coll = work.kind === 'collection';
    const [unit, units] = coll ? (UNIT[work.collectionKind] ?? UNIT.story) : ['chapter', 'chapters'];
    const [rows, setRows] = useState(contents.rows);
    const [reorder, setReorder] = useState(false);
    const [toast, setToast] = useState('');
    const [picking, setPicking] = useState(false);
    const [taken, setTaken] = useState<number[]>([]);
    const [folds, setFolds] = useState<Record<number, boolean>>(() => {
        try {
            return JSON.parse(localStorage.getItem('eyiAdmFold:' + work.slug) ?? '{}');
        } catch {
            return {};
        }
    });
    const list = useRef<HTMLDivElement>(null);
    const before = useRef<Map<string, number> | null>(null);
    const moved = useRef<number | null>(null);

    const chapters = rows.filter((r) => r.type !== 'part');
    const pages = rows.reduce((a, r) => a + r.pages, 0);
    const kick = (r: ChapterRow) => (coll ? `${unit.charAt(0).toUpperCase() + unit.slice(1)} ${r.number}` : heading(r.number, 'Chapter', work.headingStyle));
    const partHead = (n: number) => (work.showPartNumbers ? heading(n, work.partWord || 'Part', work.partStyle) : '');

    const say = (t: string) => {
        setToast(t);
        window.setTimeout(() => setToast(''), 2600);
    };

    /* ---------- order: FLIP the rows into their new places ---------- */
    useLayoutEffect(() => {
        const snap = before.current;
        before.current = null;
        if (!snap || !list.current || prefersReducedMotion()) {
            return;
        }
        list.current.querySelectorAll<HTMLElement>('[data-id]').forEach((x) => {
            const b = snap.get(x.dataset.id!);
            if (b == null) {
                return;
            }
            const d = b - x.getBoundingClientRect().top;
            if (d) {
                x.animate([{ transform: `translateY(${d}px)` }, { transform: 'none' }], { duration: 320, easing: 'cubic-bezier(.22,1,.36,1)' });
            }
        });
    }, [rows]);

    async function move(id: number, afterId: number | null) {
        const i = rows.findIndex((r) => r.id === id);
        if (i < 0 || id === afterId) {
            return;
        }
        const next = rows.slice();
        const [it] = next.splice(i, 1);
        next.splice(afterId ? next.findIndex((r) => r.id === afterId) + 1 : 0, 0, it);
        before.current = new Map([...(list.current?.querySelectorAll<HTMLElement>('[data-id]') ?? [])].map((x) => [x.dataset.id!, x.getBoundingClientRect().top]));
        moved.current = id;
        setRows(next);
        const { status, data } = await json<{ rows: ChapterRow[] }>('PATCH', `/works/${work.slug}/items/order`, { ids: next.map((r) => r.id) });
        if (status === 200 && data.rows) {
            setRows(data.rows);
        } else {
            say('That didn’t save. Try again.');
            setRows(rows);
        }
    }

    /* drag by the grip (mouse or touch); a slot shows where it will land */
    function grab(e: React.PointerEvent, id: number) {
        const grip = e.currentTarget as HTMLElement;
        const el = grip.closest<HTMLElement>('[data-id]')!;
        e.preventDefault();
        const y0 = e.clientY;
        const slot = document.createElement('div');
        slot.className = 'wa-ins';
        el.classList.add('drag');
        el.after(slot);
        try {
            grip.setPointerCapture(e.pointerId);
        } catch {
            /* old browsers */
        }
        const onMove = (ev: PointerEvent) => {
            el.style.transform = `translateY(${ev.clientY - y0}px)`;
            const others = [...list.current!.querySelectorAll<HTMLElement>('[data-id]')].filter((x) => x !== el && x.offsetParent);
            let after: HTMLElement | null = null;
            for (const x of others) {
                const b = x.getBoundingClientRect();
                if (ev.clientY > b.top + b.height / 2) {
                    after = x;
                }
            }
            if (after) {
                const kids = after.classList.contains('wa-pt') ? (after.nextElementSibling as HTMLElement | null) : null;
                if (kids?.classList.contains('open')) {
                    kids.firstElementChild!.prepend(slot);
                } else {
                    after.after(slot);
                }
            } else {
                list.current!.prepend(slot);
            }
        };
        const onUp = () => {
            grip.removeEventListener('pointermove', onMove);
            grip.removeEventListener('pointerup', onUp);
            /* where the slot sits: after the row before it (the last of a folded part's chapters, or the part itself) */
            let target: string | null = null;
            const prev = slot.previousElementSibling as HTMLElement | null;
            if (prev?.dataset.id) {
                target = prev.dataset.id;
            } else if (!prev && slot.parentElement?.parentElement?.classList.contains('w-kids')) {
                target = (slot.parentElement.parentElement.previousElementSibling as HTMLElement).dataset.id ?? null;
            } else if (prev?.classList.contains('w-kids')) {
                const ks = prev.querySelectorAll<HTMLElement>('[data-id]');
                target = ks.length ? ks[ks.length - 1].dataset.id! : ((prev.previousElementSibling as HTMLElement).dataset.id ?? null);
            }
            slot.remove();
            el.classList.remove('drag');
            el.style.transform = '';
            move(id, target ? Number(target) : null);
        };
        grip.addEventListener('pointermove', onMove);
        grip.addEventListener('pointerup', onUp);
    }

    function keys(e: React.KeyboardEvent, id: number) {
        if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
            return;
        }
        e.preventDefault();
        const ids = [...list.current!.querySelectorAll<HTMLElement>('[data-id]')].filter((x) => x.offsetParent).map((x) => Number(x.dataset.id));
        const k = ids.indexOf(id);
        if (e.key === 'ArrowUp' && k > 0) {
            move(id, k > 1 ? ids[k - 2] : null);
        }
        if (e.key === 'ArrowDown' && k < ids.length - 1) {
            move(id, ids[k + 1]);
        }
        window.setTimeout(() => {
            const n = list.current?.querySelector<HTMLElement>(`[data-id="${id}"]`);
            (n?.matches('a') ? n : n?.querySelector<HTMLElement>('a'))?.focus();
        }, 30);
    }

    function fold(id: number, closed: boolean) {
        const next = { ...folds, [id]: closed };
        setFolds(next);
        localStorage.setItem('eyiAdmFold:' + work.slug, JSON.stringify(next));
    }

    const add = (type: 'chapter' | 'part') => {
        loading(true);
        router.post(`/works/${work.slug}/items`, { type }, { onFinish: () => loading(false) });
    };

    async function release(r: ChapterRow) {
        const { status, data } = await json<{ rows: ChapterRow[] }>('DELETE', `/works/${work.slug}/items/${r.id}`);
        if (status === 200 && data.rows) {
            setRows(data.rows);
            say(`${r.title} is on its own again`);
        }
    }

    function row(r: ChapterRow) {
        const title = r.title ? r.title : <i className="w-phx">{kick(r)}</i>;
        const cls = `wa-row${r.now ? ' now' : ''}${r.ref ? ' ref' : ''}${moved.current === r.id ? ' moved' : ''}`;
        const inner = (
            <>
                <span className="g" data-grip tabIndex={-1} aria-label={`Move ${r.title || unit}`} onPointerDown={(e) => grab(e, r.id)}>
                    <GripIcon />
                </span>
                <span className="no">{r.number}</span>
                <span className="t">
                    {r.ref && !reorder ? (
                        <Link className="w-rl" href={r.href}>
                            {title}
                        </Link>
                    ) : (
                        title
                    )}
                </span>
                {novel ? (
                    <span className="k-fact pv">
                        {r.pov && (
                            <>
                                <PovIcon />
                                {r.pov}
                            </>
                        )}
                    </span>
                ) : r.ref ? (
                    <span className="k-fact pv">
                        <Link15 />
                        Also on its own
                    </span>
                ) : (
                    <span />
                )}
                <span className="p">{r.pages ? `≈ ${r.pages} ${r.pages === 1 ? 'page' : 'pages'}` : 'Empty'}</span>
                <span className="s">
                    {r.ref ? (
                        <>
                            {!reorder && (
                                <span className="w-st">
                                    <StateFact row={r} />
                                </span>
                            )}
                            <button
                                className={`w-act${reorder ? '' : ' w-rm'}`}
                                type="button"
                                onClick={(e) => {
                                    e.preventDefault();
                                    release(r);
                                }}
                            >
                                Remove
                            </button>
                        </>
                    ) : (
                        <StateFact row={r} />
                    )}
                </span>
            </>
        );

        return r.ref ? (
            <div key={r.id} className={cls} data-id={r.id} onKeyDown={(e) => keys(e, r.id)}>
                {inner}
            </div>
        ) : (
            <Link key={r.id} href={r.href} className={cls} data-id={r.id} onKeyDown={(e) => keys(e, r.id)}>
                {inner}
            </Link>
        );
    }

    /* parts gather the chapters after them */
    const blocks: JSX.Element[] = [];
    for (let i = 0; i < rows.length; i++) {
        const r = rows[i];
        if (r.type !== 'part') {
            blocks.push(row(r));
            continue;
        }
        const kids: ChapterRow[] = [];
        let j = i + 1;
        while (j < rows.length && rows[j].type !== 'part') {
            kids.push(rows[j++]);
        }
        const allPub = kids.length > 0 && kids.every((c) => c.state === 'pub');
        const closed = folds[r.id] ?? allPub;
        const meta = kids.length ? `${kids.length} ${kids.length === 1 ? 'chapter' : 'chapters'}${allPub ? ' · all published' : ''}` : 'No chapters yet';
        blocks.push(
            <div key={r.id} className={`wa-pt${closed ? ' fold' : ''}${moved.current === r.id ? ' moved' : ''}`} data-id={r.id} onKeyDown={(e) => keys(e, r.id)}>
                <span className="g" data-grip tabIndex={-1} aria-label={`Move ${(work.partWord || 'part').toLowerCase()}`} onPointerDown={(e) => grab(e, r.id)}>
                    <GripIcon />
                </span>
                <button type="button" className="fb" aria-expanded={!closed} aria-label={`${closed ? 'Show' : 'Hide'} chapters`} onClick={() => fold(r.id, !closed)}>
                    <FoldIcon />
                </button>
                <Link className="pl" href={r.href}>
                    {partHead(r.number) && <span className="n">{partHead(r.number)}</span>}
                    <h3>{r.title ? r.title : <i className="w-phx">Untitled</i>}</h3>
                </Link>
                <span className="k-meta">{meta}</span>
            </div>,
            <div key={`k${r.id}`} className={`w-kids${closed ? '' : ' open'}`}>
                <div>{kids.map(row)}</div>
            </div>,
        );
        i = j - 1;
    }

    return (
        <div id="chap" className={`w-chap${coll ? ' w-coll' : ''}`}>
            <KeepHere slug={work.slug} rows={rows} count={`${chapters.length} ${chapters.length === 1 ? unit : units}`} />
            <div className="wa-ct">
                <span className="k-meta">
                    {chapters.length} {chapters.length === 1 ? unit : units}
                    {novel ? ` · ≈ ${pages.toLocaleString('en-GB')} pages` : ''}
                </span>
                <div className="acts">
                    {novel && !contents.opening && (
                        <Link className="k-l" href={`/works/${work.slug}/opening`}>
                            <Plus />
                            <span>Opening quote</span>
                        </Link>
                    )}
                    <button className="k-l" type="button" onClick={() => add('chapter')}>
                        <Plus />
                        <span>New {unit}</span>
                    </button>
                    {novel && (
                        <button className="k-l" type="button" onClick={() => add('part')}>
                            <Plus />
                            <span>New {(work.partWord || 'part').toLowerCase()}</span>
                        </button>
                    )}
                    {coll && (
                        <button className="w-act" type="button" onClick={() => setPicking(true)}>
                            <Plus />
                            Add a {unit} you’ve written
                        </button>
                    )}
                    <button className="k-l w-act w-reo" type="button" aria-pressed={reorder} onClick={() => setReorder(!reorder)}>
                        <span>{reorder ? 'Done' : 'Reorder'}</span>
                    </button>
                </div>
            </div>
            <div className={`w-list${reorder ? ' reo' : ''}`} id="clist" ref={list}>
                {contents.opening && (
                    <Link href={`/works/${work.slug}/opening`} className="wa-op">
                        <span className="fb" />
                        <span className="n">Opening quote</span>
                        <span className="t">{contents.opening.q.replace(/\n/g, ' ')}</span>
                        <span className="k-meta">{contents.opening.by}</span>
                    </Link>
                )}
                {blocks}
            </div>
            {coll && (
                <PiecePicker
                    open={picking}
                    unit={unit}
                    units={units}
                    pool={contents.pool.filter((p) => !taken.includes(p.id))}
                    onClose={() => setPicking(false)}
                    onAdd={async (ids) => {
                        const { status, data } = await json<{ rows: ChapterRow[] }>('POST', `/works/${work.slug}/items/borrow`, { works: ids });
                        if (status === 200 && data.rows) {
                            setRows(data.rows);
                            setTaken([...taken, ...ids]);
                            setPicking(false);
                            const added = contents.pool.filter((p) => ids.includes(p.id));
                            say(added.length === 1 ? `Added ${added[0].title}` : `Added ${added.length} ${units}`);
                        }
                    }}
                />
            )}
            {createPortal(
                <div className={`w-toast${toast ? ' on' : ''}`} role="status" aria-live="polite">
                    {toast}
                </div>,
                document.body,
            )}
        </div>
    );
}

/** Add pieces already written: a searchable list to tick, then Add N. */
function PiecePicker({
    open,
    unit,
    units,
    pool,
    onClose,
    onAdd,
}: {
    open: boolean;
    unit: string;
    units: string;
    pool: { id: number; title: string; meta: string }[];
    onClose: () => void;
    onAdd: (ids: number[]) => Promise<void>;
}) {
    const [q, setQ] = useState('');
    const [picked, setPicked] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const search = useRef<HTMLInputElement>(null);
    const fold = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const shown = pool.filter((p) => !q.trim() || fold(p.title).includes(fold(q.trim())));

    useLayoutEffect(() => {
        if (open) {
            setQ('');
            setPicked([]);
            window.setTimeout(() => search.current?.focus(), prefersReducedMotion() ? 0 : 80);
        }
    }, [open]);

    return createPortal(
        <>
            <div className={`w-scrim${open ? ' on' : ''}`} onClick={onClose} />
            <div
                className={`w-pan w-pick${open ? ' open' : ''}`}
                role="dialog"
                aria-modal="true"
                aria-label={`Add a ${unit}`}
                tabIndex={-1}
                onKeyDown={(e) => e.key === 'Escape' && onClose()}
                {...inertWhen(!open)}
            >
                <div className="w-ph">
                    <h2>Add a {unit}</h2>
                </div>
                <label className="k-search" htmlFor="pkQ">
                    <Search />
                    <input ref={search} id="pkQ" type="search" placeholder={`Search your ${units}`} autoComplete="off" value={q} onChange={(e) => setQ(e.target.value)} />
                </label>
                <div className="w-pkl" id="pkL">
                    {shown.length ? (
                        shown.map((p) => (
                            <label key={p.id} className="ad-cb w-pkr">
                                <input type="checkbox" checked={picked.includes(p.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, p.id] : picked.filter((x) => x !== p.id))} />
                                <span className="ad-box">
                                    <BoxTick />
                                </span>
                                <span className="t">{p.title}</span>
                                <span className="k-meta">{p.meta}</span>
                            </label>
                        ))
                    ) : (
                        <p className="w-empty">
                            {q.trim() ? (
                                <>
                                    Nothing called <b>{q.trim()}</b>.
                                </>
                            ) : (
                                `Every ${unit} is already here.`
                            )}
                        </p>
                    )}
                </div>
                <div className="w-pf">
                    <button className="w-act" type="button" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className={`k-btn sm${busy ? ' busy' : ''}`}
                        type="button"
                        disabled={!picked.length}
                        aria-busy={busy}
                        onClick={async () => {
                            setBusy(true);
                            await onAdd(picked);
                            setBusy(false);
                        }}
                    >
                        <span>{picked.length ? `Add ${picked.length}` : 'Add'}</span>
                        <Ring />
                    </button>
                </div>
            </div>
        </>,
        document.body,
    );
}

/**
 * Keep on this device: every page of the work stays on this device so writing goes on without a connection.
 * Offline, a band says drafts save here.
 */
function KeepHere({ slug, rows, count }: { slug: string; rows: ChapterRow[]; count: string }) {
    const online = useOnline();
    const version = (usePage() as unknown as { version: string | null }).version ?? '';
    const [on, setOn] = useState(() => isKept(slug));
    const [bytes, setBytes] = useState<number | null>(null);
    const paths = [`/works/${slug}`, `/works/${slug}?tab=chapters`, ...rows.filter((r) => !r.ref).map((r) => r.href)];
    const key = paths.join('|');

    /* kept: refresh the copy whenever the list changes (and we're online); otherwise just say its size */
    useEffect(() => {
        if (!on) {
            setBytes(null);

            return;
        }
        let gone = false;
        (online ? keepWork(slug, paths, version) : keptSize(slug)).then((b) => !gone && setBytes(b));

        return () => {
            gone = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [on, key, online]);

    const meta = on ? `All ${count}${bytes ? ` · ${megabytes(bytes)}` : ''}` : 'Only while online';

    return (
        <>
            <Unfold show={!online}>
                <div className="w-band" role="status">
                    <CloudOff size={18} />
                    <span>Offline. Drafts save on this device.</span>
                </div>
            </Unfold>
            <div className="w-keep">
                <label className="ad-cb">
                    <input
                        type="checkbox"
                        id="keepC"
                        checked={on}
                        onChange={(e) => {
                            const yes = e.target.checked;
                            setOn(yes);
                            if (!yes) {
                                void dropWork(slug);
                            }
                        }}
                    />
                    <span className="ad-box">
                        <BoxTick />
                    </span>
                    Keep on this device
                </label>
                <span className="k-meta w-keepm" key={meta}>
                    {meta}
                </span>
            </div>
        </>
    );
}

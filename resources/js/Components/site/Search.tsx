import { router, usePage } from '@inertiajs/react';
import { KeyboardEvent, MouseEvent, useCallback, useEffect, useRef, useState } from 'react';
import { inertWhen } from '@/lib/dom';
import { json } from '@/lib/http';
import { prefersReducedMotion } from '@/lib/motion';
import { CloseIcon, SearchIcon } from './Icons';
import Wordmark from './Wordmark';

type Hit = { title: string; small: string; href: string };
type Results = { q: string; works: Hit[]; lines: Hit[]; notes: Hit[] };

const GROUPS: { key: 'works' | 'lines' | 'notes'; label: string }[] = [
    { key: 'works', label: 'Library' },
    { key: 'lines', label: 'Inside a work' },
    { key: 'notes', label: 'Notes' },
];

/**
 * Search (the header's magnifier, or "/"): one field over a blurred page; titles under Library, lines from
 * chapters and pieces under Inside a work, then notes. Arrow keys move through them, Enter opens, Esc closes.
 */
export default function Search() {
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [res, setRes] = useState<Results | null>(null);
    const [at, setAt] = useState(-1);
    const input = useRef<HTMLInputElement>(null);
    const lastFocus = useRef<HTMLElement | null>(null);
    const asked = useRef(0);

    const show = useCallback(() => {
        lastFocus.current = document.activeElement as HTMLElement | null;
        setOpen(true);
        window.setTimeout(() => input.current?.select(), prefersReducedMotion() ? 0 : 120);
    }, []);
    const close = useCallback(() => {
        setOpen(false);
        lastFocus.current?.focus({ preventScroll: true });
    }, []);

    useEffect(() => {
        const onAsk = () => show();
        const onKey = (e: globalThis.KeyboardEvent) => {
            const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName));
            if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                show();
            }
        };
        window.addEventListener('eyi:search', onAsk);
        window.addEventListener('keydown', onKey);

        return () => {
            window.removeEventListener('eyi:search', onAsk);
            window.removeEventListener('keydown', onKey);
        };
    }, [show]);

    /* a page change closes it */
    useEffect(() => setOpen(false), [url]);

    /* ask as you type; only the newest answer counts */
    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setRes(null);
            setAt(-1);

            return;
        }
        const n = ++asked.current;
        const t = window.setTimeout(async () => {
            const { status, data } = await json<Results>('GET', `/search?${new URLSearchParams({ q: term.slice(0, 100) })}`);
            if (n === asked.current && status === 200) {
                setRes({ q: data.q ?? term, works: data.works ?? [], lines: data.lines ?? [], notes: data.notes ?? [] });
                setAt(-1);
            }
        }, 160);

        return () => window.clearTimeout(t);
    }, [q]);

    const hits = res ? GROUPS.flatMap((g) => res[g.key]) : [];
    const go = (hit: Hit) => {
        setOpen(false);
        router.visit(hit.href);
    };
    const onKey = (e: KeyboardEvent) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            close();
        } else if (e.key === 'ArrowDown' && hits.length) {
            e.preventDefault();
            setAt((a) => (a + 1) % hits.length);
        } else if (e.key === 'ArrowUp' && hits.length) {
            e.preventDefault();
            setAt((a) => (a <= 0 ? hits.length - 1 : a - 1));
        } else if (e.key === 'Enter' && hits.length) {
            e.preventDefault();
            go(hits[Math.max(0, at)]);
        }
    };
    const onPick = (e: MouseEvent, hit: Hit) => {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        e.preventDefault();
        go(hit);
    };

    useEffect(() => {
        if (at >= 0) {
            document.getElementById(`sr-${at}`)?.scrollIntoView({ block: 'nearest' });
        }
    }, [at]);

    let k = -1;

    return (
        <div
            className={`search${open ? ' open' : ''}`}
            id="search"
            role="dialog"
            aria-modal="true"
            aria-label="Search"
            onKeyDown={onKey}
            onClick={(e) => {
                /* a click on the page around the field and results closes it */
                const t = e.target as HTMLElement;
                if (t === e.currentTarget || t.classList.contains('wrap') || t.classList.contains('bar')) {
                    close();
                }
            }}
            {...inertWhen(!open)}
        >
            <div className="wrap">
                <div className="bar">
                    <Wordmark />
                    <label className="field">
                        <SearchIcon width={20} height={20} />
                        <input
                            ref={input}
                            id="q"
                            type="search"
                            value={q}
                            maxLength={100}
                            placeholder="Search titles and lines"
                            autoComplete="off"
                            spellCheck={false}
                            role="combobox"
                            aria-expanded={!!res}
                            aria-controls="sList"
                            aria-activedescendant={at >= 0 ? `sr-${at}` : undefined}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        <span className="caps esc">Esc</span>
                    </label>
                    <button className="icon" id="sClose" type="button" aria-label="Close search" onClick={close}>
                        <CloseIcon />
                    </button>
                </div>
                <div className={`panel${res ? '' : ' none'}`} id="sList" role="listbox" aria-label="Results">
                    {res && !hits.length && <p className="sempty">Nothing for “{res.q}”.</p>}
                    {res &&
                        GROUPS.map(
                            (g) =>
                                res[g.key].length > 0 && (
                                    <div className="sgroup" key={g.key}>
                                        <div className="caps sl">{g.label}</div>
                                        {res[g.key].map((hit) => {
                                            k += 1;
                                            const i = k;

                                            return (
                                                <a
                                                    key={`${g.key}-${hit.href}`}
                                                    id={`sr-${i}`}
                                                    className={`res${i === at ? ' on' : ''}`}
                                                    href={hit.href}
                                                    role="option"
                                                    aria-selected={i === at}
                                                    tabIndex={-1}
                                                    onMouseMove={() => i !== at && setAt(i)}
                                                    onClick={(e) => onPick(e, hit)}
                                                >
                                                    <b>{hit.title}</b>
                                                    <span>{hit.small}</span>
                                                </a>
                                            );
                                        })}
                                    </div>
                                ),
                        )}
                </div>
            </div>
        </div>
    );
}

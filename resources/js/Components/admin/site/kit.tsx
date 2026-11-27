import { CSSProperties, KeyboardEvent as ReactKeyboardEvent, ReactNode, RefObject, useEffect, useRef } from 'react';
import { flushSync } from 'react-dom';
import { CoverData } from '../Cover';
import { after, EASE } from '../notes/lines';
import { prefersReducedMotion as RM } from '../../../lib/motion';

/* The Site pages' shared pieces (prototype 2g): icons, a row that unfolds into a card, typing areas, drag to reorder. */

/* ------------------------------------------------------------------ data */
export type PageKey = 'about' | 'in_print' | 'privacy' | 'terms';
export type Version = { id: number; at: string; changes: string; matters: boolean };
export type Page<T = Record<string, unknown>> = { draft: T; live: T | null; savedAt: string | null; publishedAt: string | null; versions: Version[] };
export type Work = { slug: string; title: string; kind: string; color: string | null; image: string | null; public: boolean };
export type Money = { amount: number; currency: string } | null;

export type About = {
    image: number | null;
    hello: { heading: string; paragraphs: string[] };
    sections: { id: string; heading: string; paragraphs: { text: string; margin_note: string | null; pull_quote: boolean }[] }[];
    books: string;
    home: { quote: string; line: string };
};
export type Store = { store: string; price: Money; url: string | null; ok: boolean };
export type Book = { slug: string; title: string; kind: string; cover: string; formats: { format: string; stores: Store[] }[] };
export type Coming = { slug: string; title: string; kind: string; cover: string; when: string; out: { d: string; m: string; y: string } | null; mode: 'pre' | 'follow'; store: string; price: Money; url: string | null };
export type Reading = { id: string; title: string; starts_at: string; place: string; online: boolean; url: string | null; cancelled: boolean };
export type InPrint = { books: Book[]; coming: Coming[]; events: Reading[] };
export type DocSection = { id: string; heading: string; html: string };
export type Doc = { updated_on: string; sections: DocSection[]; change: { on: string; line: string; ids: string[] } | null };

export const clone = <T,>(o: T): T => JSON.parse(JSON.stringify(o)) as T;
export const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
export const pad = (n: number | string) => String(n).padStart(2, '0');
export const newId = (p: string) => p + Date.now().toString(36) + Math.floor(Math.random() * 36).toString(36);
export const plural = (n: number, one: string, many = one + 's') => `${n} ${n === 1 ? one : many}`;

/** A work's cover for a row: its picture, else its colour. */
export function coverOf(w: { image?: string | null; color?: string | null; cover?: string }): CoverData {
    return { src: w.image ?? null, color: w.color ?? w.cover ?? '#C46F8A', dark: true };
}
export function Cov({ w, width, ratio = 1.5 }: { w: { image?: string | null; color?: string | null; cover?: string }; width: number; ratio?: number }) {
    const st: CSSProperties = { width, height: Math.round(width * ratio) };
    const c = coverOf(w);

    return c.src ? (
        <span className="k-cov sp-cv" style={st}>
            <img src={c.src} alt="" />
        </span>
    ) : (
        <span className="k-cov sp-cv" style={{ ...st, background: c.color }} />
    );
}

/* ------------------------------------------------------------------ money: she types "8,500", the site keeps kobo */
export const CURS: [string, string][] = [
    ['NGN', '₦'],
    ['GBP', '£'],
    ['USD', '$'],
    ['EUR', '€'],
];
export const SYM = Object.fromEntries(CURS) as Record<string, string>;
export function priceText(p: Money): string {
    if (!p) {
        return '';
    }

    return p.currency === 'NGN' ? Math.round(p.amount / 100).toLocaleString('en-US') : (p.amount / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
export function priceOf(text: string, currency: string): Money {
    const v = parseFloat(text.replace(/,/g, ''));

    return text.trim() === '' || Number.isNaN(v) ? null : { amount: Math.round(v * 100), currency };
}
/** The lowest naira price, else the first store's: "₦8,500". */
export function fromPrice(stores: Store[]): string {
    const priced = stores.filter((s) => s.price && s.price.amount > 0);
    if (!priced.length) {
        return '';
    }
    const ng = priced.filter((s) => s.price!.currency === 'NGN');
    const b = ng.length ? ng.reduce((a, c) => (c.price!.amount < a.price!.amount ? c : a)) : priced[0];

    return SYM[b.price!.currency] + priceText(b.price);
}

/* a web address (or, for links in text, an email or a page here) */
export const isLink = (v: string, inText = false) =>
    /^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?([/?#]\S*)?$/i.test(v) || (inText && (/^(mailto:)?[^\s@/]+@[^\s@]+\.[a-z]{2,}$/i.test(v) || /^\/[\w\-/#]*$/.test(v)));
export const fullUrl = (v: string) => (v.trim() === '' ? null : /^https?:\/\//i.test(v.trim()) ? v.trim() : 'https://' + v.trim());
export const bareUrl = (v: string | null) => (v ?? '').replace(/^https?:\/\//i, '');

/* ------------------------------------------------------------------ icons (the prototype's set) */
const stroke = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
const ic = (size: number, width: number, d: ReactNode, cls?: string) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...stroke} strokeWidth={width} aria-hidden="true" className={cls}>
        {d}
    </svg>
);
const D = {
    chev: <path d="m6 9 6 6 6-6" />,
    x: <path d="M6 6l12 12M18 6 6 18" />,
    tick: <path d="m5 12.5 4.5 4.5L19 7" />,
    pen: (
        <>
            <path d="M4 20h4L19 9l-4-4L4 16z" />
            <path d="m13.5 6.5 4 4" />
        </>
    ),
    hist: (
        <>
            <path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1" />
            <path d="M3.5 4.5v4h4" />
            <path d="M12 8v4.5l3 2" />
        </>
    ),
    grip: (
        <>
            <circle cx="9" cy="6.5" r="1.1" />
            <circle cx="15" cy="6.5" r="1.1" />
            <circle cx="9" cy="12" r="1.1" />
            <circle cx="15" cy="12" r="1.1" />
            <circle cx="9" cy="17.5" r="1.1" />
            <circle cx="15" cy="17.5" r="1.1" />
        </>
    ),
    book: (
        <>
            <path d="M5 4.5h11a3 3 0 0 1 3 3v12H8a3 3 0 0 1-3-3z" />
            <path d="M5 16.5a3 3 0 0 1 3-3h11" />
        </>
    ),
    desk: (
        <>
            <path d="M3 9.5h18" />
            <path d="M5 9.5V20M19 9.5V20" />
            <path d="M7 9.5V5.5h10v4" />
        </>
    ),
    note: (
        <>
            <path d="M6 3h9l4 4v14H6z" />
            <path d="M14 3v5h5" />
        </>
    ),
    quote: <path d="M5 17c0-4 1-7 5-9M14 17c0-4 1-7 5-9" />,
    plus: <path d="M12 5v14M5 12h14" />,
    up: <path d="M12 16V5m-5 5 5-5 5 5M5 20h14" />,
    crop: (
        <>
            <path d="M7 3v14h14" />
            <path d="M3 7h14v14" />
        </>
    ),
    undo: (
        <>
            <path d="M9 7 4 12l5 5" />
            <path d="M4 12h11a5 5 0 0 1 0 10h-2" />
        </>
    ),
    minus: <path d="M6 12h12" />,
    broken: (
        <>
            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-2.4-6.8" />
            <path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 2.4 6.8" />
            <path d="M4 4l3 3M20 20l-3-3" />
        </>
    ),
    clock: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>
    ),
    globe: (
        <>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17M12 3.5c2.4 2.5 3.4 5.3 3.4 8.5s-1 6-3.4 8.5c-2.4-2.5-3.4-5.3-3.4-8.5s1-6 3.4-8.5z" />
        </>
    ),
    ext: <path d="M8 16 16 8M9.5 8H16v6.5" />,
    cal: (
        <>
            <rect x="3.5" y="5" width="17" height="15" rx="2" />
            <path d="M3.5 10h17M8 3v4M16 3v4" />
        </>
    ),
    search: (
        <>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </>
    ),
    pin: (
        <>
            <path d="M9 4h6l-1 5 3.5 3.5h-11L10 9z" />
            <path d="M12 12.5V20" />
        </>
    ),
    sun: (
        <>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
        </>
    ),
    img: (
        <>
            <rect x="3.5" y="4.5" width="17" height="15" rx="2" />
            <circle cx="9" cy="10" r="1.8" />
            <path d="m20.5 16-5-5L6 19.5" />
        </>
    ),
    link: (
        <>
            <path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1" />
            <path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1" />
        </>
    ),
};
export const I = {
    chev16: ic(16, 1.8, D.chev),
    chev18: ic(18, 1.8, D.chev),
    x14: ic(14, 1.8, D.x),
    x15: ic(15, 1.8, D.x),
    x16: ic(16, 1.8, D.x),
    x18: ic(18, 1.8, D.x),
    tick13: (
        <svg width="13" height="13" viewBox="0 0 24 24" {...stroke} strokeWidth="2.6" aria-hidden="true">
            <path pathLength={1} d="m5 12.5 4.5 4.5L19 7" />
        </svg>
    ),
    tick15: ic(15, 2.4, D.tick),
    pen14: ic(14, 1.8, D.pen),
    pen15: ic(15, 1.8, D.pen),
    pen17: ic(17, 1.8, D.pen),
    hist14: ic(14, 1.8, D.hist),
    hist15: ic(15, 1.8, D.hist),
    hist17: ic(17, 1.8, D.hist),
    grip15: ic(15, 1.8, D.grip),
    book15: ic(15, 1.8, D.book),
    book17: ic(17, 1.8, D.book),
    desk17: ic(17, 1.8, D.desk),
    note17: ic(17, 1.8, D.note),
    quote15: ic(15, 1.8, D.quote),
    plus15: ic(15, 2, D.plus),
    plus16: ic(16, 2, D.plus),
    up16: ic(16, 1.8, D.up),
    crop16: ic(16, 1.8, D.crop),
    undo15: ic(15, 1.8, D.undo),
    undo16: ic(16, 1.8, D.undo),
    minus15: ic(15, 1.8, D.minus),
    broken15: ic(15, 1.8, D.broken),
    broken16: ic(16, 1.8, D.broken),
    clock14: ic(14, 1.8, D.clock),
    clock15: ic(15, 1.8, D.clock),
    globe14: ic(14, 1.8, D.globe),
    ext14: ic(14, 2, D.ext),
    cal15: ic(15, 1.8, D.cal),
    search: ic(18, 1.8, D.search),
    pin16: ic(16, 1.8, D.pin),
    sun15: ic(15, 1.8, D.sun),
    sun16: ic(16, 1.8, D.sun),
    img15: ic(15, 1.8, D.img),
    link15: ic(15, 1.8, D.link),
    dot: (
        <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="4" fill="currentColor" />
        </svg>
    ),
    ltick: (
        <svg width="14" height="14" viewBox="0 0 24 24" {...stroke} strokeWidth="3" aria-hidden="true">
            <path pathLength={1} d="m5 12.5 4.5 4.5L19 7" />
        </svg>
    ),
};

/* ------------------------------------------------------------------ a row that unfolds into a card */
export function Row({
    k,
    icon,
    title,
    sub,
    meta,
    flag,
    edited,
    grip,
    cls = '',
    onOpen,
    onGrip,
    onGripKey,
}: {
    k: string;
    icon: ReactNode;
    title: string;
    sub: ReactNode;
    meta?: ReactNode;
    flag?: ReactNode;
    edited: boolean;
    grip?: string;
    cls?: string;
    onOpen: () => void;
    onGrip?: (e: React.PointerEvent) => void;
    onGripKey?: (e: ReactKeyboardEvent) => void;
}) {
    return (
        <div className="sp-rw" data-k={k} data-drag={grip ? '' : undefined}>
            {grip ? (
                <button className="sp-grip" type="button" aria-label={`Move ${grip}. Use the up and down arrows`} data-grip onPointerDown={onGrip} onKeyDown={onGripKey}>
                    {I.grip15}
                </button>
            ) : (
                <span className="sp-grip" />
            )}
            <button className={`sp-row${cls ? ' ' + cls : ''}`} type="button" data-part={k} aria-expanded="false" onClick={onOpen}>
                <span className="ic">{icon}</span>
                <span className="tx">
                    <b>{title || <i className="sp-untitled">Untitled</i>}</b>
                    <span>{sub}</span>
                </span>
                {flag !== undefined && <span className="sp-flag">{flag}</span>}
                <span className="mk" data-on={edited}>
                    {edited && <span title="Edited, not out yet">{I.pen14}</span>}
                </span>
                <span className="mt">{meta}</span>
                {I.chev18}
            </button>
        </div>
    );
}

export type SaveMark = 'idle' | 'saved' | 'device' | 'bad';

/** A row's open card: its name, Saved, Close; the fields; then its actions. */
export function Card({ k, head, saved, onClose, children, foot, cls = '' }: { k: string; head: ReactNode; saved: SaveMark; onClose: () => void; children: ReactNode; foot?: ReactNode; cls?: string }) {
    return (
        <div className={`nl-o sp-o${cls ? ' ' + cls : ''}`} role="group" aria-label={typeof head === 'string' ? head : undefined} data-k={k} id="spO">
            <div className="nl-oh">
                {head && <span className="sp-oh">{head}</span>}
                <SavedMark state={saved} cls="sp-svd" />
                <button className="ad-ic nt-x" type="button" aria-label="Close" onClick={onClose}>
                    {I.x18}
                </button>
            </div>
            {children}
            {foot && <div className="nl-ob">{foot}</div>}
        </div>
    );
}

export function SavedMark({ state, cls = '' }: { state: SaveMark; cls?: string }) {
    if (state === 'idle') {
        return <span className={`nt-sv ${cls}`} hidden />;
    }

    return (
        <span className={`nt-sv ${cls}${state === 'bad' ? ' bad' : ''}`} key={state}>
            {state === 'device' ? I.pen15 : state === 'bad' ? I.x15 : I.tick15}
            <span>{state === 'device' ? 'On this device' : state === 'bad' ? 'Not saved' : 'Saved'}</span>
        </span>
    );
}

/* ------------------------------------------------------------------ typing areas: Archivo, placeholder on the caret's line */
const KEEP = ['P', 'A', 'BR', 'B', 'I', 'EM', 'STRONG'];
/** Her formatted text down to paragraphs, emphasis and links (the server cleans it again). */
export function cleanHtml(h: string): string {
    const d = document.createElement('div');
    d.innerHTML = h;
    [...d.querySelectorAll('*')].forEach((e) => {
        if (!KEEP.includes(e.tagName)) {
            e.replaceWith(...e.childNodes);
        } else {
            [...e.attributes].forEach((a) => {
                if (!(e.tagName === 'A' && a.name === 'href')) {
                    e.removeAttribute(a.name);
                }
            });
            if (e.tagName === 'A') {
                e.className = 'link';
            }
        }
    });
    let out = d.innerHTML.replace(/<div>/g, '<p>').replace(/<\/div>/g, '</p>');
    if (!/^<p>/.test(out.trim())) {
        out = '<p>' + out.replace(/<br>\s*<br>/g, '</p><p>') + '</p>';
    }

    return out.replace(/<p>\s*(<br>)?\s*<\/p>/g, '');
}

export function Area({
    id,
    value,
    placeholder,
    label,
    cls = '',
    rich = false,
    onText,
    onKeyDown,
    onFocus,
    onBlur,
    boxRef,
}: {
    id?: string;
    value: string;
    placeholder: string;
    label?: string;
    cls?: string;
    rich?: boolean;
    onText: (v: string) => void;
    onKeyDown?: (e: ReactKeyboardEvent<HTMLDivElement>) => void;
    onFocus?: () => void;
    onBlur?: (e: React.FocusEvent<HTMLDivElement>) => void;
    boxRef?: RefObject<HTMLDivElement>;
}) {
    const own = useRef<HTMLDivElement>(null);
    const box = boxRef ?? own;
    const ph = useRef<HTMLSpanElement>(null);
    const showPh = () => ph.current?.classList.toggle('on', !box.current?.textContent?.trim());

    useEffect(() => {
        if (box.current) {
            if (rich) {
                box.current.innerHTML = value;
            } else {
                box.current.innerText = value;
            }
            showPh();
        }
        /* only the first value: after that the box is hers */
    }, []);

    return (
        <div className={`sp-tw ${cls}`}>
            <div
                className="sp-ta"
                id={id}
                ref={box as RefObject<HTMLDivElement>}
                contentEditable
                suppressContentEditableWarning
                role="textbox"
                aria-multiline="true"
                spellCheck
                aria-label={label}
                onInput={(e) => {
                    showPh();
                    const el = e.currentTarget;
                    onText(rich ? cleanHtml(el.innerHTML) : el.innerText.replace(/\n+/g, ' ').trim());
                }}
                onPaste={(e) => {
                    e.preventDefault();
                    document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
                }}
                onKeyDown={(e) => {
                    if (!rich && e.key === 'Enter') {
                        e.preventDefault();
                    }
                    if (!rich && (e.ctrlKey || e.metaKey) && /^[bi]$/i.test(e.key)) {
                        e.preventDefault();
                    }
                    onKeyDown?.(e);
                }}
                onFocus={onFocus}
                onBlur={onBlur}
            />
            <span className="sp-ph" aria-hidden="true" ref={ph}>
                {placeholder}
            </span>
        </div>
    );
}

/** A labelled one-line field. */
export function Field({ id, label, value, placeholder, onChange, type = 'text', bad, inputMode, after: tail, big }: { id: string; label: string; value: string; placeholder?: string; onChange: (v: string) => void; type?: string; bad?: boolean; inputMode?: 'url' | 'email' | 'decimal'; after?: ReactNode; big?: boolean }) {
    return (
        <div className="k-f sp-f">
            <label className="k-fl" htmlFor={id}>
                {label}
            </label>
            <span className={`k-in sp-in${big ? ' big' : ''}${bad ? ' bad' : ''}`}>
                <input id={id} value={value} placeholder={placeholder} autoComplete="off" spellCheck={type === 'text'} type={type} inputMode={inputMode} onChange={(e) => onChange(e.target.value)} />
            </span>
            {tail}
        </div>
    );
}

/* ------------------------------------------------------------------ drag to reorder: the item lifts, the others slide apart, a dashed slot shows where it lands */
export function dragSort(e: React.PointerEvent, r: HTMLElement, peers: () => HTMLElement[], onDrop: (from: number, to: number) => void) {
    const pl = r.parentElement!;
    if (getComputedStyle(pl).position === 'static') {
        pl.style.position = 'relative';
    }
    const from = peers().indexOf(r);
    const top0 = r.offsetTop;
    const h = r.offsetHeight;
    const y0 = e.clientY;
    const slot = document.createElement('div');
    slot.className = 'sp-slot';
    slot.style.height = h + 'px';
    r.before(slot);
    r.classList.add('drag');
    const sr = slot.getBoundingClientRect();
    const pr = pl.getBoundingClientRect();
    Object.assign(r.style, { position: 'absolute', top: top0 + 'px', left: sr.left - pr.left + 'px', width: sr.width + 'px', margin: '0', boxSizing: 'border-box', zIndex: '3' });
    const flipMove = (fn: () => void) => {
        const os = peers().filter((x) => x !== r);
        const t0 = new Map(os.map((x) => [x, x.offsetTop]));
        fn();
        if (RM()) {
            return;
        }
        os.forEach((x) => {
            const d = t0.get(x)! - x.offsetTop;
            if (d) {
                x.animate([{ transform: `translateY(${d}px)` }, { transform: 'none' }], { duration: 220, easing: EASE });
            }
        });
    };
    const g = (e.target as Element).closest('[data-grip],[data-pgrip]') as HTMLElement;
    try {
        g.setPointerCapture(e.pointerId);
    } catch {
        /* the pointer may already be gone */
    }
    const mv = (ev: PointerEvent) => {
        const dy = ev.clientY - y0;
        r.style.top = top0 + dy + 'px';
        const mid = top0 + dy + h / 2;
        const os = peers().filter((x) => x !== r);
        const tgt = os.find((x) => mid < x.offsetTop + x.offsetHeight / 2);
        if (tgt) {
            if (slot.nextElementSibling !== tgt) {
                flipMove(() => tgt.before(slot));
            }
        } else {
            const last = os[os.length - 1];
            if (last && last.nextElementSibling !== slot) {
                flipMove(() => last.after(slot));
            }
        }
    };
    const up = () => {
        g.removeEventListener('pointermove', mv);
        g.removeEventListener('pointerup', up);
        g.removeEventListener('pointercancel', up);
        const to = [...pl.children].filter((x) => x === slot || (x !== r && peers().includes(x as HTMLElement))).indexOf(slot);
        const fin = () => {
            /* put the row back where React left it; the new order comes from the state */
            slot.replaceWith(r);
            const list = peers();
            if (list[from] !== r) {
                const ref = list.filter((x) => x !== r)[from];
                if (ref) {
                    ref.before(r);
                } else {
                    pl.append(r);
                }
            }
            r.classList.remove('drag');
            Object.assign(r.style, { position: '', top: '', left: '', width: '', margin: '', transform: '', boxSizing: '', zIndex: '' });
            /* the new order lands in the same frame, so nothing jumps */
            flushSync(() => onDrop(from, to));
        };
        if (RM()) {
            fin();

            return;
        }
        after(r.animate([{ top: r.style.top }, { top: slot.offsetTop + 'px' }], { duration: 200, easing: EASE, fill: 'forwards' }), 200, fin);
    };
    g.addEventListener('pointermove', mv);
    g.addEventListener('pointerup', up);
    g.addEventListener('pointercancel', up);
}

/** Note where each child sits, then after the change slide them from there (FLIP). `map` says which old key each new key was. */
export function flipFrom(box: HTMLElement | null, attr: string): (map?: (k: string) => string) => void {
    const tops = new Map<string, number>();
    [...(box?.children ?? [])].forEach((e) => {
        const k = (e as HTMLElement).dataset[attr];
        if (k != null) {
            tops.set(k, e.getBoundingClientRect().top);
        }
    });

    return (map) => {
        if (RM() || !box) {
            return;
        }
        [...box.children].forEach((e) => {
            const k = (e as HTMLElement).dataset[attr];
            if (k == null) {
                return;
            }
            const t = tops.get(map ? map(k) : k);
            if (t == null) {
                return;
            }
            const dy = t - e.getBoundingClientRect().top;
            if (dy) {
                e.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 320, easing: EASE });
            }
        });
    };
}

/** Reorder a list (a copy). */
export function moved<T>(list: T[], from: number, to: number): T[] {
    const l = list.slice();
    const [x] = l.splice(from, 1);
    l.splice(Math.max(0, Math.min(l.length, to)), 0, x);

    return l;
}

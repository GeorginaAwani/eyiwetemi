import { CSSProperties, ReactNode, RefObject, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Ring } from '../../Components/admin/Icons';
import { dDay, grow, inA, phone, settle, shrink, turn } from '../../Components/admin/notes/lines';
import AboutTab from '../../Components/admin/site/About';
import DocTab, { editedIds } from '../../Components/admin/site/Docs';
import InPrintTab, { withOut } from '../../Components/admin/site/InPrint';
import { clone, Doc, I, InPrint, Page, PageKey, same, SaveMark, Work } from '../../Components/admin/site/kit';
import ContactTab, { ContactData } from '../../Components/admin/site/Contact';
import HomeTab, { HomeData } from '../../Components/admin/site/Home';
import NoticeTab, { NoticeData } from '../../Components/admin/site/Notice';
import SharingTab, { SharingData } from '../../Components/admin/site/Sharing';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePage } from '@inertiajs/react';
import { json } from '../../lib/http';
import { prefersReducedMotion as RM } from '../../lib/motion';

type Props = {
    pages: Record<PageKey, Page>;
    works: Work[];
    links: Record<string, { ok: boolean; checkedOn: string }>;
    portrait: { draft: string | null; live: string | null };
    home: HomeData;
    notice: NoticeData;
    contact: ContactData;
    networks: string[];
    sharing: SharingData;
};
type Tab = 'about' | 'print' | 'privacy' | 'terms' | 'home' | 'notice' | 'share' | 'contact';
const TABS: [Tab, string][] = [
    ['about', 'About'],
    ['print', 'In print'],
    ['privacy', 'Privacy'],
    ['terms', 'Terms'],
    ['home', 'Home'],
    ['notice', 'Notice'],
    ['share', 'Sharing'],
    ['contact', 'Contact'],
];
const KEY: Partial<Record<Tab, PageKey>> = {
    about: 'about',
    print: 'in_print',
    privacy: 'privacy',
    terms: 'terms',
};
const DOC: Partial<Record<Tab, true>> = { privacy: true, terms: true };
const BACKUP = (k: PageKey) => 'eyiAdmSiteDraft:' + k;

/** A popover: what it shows and which button opened it. One at a time; on the phone, a sheet from the bottom. */
export type PopSpec = {
    id: string;
    btn: HTMLElement;
    side: 'left' | 'right' | 'above';
    render: (cls: string, style: CSSProperties, ref: RefObject<HTMLDivElement>) => ReactNode;
};

/** What every tab gets from the page. */
export type SiteCtx = {
    works: Work[];
    links: Props['links'];
    setLink: (url: string, v: { ok: boolean; checkedOn: string }) => void;
    portrait: Props['portrait'];
    setPortrait: (src: string | null) => void;
    page: <T>(k: PageKey) => Page<T>;
    update: <T>(k: PageKey, fn: (d: T) => void) => void;
    saved: SaveMark;
    open: string;
    openCard: (key: string) => void;
    closeCard: (back?: boolean) => void;
    setOpen: (key: string) => void;
    pop: PopSpec | null;
    popShow: (p: PopSpec) => void;
    popClose: (back?: boolean) => void;
    confirm: (btn: HTMLElement, title: string, line: string, yes: string, fn: () => void) => void;
    after: (fn: () => void) => void;
    /** the pane fades out, changes, and settles back in */
    swap: (fn: () => void) => void;
    sub: 'b' | 'r';
    setSub: (s: 'b' | 'r') => void;
    matters: boolean;
    setMatters: (on: boolean) => void;
    line: string;
    setLine: (v: string) => void;
};

/**
 * The site's own pages: About, In print, Privacy and Terms. Each is a draft she edits (saved as she types) and
 * publishes when it's ready; earlier versions can come back as the draft.
 */
export default function Site(props: Props) {
    const q = new URLSearchParams(window.location.search);
    const [tab, setTab] = useState<Tab>(() => (TABS.some(([t]) => t === q.get('tab')) ? (q.get('tab') as Tab) : 'about'));
    const [sub, setSubState] = useState<'b' | 'r'>(q.get('sub') === 'readings' ? 'r' : 'b');
    const [pages, setPages] = useState<Record<PageKey, Page>>(() => {
        const p = clone(props.pages);
        /* a draft kept on this device when the connection dropped wins over the server's */
        (Object.keys(p) as PageKey[]).forEach((k) => {
            try {
                const b = window.localStorage.getItem(BACKUP(k));
                if (b) {
                    p[k].draft = JSON.parse(b);
                }
            } catch {
                /* nothing kept */
            }
        });

        withOut(p.in_print.draft as InPrint);
        withOut(p.in_print.live as InPrint | null);

        return p;
    });
    const [links, setLinks] = useState(props.links);
    const [portrait, setPortraitState] = useState(props.portrait);
    const [saved, setSaved] = useState<SaveMark>('idle');
    const [open, setOpen] = useState(q.get('open') ?? '');
    const [out, setOut] = useState(false);
    const [pop, setPop] = useState<PopSpec | null>(null);
    const [popOn, setPopOn] = useState(false);
    const [popAt, setPopAt] = useState<CSSProperties>({});
    const [sheet, setSheet] = useState(false);
    const [done, setDone] = useState<{ k: PageKey } | null>(null);
    const [busy, setBusy] = useState(false);
    const [matters, setMattersState] = useState(false);
    const [line, setLine] = useState('');
    const [home, setHome] = useState(props.home);
    const [notice, setNotice] = useState(props.notice);
    const [contact, setContact] = useState(props.contact);
    const [sharing, setSharing] = useState(props.sharing);
    const siteUrl = (usePage().props as { siteUrl?: string }).siteUrl ?? 'https://eyiwetemi.com';
    const [toast, setToast] = useState<{
        msg: string;
        href?: string;
        label?: string;
        on: boolean;
    } | null>(null);
    const ref = useRef(pages);
    const popRef = useRef<PopSpec | null>(null);
    const main = useRef<HTMLDivElement>(null);
    const pane = useRef<HTMLDivElement>(null);
    const popBox = useRef<HTMLDivElement>(null);
    const fx = useRef<(() => void)[]>([]);
    const timers = useRef<Partial<Record<PageKey, number>>>({});
    const doneT = useRef(0);
    const toastT = useRef(0);
    ref.current = pages;

    useLayoutEffect(() => {
        const fs = fx.current;
        fx.current = [];
        fs.forEach((f) => f());
    });
    const after = (fn: () => void) => fx.current.push(fn);

    /* a page opened on a later tab shows that tab in the row (phones scroll the row sideways) */
    useEffect(() => {
        document.querySelector('#spT a.on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, []);

    /* drafts kept on this device go up again when the connection is back */
    useEffect(() => {
        const back = () => (Object.keys(ref.current) as PageKey[]).forEach((k) => window.localStorage.getItem(BACKUP(k)) && void save(k));
        back();
        window.addEventListener('online', back);

        return () => window.removeEventListener('online', back);
    }, []);

    /* ---------- saving: every change, 600 ms after she stops ---------- */
    async function save(k: PageKey) {
        const { status } = await json('PATCH', `/site/pages/${k}`, {
            draft: ref.current[k].draft as Record<string, unknown>,
        });
        if (status === 200) {
            window.localStorage.removeItem(BACKUP(k));
            setSaved('saved');
        } else if (status === 0) {
            window.localStorage.setItem(BACKUP(k), JSON.stringify(ref.current[k].draft));
            setSaved('device');
        } else {
            setSaved('bad');
        }
    }
    function update<T>(k: PageKey, fn: (d: T) => void) {
        const p = clone(ref.current[k]);
        fn(p.draft as T);
        const next = { ...ref.current, [k]: p };
        ref.current = next;
        setPages(next);
        window.clearTimeout(timers.current[k]);
        timers.current[k] = window.setTimeout(() => void save(k), 600);
    }
    const setPage = (k: PageKey, p: Page) => {
        ref.current = { ...ref.current, [k]: p };
        setPages(ref.current);
    };

    /* ---------- tabs ---------- */
    function url(t: Tab, s: 'b' | 'r') {
        const u = new URLSearchParams();
        if (t !== 'about') {
            u.set('tab', t);
        }
        if (t === 'print' && s === 'r') {
            u.set('sub', 'readings');
        }
        const qs = u.toString();
        window.history.replaceState(window.history.state, '', '/site' + (qs ? '?' + qs : ''));
    }
    function goTab(t: Tab, a: HTMLElement) {
        if (t === tab) {
            return;
        }
        popClose();
        url(t, sub);
        a.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: RM() ? 'auto' : 'smooth',
        });
        setMattersState(false);
        setLine('');
        after(() => inA(document.getElementById('spPB')));
        if (RM()) {
            setTab(t);
            setOpen('');

            return;
        }
        setOut(true);
        window.setTimeout(() => {
            setTab(t);
            setOpen('');
            setOut(false);
            after(() => settle(pane.current));
        }, 160);
    }
    function setSub(s: 'b' | 'r') {
        url(tab, s);
        setOpen('');
        setSubState(s);
    }

    /* ---------- rows that unfold into a card (one at a time) ---------- */
    function openCard(key: string) {
        const r = document.querySelector<HTMLElement>(`.sp-rw[data-k="${CSS.escape(key)}"]`);
        const from = r?.offsetHeight ?? 68;
        popClose();
        setOpen(key);
        setSaved('idle');
        after(() => {
            const c = document.getElementById('spO');
            if (c) {
                grow(c, from);
                window.setTimeout(
                    () => {
                        const f = c.querySelector<HTMLElement>('input,[contenteditable="true"]');
                        if (f && !phone()) {
                            f.focus({ preventScroll: true });
                        }
                    },
                    RM() ? 0 : 200,
                );
            }
        });
    }
    function closeCard(back = false) {
        const c = document.getElementById('spO');
        popClose();
        if (!c) {
            setOpen('');

            return;
        }
        const key = c.dataset.k ?? '';
        shrink(c, 68, () => {
            setOpen('');
            after(() => {
                const r = document.querySelector<HTMLElement>(`.sp-rw[data-k="${CSS.escape(key)}"]`);
                inA(r);
                if (back) {
                    r?.querySelector<HTMLElement>('.sp-row')?.focus();
                }
            });
        });
    }

    /* ---------- popovers ---------- */
    function popShow(p: PopSpec) {
        const pop = popRef.current;
        if (pop && pop.btn === p.btn) {
            popClose(true);

            return;
        }
        pop?.btn.setAttribute('aria-expanded', 'false');
        pop?.btn.classList.remove('on');
        popRef.current = p;
        setPop(p);
        setPopOn(false);
        setSheet(phone());
        p.btn.setAttribute('aria-expanded', 'true');
        p.btn.classList.add('on');
    }
    function popClose(back = false) {
        /* the popover's own buttons were made with the page as it was when it opened: read the one open now */
        const pop = popRef.current;
        if (!pop) {
            return;
        }
        popRef.current = null;
        const b = pop.btn;
        b.setAttribute('aria-expanded', 'false');
        b.classList.remove('on');
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
            let x = pop.side === 'left' ? r.left - mr.left : r.right - mr.left - w;
            x = Math.max(0, Math.min(x, mr.width - w));
            let y = r.bottom - mr.top + 10;
            if (pop.side === 'above' || (r.bottom + h + 20 > window.innerHeight && r.top - h - 20 > 0)) {
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
            if (e.key !== 'Escape' || document.getElementById('spCr')) {
                return;
            }
            if (pop && !(e.target as Element).closest?.('.k-select.open')) {
                e.preventDefault();
                popClose(true);

                return;
            }
            const c = document.getElementById('spO');
            if (c && c.contains(document.activeElement) && !(e.target as Element).closest?.('.k-select.open')) {
                closeCard(true);
            }
        };
        document.addEventListener('keydown', key);

        return () => document.removeEventListener('keydown', key);
    });
    function confirm(btn: HTMLElement, title: string, text: string, yes: string, fn: () => void) {
        popShow({
            id: 'confirm',
            btn,
            side: 'above',
            render: (cls, style, box) => (
                <div className={`${cls} sm`} role="alertdialog" aria-label={title} ref={box} style={style}>
                    <h3>{title}</h3>
                    {text && <p className="pp">{text}</p>}
                    <div className="acts">
                        <button
                            className="k-btn sm line nt-dng"
                            type="button"
                            onClick={() => {
                                popClose();
                                fn();
                            }}
                        >
                            <span>{yes}</span>
                        </button>
                        <button className="w-act" type="button" autoFocus onClick={() => popClose(true)}>
                            Cancel
                        </button>
                    </div>
                </div>
            ),
        });
    }

    /* ---------- the header: status, This change matters, Versions, Publish ---------- */
    const k = KEY[tab] ?? 'about';
    const isPage = !!KEY[tab];
    const cur = pages[k];
    const dirty = isPage && !same(cur.draft, cur.live);
    const none = !!DOC[tab] && !(cur.draft as Doc).sections?.length;
    const stRef = useRef<HTMLSpanElement>(null);
    const [stShown, setStShown] = useState<'ed' | 'live'>(dirty ? 'ed' : 'live');
    useEffect(() => {
        const want = dirty ? 'ed' : 'live';
        if (want !== stShown) {
            turn(stRef.current, () => setStShown(want));
        }
    }, [dirty]);
    useEffect(() => setStShown(dirty ? 'ed' : 'live'), [tab]);

    function swap(fn: () => void) {
        const p = pane.current;
        if (RM() || !p) {
            fn();

            return;
        }
        setOut(true);
        window.setTimeout(() => {
            fn();
            setOut(false);
            after(() => settle(p));
        }, 160);
    }

    function setMatters(on: boolean) {
        setMattersState(on);
        if (on && !line) {
            const d = cur.draft as Doc;
            const hs = editedIds(d, cur.live as Doc | null).map((id) => d.sections.find((s) => s.id === id)?.heading.trim() || 'a new section');
            if (hs.length) {
                setLine('Changes to ' + (hs.length === 1 ? hs[0] : hs.slice(0, -1).join(', ') + ' and ' + hs[hs.length - 1]) + '.');
            }
        }
    }

    async function publish(btn: HTMLButtonElement) {
        if (!dirty || none || busy) {
            return;
        }
        popClose();
        window.clearTimeout(timers.current[k]);
        setBusy(true);
        btn.classList.add('busy');
        await save(k);
        const mt = !!DOC[tab] && matters;
        const { status, data } = await json<{
            page: Page;
            noteId: number | null;
        }>('POST', `/site/pages/${k}/publish`, mt ? { matters: true, line: line.trim() } : {});
        btn.classList.remove('busy');
        setBusy(false);
        if (status !== 200 || !data.page) {
            showToast(status === 0 ? 'No connection. Your edits are kept here.' : 'That didn’t publish. Try again.');

            return;
        }
        setPage(k, data.page);
        setMattersState(false);
        setLine('');
        setDone({ k });
        after(() => inA(document.getElementById('spPB')));
        window.clearTimeout(doneT.current);
        doneT.current = window.setTimeout(() => setDone(null), 8000);
        if (data.noteId) {
            showToast('Published. Readers with accounts will hear about it, and a note is waiting.', `/notes?n=${data.noteId}`, 'Open the note');
        }
    }
    async function undo() {
        if (!done) {
            return;
        }
        const { status, data } = await json<{ page: Page }>('POST', `/site/pages/${done.k}/undo`);
        window.clearTimeout(doneT.current);
        setDone(null);
        if (status === 200 && data.page) {
            setPage(done.k, data.page);
            hideToast();
        }
        after(() => inA(document.getElementById('spPB')));
    }
    function showToast(msg: string, href?: string, label?: string) {
        window.clearTimeout(toastT.current);
        setToast({ msg, href, label, on: false });
        requestAnimationFrame(() => requestAnimationFrame(() => setToast((t) => (t ? { ...t, on: true } : t))));
        toastT.current = window.setTimeout(hideToast, 9000);
    }
    function hideToast() {
        setToast((t) => (t ? { ...t, on: false } : t));
        window.setTimeout(() => setToast((t) => (t && !t.on ? null : t)), RM() ? 0 : 300);
    }

    function versions(btn: HTMLElement) {
        popShow({
            id: 'vers',
            btn,
            side: 'right',
            render: (cls, style, box) => (
                <div className={`${cls} sp-vp`} role="dialog" aria-label="Versions" ref={box} style={style}>
                    <button className="ad-ic x" type="button" aria-label="Close" onClick={() => popClose(true)}>
                        {I.x16}
                    </button>
                    <h3>Versions</h3>
                    <div className="sp-vrs">
                        {ref.current[k].versions.map((v, i) => (
                            <div className="sp-vr" key={v.id}>
                                <span className="d">
                                    {i === 0 ? 'Now' : dDay(v.at.slice(0, 10))}
                                    {i === 0 && <em>Live</em>}
                                </span>
                                <span className="c">
                                    {v.matters ? (
                                        <span className="sp-vm">
                                            <i aria-hidden="true" />
                                            {v.changes}
                                        </span>
                                    ) : (
                                        v.changes
                                    )}
                                </span>
                                {i > 0 && (
                                    <button className="w-act" type="button" onClick={() => void restore(v.id)}>
                                        {I.hist15}
                                        <span>Restore as draft</span>
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            ),
        });
    }
    async function restore(id: number) {
        const { status, data } = await json<{ page: Page }>('POST', `/site/pages/${k}/versions/${id}/restore`);
        if (status !== 200 || !data.page) {
            return;
        }
        popClose();
        const p = pane.current;
        const go = () => {
            setPage(k, data.page!);
            setOpen('');
            window.localStorage.removeItem(BACKUP(k));
            after(() => settle(p));
        };
        if (RM() || !p) {
            go();

            return;
        }
        setOut(true);
        window.setTimeout(() => {
            go();
            setOut(false);
        }, 160);
    }

    const stEl = (
        <span className={stShown === 'ed' ? 'nt-st ed' : 'nt-sv'} id="spSt" ref={stRef}>
            {stShown === 'ed' ? I.pen15 : I.tick15}
            <span>{stShown === 'ed' ? 'Edits not out' : 'Live'}</span>
        </span>
    );
    const mattersBox = (cls: string, id: string) => (
        <label className={`sp-chk${cls}${matters ? ' on' : ''}`} id={id}>
            <input type="checkbox" className="sp-cbx" checked={matters} disabled={!dirty} onChange={(e) => setMatters(e.target.checked)} />
            <span className="bx" aria-hidden="true">
                {I.tick13}
            </span>
            <span>This change matters</span>
        </label>
    );

    const ctx: SiteCtx = {
        works: props.works,
        links,
        setLink: (u, v) => setLinks((l) => ({ ...l, [u]: v })),
        portrait,
        setPortrait: (src) => setPortraitState((p) => ({ ...p, draft: src })),
        page: <T,>(key: PageKey) => pages[key] as unknown as Page<T>,
        update,
        saved,
        open,
        openCard,
        closeCard,
        setOpen,
        pop,
        popShow,
        popClose,
        confirm,
        after,
        swap,
        sub,
        setSub,
        matters,
        setMatters,
        line,
        setLine,
    };

    function popover(): ReactNode {
        if (!pop) {
            return null;
        }

        return pop.render(`nt-pop${popOn ? ' on' : ''}${sheet ? ' sheet' : ''}`, sheet ? {} : popAt, popBox);
    }

    return (
        <AdminLayout section="site">
            <div className="sp-root">
                <div className="b-wh">
                    <h1 className="b-h" id="sT">
                        Site
                    </h1>
                    {isPage && (
                        <div className="sp-pb" id="spPB">
                            {done && done.k === k ? (
                                <span className="nt-sv ok">
                                    {I.tick15}
                                    <span>
                                        Published ·{' '}
                                        <button className="w-act sp-undo" type="button" onClick={() => void undo()}>
                                            Undo
                                        </button>
                                    </span>
                                </span>
                            ) : (
                                stEl
                            )}
                            {DOC[tab] && mattersBox('', 'spMt')}
                            <button className="w-act sp-vs" type="button" aria-haspopup="dialog" aria-expanded="false" aria-label="Versions" onClick={(e) => versions(e.currentTarget)}>
                                {I.hist17}
                                <span>Versions</span>
                            </button>
                            <button
                                className={`k-btn sm sp-pub${pop && pop.id !== 'vers' && pop.id !== 'confirm' ? ' line' : ''}`}
                                type="button"
                                disabled={!dirty || none}
                                title={none ? (tab === 'terms' ? 'Terms need at least one section' : 'Privacy needs at least one section') : undefined}
                                onClick={(e) => void publish(e.currentTarget)}
                            >
                                <span>Publish</span>
                                <Ring />
                            </button>
                        </div>
                    )}
                </div>
                <div className="sp-main" ref={main} style={{ position: 'relative' }}>
                    <div className="nw-tools sp-tools">
                        <div className="k-tabs sp-tabs" role="tablist" id="spT">
                            {TABS.map(([id, label]) => (
                                <a
                                    key={id}
                                    href="#"
                                    role="tab"
                                    className={tab === id ? 'on' : ''}
                                    aria-selected={tab === id}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        goTab(id, e.currentTarget);
                                    }}
                                >
                                    {label}
                                </a>
                            ))}
                        </div>
                    </div>
                    <div className={`sp-pane${out ? ' nt-out' : ''}`} id="spP" ref={pane} data-t={tab} aria-live="polite">
                        {tab === 'about' && <AboutTab s={ctx} />}
                        {tab === 'print' && <InPrintTab s={ctx} />}
                        {DOC[tab] && <DocTab s={ctx} k={k as 'privacy' | 'terms'} key={k} mattersBox={mattersBox(' phx', 'spMt2')} />}
                        {tab === 'home' && <HomeTab s={ctx} home={home} setHome={setHome} />}
                        {tab === 'notice' && <NoticeTab s={ctx} notice={notice} setNotice={setNotice} key={notice.current ? `${notice.current.id}` : 'none'} />}
                        {tab === 'share' && <SharingTab s={ctx} sharing={sharing} setSharing={setSharing} host={siteUrl.replace(/^https?:\/\//, '').replace(/\/$/, '')} />}
                        {tab === 'contact' && <ContactTab s={ctx} contact={contact} networks={props.networks} setContact={setContact} />}
                    </div>
                    {pop && !sheet && popover()}
                </div>
            </div>
            {createPortal(
                <>
                    <div className={`nt-scrim${pop && sheet && popOn ? ' on' : ''}`} onClick={() => popClose()} />
                    {pop && sheet && popover()}
                    {toast && (
                        <div className={`sp-toast${toast.on ? ' on' : ''}`} role="status">
                            {I.tick15}
                            <span>{toast.msg}</span>
                            {toast.href && (
                                <a className="k-l" href={toast.href}>
                                    <span>{toast.label}</span>
                                </a>
                            )}
                            <button className="ad-ic" type="button" aria-label="Close" onClick={hideToast}>
                                {I.x16}
                            </button>
                        </div>
                    )}
                </>,
                document.body,
            )}
        </AdminLayout>
    );
}

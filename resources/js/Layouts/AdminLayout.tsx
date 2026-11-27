import { Head, Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import AdminTop from '../Components/admin/AdminTop';
import { Chevron, NAV_ICONS, Plus } from '../Components/admin/Icons';
import NewWork, { openNewWork } from '../Components/admin/NewWork';
import { InviteDialog } from '../Components/admin/People';
import { json } from '../lib/http';
import { forgetKept } from '../lib/offline';

export type AdminSection = 'desk' | 'works' | 'notes' | 'comments' | 'reviewers' | 'stats' | 'newsletter' | 'site';

export const SECTIONS: { key: AdminSection; name: string; href: string }[] = [
    { key: 'desk', name: 'Desk', href: '/' },
    { key: 'works', name: 'Works', href: '/works' },
    { key: 'notes', name: 'Notes', href: '/notes' },
    { key: 'comments', name: 'Comments', href: '/comments' },
    { key: 'reviewers', name: 'Reviewers', href: '/reviewers' },
    { key: 'stats', name: 'Stats', href: '/stats' },
    { key: 'newsletter', name: 'Newsletter', href: '/newsletter' },
    { key: 'site', name: 'Site', href: '/site' },
];

/** The margin's sections; Comments carries how many comments are new. */
function Nav({ section }: { section: AdminSection }) {
    const { commentsNew = 0 } = usePage().props as unknown as { commentsNew?: number };
    const [fresh, setFresh] = useState(commentsNew);

    /* the inbox tells the margin when she has seen or acted on comments */
    useEffect(() => setFresh(commentsNew), [commentsNew]);
    useEffect(() => {
        const set = (e: Event) => setFresh((e as CustomEvent<number>).detail);
        window.addEventListener('admin:comments-new', set);

        return () => window.removeEventListener('admin:comments-new', set);
    }, []);

    return (
        <nav className="b-mn k-mn" aria-label="Admin">
            {SECTIONS.map((s) => {
                const badge = s.key === 'comments' && fresh > 0 ? <small>{fresh}</small> : null;

                return s.key === section ? (
                    <Link key={s.key} href={s.href} className="on" aria-current="page">
                        {NAV_ICONS[s.key]}
                        {s.name}
                        {badge}
                    </Link>
                ) : (
                    <Link key={s.key} href={s.href}>
                        {NAV_ICONS[s.key]}
                        {s.name}
                        {badge}
                    </Link>
                );
            })}
        </nav>
    );
}

/** Forget this device: the next sign-in here asks for the code again. "Forgotten" shows for a moment. */
function ForgetDevice() {
    const [done, setDone] = useState(false);

    return (
        <button
            className="k-l"
            type="button"
            disabled={done}
            onClick={async () => {
                const { status } = await json('POST', '/forget-device');
                if (status === 200) {
                    setDone(true);
                    window.setTimeout(() => setDone(false), 1800);
                }
            }}
        >
            <span>{done ? 'Forgotten' : 'Forget this device'}</span>
        </button>
    );
}

const signOut = async () => {
    await forgetKept();
    router.post('/sign-out');
};

type NowRow = { href: string; title: string; meta: string };

/** Writing now: the works with the freshest drafts, each opening where she left off; New work under them. */
function WritingNow({ rows, newWork = false }: { rows: NowRow[]; newWork?: boolean }) {
    if (rows.length === 0 && !newWork) {
        return null;
    }

    return (
        <div className="b-now">
            {rows.length > 0 && (
                <>
                    <h3>Writing now</h3>
                    <div className="nowl">
                        {rows.map((r) => (
                            <Link key={r.href} href={r.href}>
                                <b>{r.title}</b>
                                <small>{r.meta}</small>
                            </Link>
                        ))}
                    </div>
                </>
            )}
            {newWork && <NewWorkButton />}
        </div>
    );
}

function NewWorkButton() {
    return (
        <button
            className="k-l"
            type="button"
            onClick={(e) => {
                e.nativeEvent.stopImmediatePropagation();
                openNewWork();
            }}
        >
            <Plus />
            <span>New work</span>
        </button>
    );
}

/**
 * The admin shell, as the prototype's desk: header, the margin (menu, sign out), and the page, which settles in.
 * On phones the margin becomes a section switcher at the top of the page.
 */
export default function AdminLayout({ section, title, children }: { section: AdminSection; title?: string; children?: ReactNode }) {
    const [switcher, setSwitcher] = useState(false);
    const fold = useRef<HTMLDivElement>(null);
    const main = useRef<HTMLDivElement>(null);
    const current = SECTIONS.find((s) => s.key === section)!;
    const { writingNow = [] } = usePage().props as unknown as { writingNow?: NowRow[] };

    /* Settle: each block of the page inks in, one after another */
    useEffect(() => {
        const el = main.current;
        if (!el) {
            return;
        }
        [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i)));
        el.classList.remove('settle');
        void el.offsetWidth;
        el.classList.add('settle');
    }, [section]);

    /* New work opened from the switcher closes it */
    useEffect(() => {
        const close = () => setSwitcher(false);
        window.addEventListener('admin:new-work', close);

        return () => window.removeEventListener('admin:new-work', close);
    }, []);

    useEffect(() => {
        if (!switcher) {
            return;
        }
        const outside = (e: MouseEvent) => {
            if (!fold.current?.contains(e.target as Node)) {
                setSwitcher(false);
            }
        };
        const escape = (e: KeyboardEvent) => e.key === 'Escape' && setSwitcher(false);
        document.addEventListener('click', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('click', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [switcher]);

    return (
        <>
            <Head title={title ?? (section === 'desk' ? undefined : current.name)} />
            <main className="ad-page">
                <AdminTop className="k-top" />
                <div className="b-wrap">
                    <aside className="b-mg">
                        <Nav section={section} />
                        <WritingNow rows={writingNow} newWork />
                        <div className="b-out">
                            <button className="k-l" type="button" onClick={signOut}>
                                <span>Sign out</span>
                            </button>
                            <ForgetDevice />
                        </div>
                    </aside>
                    <div className="b-main" id="main" ref={main}>
                        <div className="b-swrap">
                            <button
                                className="b-sw"
                                type="button"
                                aria-expanded={switcher}
                                aria-controls="swf"
                                onClick={(e) => {
                                    e.nativeEvent.stopImmediatePropagation();
                                    setSwitcher(!switcher);
                                }}
                            >
                                <span>
                                    {NAV_ICONS[section]}
                                    {current.name}
                                </span>
                                <Chevron />
                            </button>
                            <div className={`b-swf${switcher ? ' open' : ''}`} id="swf" ref={fold}>
                                <Nav section={section} />
                                <WritingNow rows={writingNow.slice(0, 3)} />
                                <div className="foot">
                                    <NewWorkButton />
                                    <button className="k-l" type="button" onClick={signOut}>
                                        <span>Sign out</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
                <div className={`b-dim${switcher ? ' on' : ''}`} onClick={() => setSwitcher(false)} />
            </main>
            <NewWork />
            <InviteDialog />
        </>
    );
}

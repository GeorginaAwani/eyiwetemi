import { Head, Link, router } from '@inertiajs/react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import AdminTop from '../Components/admin/AdminTop';
import { Chevron, NAV_ICONS } from '../Components/admin/Icons';
import { json } from '../lib/http';

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

function Nav({ section }: { section: AdminSection }) {
    return (
        <nav className="b-mn k-mn" aria-label="Admin">
            {SECTIONS.map((s) =>
                s.key === section ? (
                    <Link key={s.key} href={s.href} className="on" aria-current="page">
                        {NAV_ICONS[s.key]}
                        {s.name}
                    </Link>
                ) : (
                    <Link key={s.key} href={s.href}>
                        {NAV_ICONS[s.key]}
                        {s.name}
                    </Link>
                ),
            )}
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

const signOut = () => router.post('/sign-out');

/**
 * The admin shell, as the prototype's desk: header, the margin (menu, sign out), and the page, which settles in.
 * On phones the margin becomes a section switcher at the top of the page.
 */
export default function AdminLayout({ section, title, children }: { section: AdminSection; title?: string; children?: ReactNode }) {
    const [switcher, setSwitcher] = useState(false);
    const fold = useRef<HTMLDivElement>(null);
    const main = useRef<HTMLDivElement>(null);
    const current = SECTIONS.find((s) => s.key === section)!;

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
                                <div className="foot">
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
        </>
    );
}

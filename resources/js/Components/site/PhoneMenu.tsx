import { Link, usePage } from '@inertiajs/react';
import { CSSProperties, useEffect, useSyncExternalStore } from 'react';
import { inertWhen } from '@/lib/dom';
import { currentTheme, setThemeFrom, subscribeTheme, THEMES } from '@/lib/motion';
import { paths } from '@/lib/paths';
import { CloseIcon } from './Icons';
import Wordmark from './Wordmark';
import { askSignIn } from '@/lib/signin';

const k = (n: number) => ({ '--k': n }) as CSSProperties;

/** Phone menu (≤ 900px): the Library kinds and Notes big, the rest small, then the reading theme. Lines settle in. */
export default function PhoneMenu({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { site, auth } = usePage().props;
    const theme = useSyncExternalStore(subscribeTheme, currentTheme, () => 'white' as const);

    useEffect(() => {
        document.documentElement.style.overflow = open ? 'hidden' : '';
        if (!open) {
            return;
        }
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        const onResize = () => window.innerWidth > 900 && onClose();
        window.addEventListener('keydown', onKey);
        window.addEventListener('resize', onResize);

        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', onResize);
        };
    }, [open, onClose]);

    const big: { href: string; label: string }[] = [
        { href: paths.library(), label: 'Everything' },
        { href: paths.library('novel'), label: 'Novels' },
        { href: paths.library('story'), label: 'Short stories' },
        { href: paths.library('poem'), label: 'Poetry' },
        { href: paths.library('essay'), label: 'Essays' },
        { href: paths.notes, label: 'Notes' },
    ];

    return (
        <div className={`pmenu${open ? ' open' : ''}`} id="pMenu" aria-hidden={!open} {...inertWhen(!open)}>
            <div className="wrap">
                <div className="pbar">
                    <Wordmark />
                    <button className="icon" id="pClose" type="button" aria-label="Close menu" onClick={onClose}>
                        <CloseIcon />
                    </button>
                </div>
                <nav className="pbig" aria-label="Library">
                    {big.map((item, i) => (
                        <Link key={item.href} href={item.href} style={k(i)} onClick={onClose}>
                            {item.label}
                            <span aria-hidden="true">→</span>
                        </Link>
                    ))}
                </nav>
                <nav className="psmall" style={k(6)}>
                    {auth.user === null && (
                        <a
                            href="#"
                            onClick={(e) => {
                                e.preventDefault();
                                onClose();
                                askSignIn();
                            }}
                        >
                            Sign in
                        </a>
                    )}
                    <Link href={paths.about} onClick={onClose}>
                        About Me
                    </Link>
                    <a href={`mailto:${site.email}`}>Write to me</a>
                    <Link href={paths.inPrint} onClick={onClose}>
                        In print
                    </Link>
                </nav>
                <div className="ptheme" style={k(7)}>
                    <div className="caps" style={{ fontSize: 10 }}>
                        Reading theme
                    </div>
                    <div className="tiles">
                        {THEMES.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                className={theme === t.key ? 'cur' : undefined}
                                aria-pressed={theme === t.key}
                                style={{ background: t.bg, color: t.ink }}
                                onClick={(e) => setThemeFrom(t.key, e.currentTarget)}
                            >
                                {t.name}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

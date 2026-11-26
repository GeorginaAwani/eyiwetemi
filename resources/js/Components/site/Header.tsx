import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { inertWhen } from '@/lib/dom';
import { currentTheme, setThemeFrom, subscribeTheme, THEMES } from '@/lib/motion';
import { paths } from '@/lib/paths';
import { Section } from '@/types/site';
import { ChevronDown, MenuIcon, SearchIcon, ThemeIcon } from './Icons';
import PhoneMenu from './PhoneMenu';
import Wordmark from './Wordmark';

type MenuName = 'library' | 'theme' | null;

export const useTheme = () => useSyncExternalStore(subscribeTheme, currentTheme, () => 'white' as const);

/** Header A: wordmark left; About Me, Library menu, Notes; search, reading theme, Sign in. */
export default function Header({ current }: { current?: Section }) {
    const { site, auth } = usePage().props;
    const [open, setOpen] = useState<MenuName>(null);
    const [phoneOpen, setPhoneOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const [hidden, setHidden] = useState(false);
    const theme = useTheme();
    const openRef = useRef(open);
    openRef.current = open;

    /* glass on scroll; hides going down, returns going up */
    useEffect(() => {
        let lastY = window.scrollY;
        const onScroll = () => {
            const y = window.scrollY;
            setScrolled(y > 8);
            setHidden(y > 400 && y > lastY && openRef.current === null);
            lastY = y;
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    /* one menu at a time; a click outside or Esc closes it */
    useEffect(() => {
        if (open === null) {
            return;
        }
        const onClick = (e: MouseEvent) => {
            if (!(e.target as Element).closest('.menu, .navbtn, #tBtn')) {
                setOpen(null);
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(null);
            }
        };
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const toggle = (name: Exclude<MenuName, null>) => setOpen((o) => (o === name ? null : name));
    const close = () => setOpen(null);
    const counts = site.library;
    const rows: { href: string; label: string; count: number }[] = [
        { href: paths.library('novel'), label: 'Novels', count: counts.novel },
        { href: paths.library('story'), label: 'Short stories', count: counts.story },
        { href: paths.library('poem'), label: 'Poetry', count: counts.poem },
        { href: paths.library('essay'), label: 'Essays', count: counts.essay },
    ];

    return (
        <>
            <header className={`hdr${scrolled ? ' scrolled' : ''}${hidden ? ' hide' : ''}`} id="hdr">
                <div className="wrap">
                    <Wordmark />
                    <div className="right">
                        <nav className="nav" style={{ position: 'relative' }} aria-label="Main">
                            <Link href={paths.about} prefetch className={current === 'about' ? 'on' : undefined} aria-current={current === 'about' ? 'page' : undefined}>
                                About Me
                            </Link>
                            <button
                                className={`navbtn${current === 'library' ? ' on' : ''}`}
                                id="wBtn"
                                type="button"
                                aria-expanded={open === 'library'}
                                aria-controls="wMenu"
                                onClick={() => toggle('library')}
                            >
                                Library
                                <ChevronDown />
                            </button>
                            <Link href={paths.notes} prefetch className={current === 'notes' ? 'on' : undefined} aria-current={current === 'notes' ? 'page' : undefined}>
                                Notes
                            </Link>
                            <div className={`menu${open === 'library' ? ' open' : ''}`} id="wMenu" style={{ left: 82, top: 52, width: 250 }} {...inertWhen(open !== 'library')}>
                                <Link className="row" href={paths.library()} prefetch onClick={close}>
                                    Everything<span className="cnt">{counts.all}</span>
                                </Link>
                                <div className="msep" />
                                {rows.map((r) => (
                                    <Link key={r.href} className="row" href={r.href} prefetch onClick={close}>
                                        {r.label}
                                        <span className="cnt">{r.count}</span>
                                    </Link>
                                ))}
                            </div>
                        </nav>
                        <div className="sep" />
                        <div className="tools" style={{ position: 'relative' }}>
                            <button className="icon" id="sBtn" type="button" aria-label="Search" onClick={() => window.dispatchEvent(new CustomEvent('eyi:search'))}>
                                <SearchIcon />
                            </button>
                            <button className="icon" id="tBtn" type="button" aria-label="Reading theme" aria-expanded={open === 'theme'} onClick={() => toggle('theme')}>
                                <ThemeIcon />
                            </button>
                            {auth.user === null && (
                                <button className="au-sgin" id="sgBtn" type="button" onClick={() => window.dispatchEvent(new CustomEvent('eyi:signin'))}>
                                    Sign in
                                </button>
                            )}
                            <button className="icon" id="mBtn" type="button" aria-label="Menu" aria-expanded={phoneOpen} aria-controls="pMenu" onClick={() => setPhoneOpen(true)}>
                                <MenuIcon />
                            </button>
                            <div className={`menu${open === 'theme' ? ' open' : ''}`} id="tMenu" style={{ right: 96 }} {...inertWhen(open !== 'theme')}>
                                <div className="caps" style={{ padding: '8px 14px 6px', fontSize: 10 }}>
                                    Reading theme
                                </div>
                                {THEMES.map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        className={`row${theme === t.key ? ' cur' : ''}`}
                                        aria-pressed={theme === t.key}
                                        onClick={(e) => setThemeFrom(t.key, e.currentTarget)}
                                    >
                                        <span className="dot" style={{ background: t.bg }} />
                                        {t.name}
                                        <span className="tick" aria-hidden="true">
                                            ✓
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <PhoneMenu open={phoneOpen} onClose={() => setPhoneOpen(false)} />
        </>
    );
}

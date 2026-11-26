import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { currentTheme, setThemeFrom, subscribeTheme, THEMES } from '../../lib/motion';
import { External, ThemeIcon, Tick } from './Icons';

export const ADMIN_THEME_KEY = 'eyiAdmTheme';

/** The theme menu: the round button and its four themes, as on the reading site. */
export function ThemeMenu() {
    const [open, setOpen] = useState(false);
    const theme = useSyncExternalStore(subscribeTheme, currentTheme, () => 'night');
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        const t = window.setTimeout(() => menu.current?.querySelector<HTMLElement>('[aria-checked=true]')?.focus(), 60);
        const outside = (e: MouseEvent) => {
            if (!menu.current?.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const escape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                button.current?.focus();
            }
        };
        document.addEventListener('click', outside);
        document.addEventListener('keydown', escape);

        return () => {
            window.clearTimeout(t);
            document.removeEventListener('click', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const arrows = (e: React.KeyboardEvent) => {
        const items = [...(menu.current?.querySelectorAll<HTMLElement>('.ad-mi') ?? [])];
        const i = items.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            items[(i + 1) % items.length]?.focus();
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            items[(i + items.length - 1) % items.length]?.focus();
        }
    };

    return (
        <div className="ad-tw">
            <button
                ref={button}
                className="ad-ic"
                id="tBtn"
                type="button"
                aria-label="Theme"
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={(e) => {
                    e.nativeEvent.stopImmediatePropagation();
                    setOpen(!open);
                }}
            >
                <ThemeIcon />
            </button>
            <div ref={menu} className={`ad-menu${open ? ' open' : ''}`} id="tMenu" role="menu" onKeyDown={arrows}>
                {THEMES.map((t) => (
                    <button
                        key={t.key}
                        className="ad-mi"
                        type="button"
                        role="menuitemradio"
                        aria-checked={theme === t.key}
                        tabIndex={open ? 0 : -1}
                        onClick={(e) => {
                            setOpen(false);
                            setThemeFrom(t.key, e.currentTarget, ADMIN_THEME_KEY);
                            button.current?.focus();
                        }}
                    >
                        <span>{t.name}</span>
                        <Tick />
                    </button>
                ))}
            </div>
        </div>
    );
}

/** The wordmark and "Admin": both lead back to the reading site. */
export function Brand() {
    const { siteUrl } = usePage().props as unknown as { siteUrl: string };

    return (
        <div className="ad-brand">
            <a className="ad-home" href={siteUrl} aria-label="eyiwetemi, the reading site">
                <span className="wm" aria-label="eyiwetemi">
                    <span className="r">ey</span>
                    <span className="r i first">ı</span>wetem<span className="i">ı</span>
                </span>
            </a>
            <span className="ad-adm">Admin</span>
        </div>
    );
}

/** View site ↗ */
export function ViewSite() {
    const { siteUrl } = usePage().props as unknown as { siteUrl: string };

    return (
        <a className="ad-site" href={siteUrl} aria-label="View site">
            View site
            <External />
        </a>
    );
}

/** The admin header: the wordmark and "Admin", View site ↗ and the theme menu. */
export default function AdminTop({ className = '' }: { className?: string }) {
    return (
        <header className={`ad-top ${className}`.trim()}>
            <Brand />
            <div className="ad-tr">
                <ViewSite />
                <ThemeMenu />
            </div>
        </header>
    );
}

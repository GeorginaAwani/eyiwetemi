import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { inertWhen } from '@/lib/dom';
import { json } from '@/lib/http';
import { currentTheme, setThemeFrom, subscribeTheme, THEMES } from '@/lib/motion';
import { paths } from '@/lib/paths';
import { askSignIn } from '@/lib/signin';
import { Section } from '@/types/site';
import Bloom from './Bloom';
import { NotificationPicture, NotificationT, openNotification } from './NotificationRow';
import { BellIcon, ChevronDown, GearIcon, MenuIcon, SearchIcon, SignOutIcon, ThemeIcon } from './Icons';
import PhoneMenu from './PhoneMenu';
import Wordmark from './Wordmark';
import { forgetReading } from './Offline';

type MenuName = 'library' | 'theme' | 'account' | 'bell' | null;

/* the Bloom comes in only when you've just signed in, not on every page */
let lastUserId: number | null | undefined;

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
            if (!(e.target as Element).closest('.menu, .navbtn, #tBtn, #aBtn, #nBtn')) {
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
    const me = auth.user;
    const arrive = me !== null && lastUserId === null;
    useEffect(() => {
        lastUserId = me?.id ?? null;
    }, [me]);
    /* the bell: its dot follows what's unread; the short list loads when you open it */
    const [unread, setUnread] = useState(me?.unread ?? 0);
    const [bell, setBell] = useState<NotificationT[] | null>(null);
    useEffect(() => setUnread(me?.unread ?? 0), [me?.unread]);
    const openBell = async () => {
        toggle('bell');
        const { status, data } = await json<{ unread: number; rows: NotificationT[] }>('GET', '/notifications/menu');
        if (status === 200) {
            setBell(data.rows ?? []);
            setUnread(data.unread ?? 0);
        }
    };
    const markAll = async () => {
        setBell((rows) => rows?.map((r) => ({ ...r, unread: false })) ?? null);
        setUnread(0);
        await json('POST', '/notifications/read');
    };
    const signOut = () => {
        close();
        forgetReading();
        router.post('/logout');
    };
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
                            {me === null && (
                                <button className="au-sgin" id="sgBtn" type="button" onClick={() => askSignIn()}>
                                    Sign in
                                </button>
                            )}
                            {me !== null && (
                                <button
                                    className="icon"
                                    id="nBtn"
                                    type="button"
                                    aria-label={unread ? `Notifications, ${unread} new` : 'Notifications'}
                                    aria-expanded={open === 'bell'}
                                    aria-controls="nMenu"
                                    onClick={openBell}
                                >
                                    <BellIcon />
                                    <span className={`ndot${unread ? '' : ' gone'}`} />
                                </button>
                            )}
                            <button className="icon" id="mBtn" type="button" aria-label="Menu" aria-expanded={phoneOpen} aria-controls="pMenu" onClick={() => setPhoneOpen(true)}>
                                <MenuIcon />
                            </button>
                            {me !== null && (
                                <button
                                    className={`icon acct${arrive ? ' arrive' : ''}`}
                                    id="aBtn"
                                    type="button"
                                    aria-label="Your profile"
                                    aria-expanded={open === 'account'}
                                    aria-controls="aMenu"
                                    onClick={() => toggle('account')}
                                >
                                    <Bloom seed={me.avatar_seed} size={40} />
                                    <svg className="ring" viewBox="0 0 46 46" aria-hidden="true">
                                        <circle cx="23" cy="23" r="22" pathLength="100" />
                                    </svg>
                                </button>
                            )}
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
                            {me !== null && (
                                <div className={`menu${open === 'bell' ? ' open' : ''}`} id="nMenu" style={{ right: 48, width: 400 }} {...inertWhen(open !== 'bell')}>
                                    <div className="nhead">
                                        <b>Notifications</b>
                                        {unread > 0 && (
                                            <button className="mark" type="button" onClick={markAll}>
                                                Mark all read
                                            </button>
                                        )}
                                    </div>
                                    {bell !== null && bell.length === 0 && <div className="nlabel caps">Nothing yet</div>}
                                    {(bell ?? []).map((n, k, all) => {
                                        const label = n.unread ? 'New' : 'Earlier';
                                        const first = k === 0 || (all[k - 1].unread ? 'New' : 'Earlier') !== label;

                                        return (
                                            <Fragment key={n.id}>
                                                {first && <div className="caps nlabel">{label}</div>}
                                                <a
                                                    className={`nrow${n.unread ? ' unread' : ' read'}`}
                                                    href={n.href}
                                                    onClick={(e) => {
                                                        close();
                                                        openNotification(e, n, setUnread);
                                                    }}
                                                >
                                                    <NotificationPicture thumb={n.thumb} size="menu" />
                                                    <span className="nt">
                                                        <span>{n.title}</span>
                                                        <small>{n.small}</small>
                                                    </span>
                                                    <span className="nw">
                                                        {n.when}
                                                        <i />
                                                    </span>
                                                </a>
                                            </Fragment>
                                        );
                                    })}
                                    <div style={{ height: 1, background: 'var(--line)', margin: '8px 0' }} />
                                    <div style={{ padding: '6px 14px 10px' }}>
                                        <Link className="link" href="/notifications" style={{ fontSize: 14 }} onClick={close}>
                                            See all notifications
                                        </Link>
                                    </div>
                                </div>
                            )}
                            {me !== null && (
                                <div className={`menu${open === 'account' ? ' open' : ''}`} id="aMenu" style={{ right: 0, width: 300 }} {...inertWhen(open !== 'account')}>
                                    <Link className="row prof" href="/profile" onClick={close} style={{ height: 'auto', padding: 12, background: 'var(--surf)' }}>
                                        <Bloom seed={me.avatar_seed} size={44} />
                                        <span style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                                            <b style={{ fontWeight: 700 }}>{me.name.split(' ')[0]}</b>
                                            <small style={{ fontSize: 12, color: 'var(--muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{me.email}</small>
                                        </span>
                                        <span style={{ color: 'var(--muted)' }} aria-hidden="true">
                                            ›
                                        </span>
                                    </Link>
                                    <div style={{ height: 1, background: 'var(--line)', margin: '6px 0' }} />
                                    <Link className="row" href="/settings" onClick={close}>
                                        <span style={{ width: 44, display: 'grid', placeItems: 'center', color: 'var(--muted)' }}>
                                            <GearIcon />
                                        </span>
                                        Settings
                                    </Link>
                                    <button className="row" type="button" onClick={signOut}>
                                        <span style={{ width: 44, display: 'grid', placeItems: 'center', color: 'var(--muted)' }}>
                                            <SignOutIcon />
                                        </span>
                                        Sign out
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </header>
            <PhoneMenu open={phoneOpen} onClose={() => setPhoneOpen(false)} />
        </>
    );
}

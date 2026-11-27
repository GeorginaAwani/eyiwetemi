import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { prefersReducedMotion } from '../../lib/motion';
import { useOnline } from '../../lib/offline';

/**
 * Light offline reading (her pick: A · Quiet pills). The open chapter and the next one are kept on the device
 * quietly by the service worker (public/sw.js). When the connection drops a pill says so; chapters that aren't kept
 * go faint in the chapters panel and a tap on one stays put with a nudge; Listen says it needs a connection;
 * comments written offline wait and send when it's back.
 */
export const CloudOff = ({ size = 16 }: { size?: number }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M7 18h10.5a4 4 0 0 0 1.2-7.8A6.2 6.2 0 0 0 7.1 8.6 4.8 4.8 0 0 0 7 18z" />
        <path d="M3.5 3.5l17 17" />
    </svg>
);

let started = false;
function startWorker() {
    if (started || !('serviceWorker' in navigator)) {
        return;
    }
    started = true;
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
}

async function tell(message: Record<string, unknown>) {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    const reg = await navigator.serviceWorker.ready;
    reg.active?.postMessage(message);
}

/** Signing out: chapters kept for this reader go. */
export function forgetReading() {
    localStorage.removeItem(KEPT);
    void tell({ type: 'clear' });
}

const pathOf = (href: string) => {
    try {
        const u = new URL(href, location.origin);

        return u.origin === location.origin ? u.pathname : null;
    } catch {
        return null;
    }
};

type Chapter = { number: number; href: string | null };

/** The chapters kept on this device, newest first (the service worker keeps the same last few). */
const KEPT = 'eyiReadKept';
const keptPaths = (): string[] => {
    try {
        return JSON.parse(localStorage.getItem(KEPT) ?? '[]') as string[];
    } catch {
        return [];
    }
};

export function useOfflineReading({ number, nextHref, chapters }: { number: number; nextHref: string | null; chapters: Chapter[] }) {
    const online = useOnline();
    const version = (usePage() as unknown as { version: string | null }).version ?? '';
    const [pill, setPill] = useState<{ text: string; show: boolean }>({ text: '', show: false });
    const pillTimer = useRef(0);
    const nextNumber = nextHref ? (chapters.find((c) => c.href && pathOf(c.href) === pathOf(nextHref))?.number ?? null) : null;
    /* online: this one and the next are being kept; offline: only what really was */
    const kept = keptPaths();
    const saved = online
        ? [number, ...(nextNumber ? [nextNumber] : [])]
        : [number, ...chapters.filter((c) => c.number !== number && c.href && kept.includes(pathOf(c.href) ?? '')).map((c) => c.number)];
    const nextSaved = nextNumber && saved.includes(nextNumber) ? nextNumber : null;
    const savedRef = useRef(saved);
    savedRef.current = saved;

    const say = (text: string) => {
        /* out, then in again with the new words */
        setPill({ text, show: false });
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => setPill({ text, show: true })));
        window.clearTimeout(pillTimer.current);
        pillTimer.current = window.setTimeout(() => setPill((p) => ({ ...p, show: false })), 4200);
    };
    const offLine = () => (nextSaved ? `Offline. Chapter ${nextSaved} is saved too.` : 'Offline. This chapter is saved.');

    /* keep this chapter and the next, quietly */
    useEffect(() => {
        startWorker();
        if (!online) {
            return;
        }
        const paths = [location.pathname, ...(nextHref && pathOf(nextHref) ? [pathOf(nextHref) as string] : [])];
        void tell({ type: 'keep', paths, version });
        localStorage.setItem(KEPT, JSON.stringify([...[...paths].reverse(), ...keptPaths().filter((p) => !paths.includes(p))].slice(0, 6)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [number, nextHref, online]);

    /* the pill when the connection drops or comes back (and once on opening a chapter offline) */
    const first = useRef(true);
    useEffect(() => {
        document.documentElement.classList.toggle('is-off', !online);
        if (first.current) {
            first.current = false;
            if (!online) {
                window.setTimeout(() => say(offLine()), 500);
            }

            return;
        }
        say(online ? 'Back online' : offLine());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [online]);
    useEffect(() => () => document.documentElement.classList.remove('is-off'), []);

    /* offline, a chapter that isn't kept stays put and says why; so does Listen */
    useEffect(() => {
        if (online) {
            return;
        }
        const nudge = (el: HTMLElement) => {
            el.classList.add('of-nudge');
            window.setTimeout(() => el.classList.remove('of-nudge'), 900);
            if (!prefersReducedMotion()) {
                el.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-5px)' }, { transform: 'translateX(4px)' }, { transform: 'translateX(-2px)' }, { transform: 'translateX(0)' }], {
                    duration: 360,
                });
            }
        };
        const click = (e: MouseEvent) => {
            const t = e.target as HTMLElement;
            const a = t.closest<HTMLAnchorElement>('a[href]');
            if (a) {
                const path = pathOf(a.getAttribute('href') ?? '');
                const ch = path ? chapters.find((c) => c.href && pathOf(c.href) === path) : null;
                if (ch && !savedRef.current.includes(ch.number)) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    nudge(a);
                    say(`Chapter ${ch.number} needs a connection`);
                }

                return;
            }
            const lis = t.closest<HTMLElement>('.lis');
            if (lis) {
                e.preventDefault();
                e.stopImmediatePropagation();
                nudge(lis);
                say('The soundtrack needs a connection');
            }
        };
        document.addEventListener('click', click, true);

        return () => document.removeEventListener('click', click, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [online, chapters]);

    const away = (n: number) => !online && !saved.includes(n);

    return {
        online,
        away,
        pill: (
            <div className={`of-pill${pill.show ? ' show' : ''}${pill.text === 'Back online' ? ' on-line' : ''}`} role="status" aria-live="polite">
                <CloudOff />
                <span>{pill.text}</span>
            </div>
        ),
    };
}

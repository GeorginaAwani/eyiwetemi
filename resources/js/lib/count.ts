import { useEffect } from 'react';
import { beacon } from '@/lib/http';

/**
 * Reading counts: first-party and cookieless. The browser only says what was read; the server keeps daily totals.
 * A read is a page open for 10 seconds (while it's on screen); finished is reaching the end.
 */

/* where this visit came from: the page the reader landed on, kept for the visit (not stored anywhere) */
const landing = typeof window === 'undefined' ? { ref: '', from: '' } : { ref: document.referrer, from: new URLSearchParams(window.location.search).get('from') ?? '' };

type Hit = { t: 'item' | 'note' | 'page'; id?: number; page?: string; e: 'read' | 'end' };
const send = (hit: Hit) => beacon('/count', { ...hit, ref: landing.ref || null, from: landing.from || null });

/** Runs `done` once the page has been on screen for `ms` in total (hidden tabs don't count). */
function onScreenFor(ms: number, done: () => void): () => void {
    let left = ms;
    let since = 0;
    let t = 0;
    const start = () => {
        since = Date.now();
        t = window.setTimeout(done, left);
    };
    const pause = () => {
        window.clearTimeout(t);
        left -= Date.now() - since;
    };
    const vis = () => (document.visibilityState === 'visible' ? start() : pause());
    if (document.visibilityState === 'visible') {
        start();
    }
    document.addEventListener('visibilitychange', vis);

    return () => {
        window.clearTimeout(t);
        document.removeEventListener('visibilitychange', vis);
    };
}

/** A chapter or piece: a read after 10 seconds, finished when the end row comes into view after that. */
export function useReadCount(itemId: number, end: React.RefObject<HTMLElement | null>) {
    useEffect(() => {
        let read = false;
        let reached = false;
        let ended = false;
        const finish = () => {
            if (read && reached && !ended) {
                ended = true;
                send({ t: 'item', id: itemId, e: 'end' });
            }
        };
        const stop = onScreenFor(10_000, () => {
            read = true;
            send({ t: 'item', id: itemId, e: 'read' });
            finish();
        });
        const io = new IntersectionObserver((es) => {
            if (es.some((x) => x.isIntersecting)) {
                reached = true;
                finish();
            }
        });
        if (end.current) {
            io.observe(end.current);
        }

        return () => {
            stop();
            io.disconnect();
        };
    }, [itemId]);
}

/** Diary notes: a note is read when it has been mostly on screen for 10 seconds. */
export function useNoteCounts(root: React.RefObject<HTMLElement | null>, deps: unknown[]) {
    useEffect(() => {
        const box = root.current;
        if (!box) {
            return;
        }
        const timers = new Map<Element, () => void>();
        const done = new Set<number>();
        const io = new IntersectionObserver(
            (es) =>
                es.forEach((x) => {
                    const id = Number((x.target as HTMLElement).id.replace('n-', ''));
                    if (!id || done.has(id)) {
                        return;
                    }
                    if (x.isIntersecting) {
                        if (!timers.has(x.target)) {
                            timers.set(
                                x.target,
                                onScreenFor(10_000, () => {
                                    done.add(id);
                                    send({ t: 'note', id, e: 'read' });
                                }),
                            );
                        }
                    } else {
                        timers.get(x.target)?.();
                        timers.delete(x.target);
                    }
                }),
            { threshold: 0.4 },
        );
        box.querySelectorAll('article.dn[id^="n-"]').forEach((a) => io.observe(a));

        return () => {
            io.disconnect();
            timers.forEach((stop) => stop());
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);
}

/** Which "site page" an address is, for the visits count. Reading pages and account pages aren't site pages. */
export function pageKey(path: string): string | null {
    const p = path.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    const fixed: Record<string, string> = {
        '/': 'home',
        '/about': 'about',
        '/library': 'library',
        '/in-print': 'in_print',
        '/notes': 'notes',
        '/privacy': 'privacy',
        '/terms': 'terms',
        '/rss': 'other',
    };
    if (fixed[p]) {
        return fixed[p];
    }
    if (/^\/library\/[^/]+$/.test(p)) {
        return 'work';
    }
    if (/^\/series\/[^/]+$/.test(p)) {
        return 'series';
    }

    return null;
}

/** A visit to a site page, counted as it opens. */
export function countPage(path: string) {
    const page = pageKey(path);
    if (page) {
        send({ t: 'page', page, e: 'read' });
    }
}

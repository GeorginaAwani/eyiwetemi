import { router } from '@inertiajs/react';

export const prefersReducedMotion = (): boolean =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export type Theme = 'white' | 'paper' | 'grey' | 'night';
export const THEMES: { key: Theme; name: string; bg: string; ink: string }[] = [
    { key: 'white', name: 'White', bg: '#fff', ink: '#1E1C1D' },
    { key: 'paper', name: 'Paper', bg: '#F5F0E6', ink: '#3E2723' },
    { key: 'grey', name: 'Dark grey', bg: '#2B2A2C', ink: '#EEEBEA' },
    { key: 'night', name: 'Night', bg: '#151314', ink: '#DCD5D3' },
];

const themeListeners = new Set<() => void>();

export const currentTheme = (): Theme => (document.documentElement.dataset.theme as Theme) || 'white';

export const subscribeTheme = (listener: () => void): (() => void) => {
    themeListeners.add(listener);

    return () => themeListeners.delete(listener);
};

/**
 * Bloom: the new theme opens as a soft circle from where you clicked, and the theme icon turns.
 * Reduced motion swaps it straight away.
 */
export function setTheme(theme: Theme, x: number, y: number, storageKey = 'eyiTheme'): void {
    const reduce = prefersReducedMotion();
    const icon = document.querySelector('#tBtn svg');

    if (!reduce && icon) {
        icon.animate([{ transform: 'rotate(180deg) scale(.7)' }, { transform: 'rotate(540deg) scale(1)' }], {
            duration: 650,
            easing: 'cubic-bezier(.34,1.45,.64,1)',
        });
    }

    const apply = () => {
        document.documentElement.dataset.theme = theme;
        try {
            /* the admin keeps its own theme, stored as JSON like its prototype */
            localStorage.setItem(storageKey, storageKey === 'eyiTheme' ? theme : JSON.stringify(theme));
        } catch {
            /* private mode: the theme lasts for this visit */
        }
        themeListeners.forEach((listener) => listener());
    };

    const doc = document as Document & { startViewTransition?: (cb: () => void) => { ready: Promise<void> } };

    if (!doc.startViewTransition || reduce) {
        apply();

        return;
    }

    const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
    doc.startViewTransition(apply).ready.then(() => {
        document.documentElement.animate(
            { clipPath: [`circle(0 at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
            { duration: 900, easing: 'cubic-bezier(.22,1,.36,1)', pseudoElement: '::view-transition-new(root)' },
        );
    });
}

/** Set the theme from a button, blooming from its centre. */
export function setThemeFrom(theme: Theme, el: HTMLElement, storageKey = 'eyiTheme'): void {
    const r = el.getBoundingClientRect();
    setTheme(theme, r.left + r.width / 2, r.top + r.height / 2, storageKey);
}

/**
 * Settle on scroll: anything marked data-reveal inks in when it comes into view.
 * Pictures (.book, .cover) are clipped at rest, so their parent is watched instead.
 */
export function watchReveals(root: ParentNode = document): () => void {
    const observers: IntersectionObserver[] = [];
    const io = new IntersectionObserver(
        (entries) =>
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    e.target.classList.add('in');
                    io.unobserve(e.target);
                }
            }),
        { threshold: 0.18, rootMargin: '0px 0px -6% 0px' },
    );
    observers.push(io);

    root.querySelectorAll<HTMLElement>('[data-reveal]:not(.in)').forEach((el) => {
        if (!el.matches('.book,.cover')) {
            io.observe(el);

            return;
        }
        const parent = el.parentElement;
        if (!parent) {
            return;
        }
        const w = new IntersectionObserver(
            (entries) =>
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        el.classList.add('in');
                        w.disconnect();
                    }
                }),
            { threshold: 0, rootMargin: '0px 0px -8% 0px' },
        );
        w.observe(parent);
        observers.push(w);
    });

    return () => observers.forEach((o) => o.disconnect());
}

/**
 * Page change: the old page goes faint and soft while the next one loads; after 300 ms the
 * wordmark's two i-dots bob. The new page's main and footer settle in (keyed by URL).
 */
export function wirePageChange(): void {
    const root = document.documentElement;
    let dotsTimer: number | undefined;

    router.on('start', (event) => {
        const visit = event.detail.visit;
        if (visit.prefetch || visit.only.length > 0 || visit.method !== 'get') {
            return;
        }
        root.classList.add('leaving');
        dotsTimer = window.setTimeout(() => root.classList.add('loading'), 300);
    });

    router.on('finish', () => {
        window.clearTimeout(dotsTimer);
        root.classList.remove('leaving', 'loading');
    });

    router.on('navigate', () => {
        root.classList.remove('arrive');
    });

    window.addEventListener('popstate', () => {
        root.classList.remove('arrive');
        void root.offsetWidth;
        root.classList.add('arrive');
    });
}

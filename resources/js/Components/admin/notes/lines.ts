import { prefersReducedMotion as RM } from '../../../lib/motion';

/* The Notes and Newsletter pages' shared pieces: West Africa Time labels (the server sends WAT strings) and the
   prototype's motion for lines that unfold into cards. */
/* ------------------------------------------------------------------ dates (all West Africa Time, as the server sends them) */
export const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const MONTH = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
export const DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const pd = (d: string) => {
    const [y, m, dd] = d.split('-').map(Number);

    return new Date(y, m - 1, dd);
};
export const ymd = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
export const dShort = (d: string) => `${pd(d).getDate()} ${MON[pd(d).getMonth()]}`;
export const dDay = (d: string) => {
    const x = pd(d);

    return `${DAY[x.getDay()]} ${x.getDate()} ${MON[x.getMonth()]}`;
};
export const tFmt = (hm: string) => {
    const [h, m] = hm.split(':').map(Number);

    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
};
export const atFmt = (at: string) => `${dDay(at.slice(0, 10))}, ${tFmt(at.slice(11, 16))}`;
export function ago(iso: string) {
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) {
        return 'just now';
    }
    if (m < 60) {
        return m + ' min ago';
    }
    if (m < 1440) {
        return Math.round(m / 60) + ' h ago';
    }

    return m < 2880 ? 'yesterday' : Math.round(m / 1440) + ' d ago';
}
export const TIMES = [...Array(48)].map((_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`);

/* ------------------------------------------------------------------ motion (as the prototype) */
export const EASE = 'cubic-bezier(.22,.8,.2,1)';
export function after(an: Animation, ms: number, fn: () => void) {
    let done = false;
    const f = () => {
        if (!done) {
            done = true;
            fn();
        }
    };
    an.onfinish = f;
    window.setTimeout(f, ms + 60);
}
export function grow(el: HTMLElement, from: number) {
    if (RM()) {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: from + 'px', opacity: 0.6 }, { height: h + 'px', opacity: 1 }], { duration: 380, easing: EASE }), 380, () => (el.style.overflow = ''));
    [...el.children].forEach((c, i) =>
        c.animate([{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(6px)' }, { opacity: 1, filter: 'none', transform: 'none' }], { duration: 420, delay: 60 + i * 40, easing: EASE, fill: 'backwards' }),
    );
}
export function shrink(el: HTMLElement, to: number, done: () => void) {
    if (RM()) {
        done();

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: h + 'px', opacity: 1 }, { height: to + 'px', opacity: 0.4 }], { duration: 300, easing: EASE, fill: 'forwards' }), 300, done);
}
export function bye(el: HTMLElement, done: () => void) {
    if (RM()) {
        after(el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' }), 160, done);

        return;
    }
    const h = el.offsetHeight;
    el.style.overflow = 'hidden';
    after(el.animate([{ height: h + 'px', opacity: 1, filter: 'none' }, { height: '0px', opacity: 0, filter: 'blur(4px)' }], { duration: 320, easing: EASE, fill: 'forwards' }), 320, done);
}
export function inA(el: Element | null) {
    if (!el) {
        return;
    }
    if (RM()) {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

        return;
    }
    el.animate([{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(4px)' }, { opacity: 1, filter: 'none', transform: 'none' }], { duration: 420, easing: EASE });
}
export function settle(el: Element | null) {
    if (!el) {
        return;
    }
    [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i)));
    el.classList.remove('settle');
    void (el as HTMLElement).offsetWidth;
    el.classList.add('settle');
}
export const phone = () => window.innerWidth <= 600;
export const xsrf = (): string => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** A label that changes: the old words lift away and the new ones settle in. */
export function turn(el: Element | null, change: () => void) {
    if (!el) {
        change();

        return;
    }
    if (RM()) {
        change();
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180 });

        return;
    }
    const away = el.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-4px)' }], { duration: 120, fill: 'forwards' });
    after(away, 120, () => {
        change();
        /* the way out held the words hidden; let go of it as they come back */
        el.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 240, easing: EASE });
        away.cancel();
    });
}

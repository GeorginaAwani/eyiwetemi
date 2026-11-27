import { ReactNode, useEffect, useRef } from 'react';
import { prefersReducedMotion as RM } from '../../../lib/motion';

/* ------------------------------------------------------------------ numbers */
export const n = (v: number) => Math.round(v).toLocaleString('en-GB');
/** Whole up to 99,999; from 100,000: 128K, 1.3M, 10.4M (the exact number goes in a tooltip). */
export const cn = (v: number) => {
    v = Math.round(v);
    if (v < 1e5) {
        return n(v);
    }
    if (v < 999500) {
        return Math.round(v / 1e3) + 'K';
    }
    const m = v / 1e6;

    return (m < 100 ? String(+m.toFixed(1)) : String(Math.round(m))) + 'M';
};
export const big = (v: number) => Math.round(v) >= 1e5;
/** A big number's exact value, on hover or focus. */
export const tipN = (v: number, what: string) => (big(v) ? { className: 'st-big', tabIndex: 0, 'data-tip': `${n(v)} ${what}` } : {});

/* ------------------------------------------------------------------ the chart: HTML text over a stretched SVG (text never squashes) */
export type Bucket = { lab: string; tip: string; ev?: string | null; y?: number };

function niceMax(v: number) {
    const steps = [10, 20, 25, 50, 100, 150, 200, 250, 300, 400, 500, 600, 750, 1000, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 7500, 10000];
    for (const m of steps) {
        if (v <= m * 0.92) {
            return m;
        }
    }
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    for (const k of [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10]) {
        if (v <= k * p * 0.92) {
            return k * p;
        }
    }

    return 10 * p;
}
function niceT(mx: number, h: number) {
    const ok = (v: number) => {
        const p = Math.pow(10, Math.floor(Math.log10(v)));

        return [1, 2, 2.5, 5].some((k) => Math.abs(v / p - k) < 1e-9);
    };
    for (const t of h < 180 ? [2, 3] : [4, 5, 3, 2]) {
        if (ok(mx / t)) {
            return t;
        }
    }

    return h < 180 ? 2 : 4;
}
/** Which points get a label under the chart. Months carry their year when it changes. */
export function xLabels(B: Bucket[]): [number, string][] {
    const N = B.length;
    if (N <= 7) {
        return B.map((b, i) => [i, b.lab]);
    }
    if (B[0].y === undefined) {
        return [0, 7, 14, 21, N - 1].filter((i) => i < N).map((i) => [i, B[i].lab]);
    }
    const step = Math.ceil(N / 7);
    const out: [number, string][] = [];
    for (let i = 0; i < N; i += step) {
        out.push([i, B[i].lab]);
    }
    if (out[out.length - 1][0] !== N - 1) {
        if (N - 1 - out[out.length - 1][0] < step / 2) {
            out.pop();
        }
        out.push([N - 1, B[N - 1].lab]);
    }
    let py: number | undefined;

    return out.map(([i, t]) => {
        const y = B[i].y;
        const l = y !== py ? `${t} ${y}` : t;
        py = y;

        return [i, l];
    });
}

type ChartProps = {
    vals: number[];
    B: Bucket[];
    labs?: [number, string][];
    h: number;
    cls?: string;
    unit: string;
    label: string;
    ymax?: number;
    ticks?: number;
    pct?: boolean;
    end?: string;
    /** changes when the line should ink in again */
    drawKey: string;
};

/** The line inks in from the left and the wash fades up (reduced motion: a fade). Hover, focus or arrows move a crosshair. */
export function Chart({ vals, B, labs, h, cls, unit, label, ymax, ticks, pct, end, drawKey }: ChartProps) {
    const box = useRef<HTMLDivElement>(null);
    const pl = useRef<HTMLDivElement>(null);
    const N = vals.length;
    const mx = ymax ?? niceMax(Math.max(...vals, 1));
    const T = ticks ?? niceT(mx, h);
    const xs = vals.map((_, i) => (N > 1 ? (i / (N - 1)) * 1000 : 500));
    const ys = vals.map((v) => 100 - (v / mx) * 100);
    const line = 'M' + xs.map((x, i) => `${x.toFixed(1)},${ys[i].toFixed(2)}`).join(' L');
    const area = line + ' L1000,100 L0,100 Z';
    const xl = labs ?? xLabels(B);
    const latest = useRef({ vals, B, unit, mx });
    latest.current = { vals, B, unit, mx };

    /* Draw */
    useEffect(() => {
        const el = box.current;
        if (!el) {
            return;
        }
        if (RM()) {
            el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

            return;
        }
        const ln = el.querySelector('.ln');
        const ar = el.querySelector('.ar');
        ln?.animate(
            [
                { strokeDasharray: '1 1', strokeDashoffset: 1 },
                { strokeDasharray: '1 1', strokeDashoffset: 0 },
            ],
            { duration: 900, easing: 'cubic-bezier(.22,1,.36,1)' },
        );
        ar?.animate([{ opacity: 0 }, { opacity: 0.1 }], { duration: 700, delay: 200, easing: 'ease', fill: 'backwards' });
        el.querySelectorAll('.pl>.dt:not(.hv), .el').forEach((x) =>
            x.animate(
                [
                    { opacity: 0, transform: 'scale(.4)' },
                    { opacity: 1, transform: 'none' },
                ],
                { duration: 320, delay: 760, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' },
            ),
        );
    }, [drawKey]);

    /* the crosshair finds the nearest point */
    useEffect(() => {
        const p = pl.current;
        if (!p) {
            return;
        }
        const cx = p.querySelector<HTMLElement>('.cx')!;
        const dt = p.querySelector<HTMLElement>('.dt.hv')!;
        const tip = p.querySelector<HTMLElement>('.tip')!;
        let cur = -1;
        let tw = 0;
        const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
        function show(i: number) {
            const { vals: V, B: bs, unit: u, mx: m } = latest.current;
            const len = V.length;
            cur = i;
            const x = len > 1 ? (i / (len - 1)) * 100 : 50;
            const y = (V[i] / m) * 100;
            cx.hidden = dt.hidden = tip.hidden = false;
            cx.style.left = dt.style.left = x + '%';
            dt.style.bottom = y + '%';
            tip.innerHTML = `<b>${n(V[i])} ${esc(u)}</b><span>${esc(bs[i]?.tip ?? '')}</span>${bs[i]?.ev ? `<small>${esc(bs[i].ev!)}</small>` : ''}`;
            const w = tip.offsetWidth;
            const pw = p!.offsetWidth;
            const left = Math.max(0, Math.min((x / 100) * pw - w / 2, pw - w));
            tip.style.transition = Math.abs(w - (tw || w)) > 1 ? 'opacity var(--d1) var(--ease-soft)' : '';
            tw = w;
            tip.style.left = left + 'px';
            tip.style.bottom = Math.min(y, 82) + '%';
            tip.classList.add('on');
        }
        function hide() {
            cur = -1;
            cx.hidden = dt.hidden = true;
            tip.classList.remove('on');
            tip.hidden = true;
        }
        const at = (e: PointerEvent) => {
            const r = p.getBoundingClientRect();
            const len = latest.current.vals.length;

            return Math.max(0, Math.min(len - 1, Math.round(((e.clientX - r.left) / r.width) * (len - 1))));
        };
        const move = (e: PointerEvent) => {
            const i = at(e);
            if (i !== cur) {
                show(i);
            }
        };
        const down = (e: PointerEvent) => show(at(e));
        const leave = (e: PointerEvent) => e.pointerType === 'mouse' && hide();
        const focus = () => show(latest.current.vals.length - 1);
        const key = (e: KeyboardEvent) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(e.key)) {
                return;
            }
            e.preventDefault();
            if (e.key === 'Escape') {
                hide();

                return;
            }
            const len = latest.current.vals.length;
            let i = cur < 0 ? len - 1 : cur;
            i = e.key === 'ArrowLeft' ? i - 1 : e.key === 'ArrowRight' ? i + 1 : e.key === 'Home' ? 0 : len - 1;
            show(Math.max(0, Math.min(len - 1, i)));
        };
        const outside = (e: PointerEvent) => {
            if (!p.contains(e.target as Node) && cur >= 0) {
                hide();
            }
        };
        p.addEventListener('pointermove', move);
        p.addEventListener('pointerdown', down);
        p.addEventListener('pointerleave', leave);
        p.addEventListener('focus', focus);
        p.addEventListener('blur', hide);
        p.addEventListener('keydown', key);
        document.addEventListener('pointerdown', outside, { capture: true });

        return () => {
            p.removeEventListener('pointermove', move);
            p.removeEventListener('pointerdown', down);
            p.removeEventListener('pointerleave', leave);
            p.removeEventListener('focus', focus);
            p.removeEventListener('blur', hide);
            p.removeEventListener('keydown', key);
            document.removeEventListener('pointerdown', outside, { capture: true });
        };
    }, []);

    const grid: ReactNode[] = [...Array(T + 1)].map((_, k) => (
        <span className="g" key={k} style={{ bottom: `${(k / T) * 100}%` }}>
            <i>{pct ? Math.round((mx * k) / T) + '%' : cn((mx * k) / T)}</i>
        </span>
    ));

    return (
        <div className={`st-ch${cls ? ' ' + cls : ''}`} style={{ ['--h' as string]: `${h}px` }} ref={box}>
            <div className="pl" tabIndex={0} role="img" aria-label={label} ref={pl}>
                {grid}
                <svg viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true">
                    <path className="ar" d={area} />
                    <path className="ln" d={line} pathLength={1} vectorEffect="non-scaling-stroke" />
                </svg>
                <span className="dt" style={{ left: `${xs[N - 1] / 10}%`, bottom: `${100 - ys[N - 1]}%` }} />
                {end && (
                    <span className="el" style={{ bottom: `${100 - ys[N - 1]}%` }}>
                        {end}
                    </span>
                )}
                <span className="cx" hidden />
                <span className="dt hv" hidden />
                <span className="tip" hidden role="status" />
            </div>
            <div className="xl">
                {xl.map(([i, t]) => (
                    <span key={i} style={{ left: `${xs[i] / 10}%` }} className={i === N - 1 ? 'e' : i === 0 ? 's' : ''}>
                        {t}
                    </span>
                ))}
            </div>
        </div>
    );
}

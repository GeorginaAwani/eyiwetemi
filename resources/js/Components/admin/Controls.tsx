import { KeyboardEvent, ReactNode, useEffect, useId, useRef, useState } from 'react';
import { inertWhen } from '../../lib/dom';
import { prefersReducedMotion } from '../../lib/motion';
import { Alert, Chevron, Plus, Saved as SavedIcon, Tick } from './Icons';

let dotsTimer = 0;

/** The page's loading dots: they bob only when a wait runs past 300 ms. */
export function loading(on: boolean): void {
    window.clearTimeout(dotsTimer);
    if (on) {
        dotsTimer = window.setTimeout(() => document.documentElement.classList.add('loading'), 300);
    } else {
        document.documentElement.classList.remove('loading');
    }
}

export const wait = (ms: number) => new Promise((r) => window.setTimeout(r, ms));

/**
 * Something that unfolds in place (an error, a confirm, a field): it opens its height, then lets its content
 * overflow (for listboxes) once it has settled.
 */
export function Unfold({ show, id, className = '', children }: { show: boolean; id?: string; className?: string; children: ReactNode }) {
    const [open, setOpen] = useState(show);

    useEffect(() => {
        if (!show) {
            setOpen(false);

            return;
        }
        const t = window.setTimeout(() => setOpen(true), prefersReducedMotion() ? 0 : 340);

        return () => window.clearTimeout(t);
    }, [show]);

    return (
        <div className={`k-unf${className ? ' ' + className : ''}${show ? ' show' : ''}${show && open ? ' open' : ''}`} id={id} {...inertWhen(!show)}>
            <div>{children}</div>
        </div>
    );
}

/** A field's error line, unfolding under it. */
export function FieldError({ message, children }: { message: string; children?: ReactNode }) {
    const [shown, setShown] = useState(message);
    useEffect(() => {
        if (message) {
            setShown(message);
        }
    }, [message]);

    return (
        <Unfold show={!!message}>
            <div className="k-err" role="alert">
                <p>
                    <Alert />
                    <span className="t">{shown}</span>
                </p>
                {children}
            </div>
        </Unfold>
    );
}

export type Option = { value: string; label: string; add?: boolean };

/**
 * Our select: a button and a listbox. Arrows, Home and End move; Enter or Space picks; Esc closes; a letter jumps.
 */
export function KSelect({
    value,
    options,
    onPick,
    labelledBy,
    className = '',
    id,
    buttonClassName = 'k-in k-sbtn',
    listClassName = '',
    label,
    display,
}: {
    value: string;
    options: Option[];
    onPick: (v: string) => void;
    labelledBy?: string;
    className?: string;
    id?: string;
    buttonClassName?: string;
    listClassName?: string;
    /** an aria-label, when no visible label names the field */
    label?: string;
    /** what the button shows, when not the picked option's name and a chevron */
    display?: ReactNode;
}) {
    const uid = useId().replace(/:/g, '');
    const base = id ?? 'ks' + uid;
    const root = useRef<HTMLDivElement>(null);
    const list = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [act, setAct] = useState(0);
    const current = Math.max(0, options.findIndex((o) => o.value === value));

    useEffect(() => {
        if (!open) {
            return;
        }
        const outside = (e: MouseEvent) => {
            if (!root.current?.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('click', outside);

        return () => document.removeEventListener('click', outside);
    }, [open]);

    /* keep the active option in view; centre the picked one when the list opens */
    useEffect(() => {
        const lb = list.current;
        const o = lb?.children[act] as HTMLElement | undefined;
        if (!open || !lb || !o || lb.scrollHeight <= lb.clientHeight) {
            return;
        }
        if (o.offsetTop < lb.scrollTop + 6) {
            lb.scrollTop = o.offsetTop - 6;
        } else if (o.offsetTop + o.offsetHeight > lb.scrollTop + lb.clientHeight - 6) {
            lb.scrollTop = o.offsetTop + o.offsetHeight - lb.clientHeight + 6;
        }
    }, [act, open]);

    function show(on: boolean, focusList = true) {
        if (on) {
            setAct(current);
            window.setTimeout(() => {
                const lb = list.current;
                const o = lb?.children[current] as HTMLElement | undefined;
                if (lb && o && lb.scrollHeight > lb.clientHeight) {
                    lb.scrollTop = o.offsetTop - (lb.clientHeight - o.offsetHeight) / 2;
                }
                if (focusList) {
                    lb?.focus({ preventScroll: true });
                }
            }, 0);
        }
        setOpen(on);
    }

    function pick(i: number) {
        const o = options[i];
        show(false);
        button.current?.focus();
        if (o && o.value !== value) {
            onPick(o.value);
        } else if (o?.add) {
            onPick(o.value);
        }
    }

    function key(e: KeyboardEvent) {
        const k = e.key;
        const n = options.length;
        if (k === 'ArrowDown') {
            e.preventDefault();
            setAct((act + 1) % n);
        } else if (k === 'ArrowUp') {
            e.preventDefault();
            setAct((act + n - 1) % n);
        } else if (k === 'Home') {
            e.preventDefault();
            setAct(0);
        } else if (k === 'End') {
            e.preventDefault();
            setAct(n - 1);
        } else if (k === 'Enter' || k === ' ') {
            e.preventDefault();
            pick(act);
        } else if (k === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            show(false);
            button.current?.focus();
        } else if (k === 'Tab') {
            show(false);
        } else if (k.length === 1) {
            const m = (o: Option) => o.label.trim().toLowerCase().split(/\s+/).some((w) => w.startsWith(k.toLowerCase()));
            const after = options.findIndex((o, i) => i > act && m(o));
            const j = after >= 0 ? after : options.findIndex(m);
            if (j >= 0) {
                setAct(j);
            }
        }
    }

    return (
        <div className={`k-select${className ? ' ' + className : ''}${open ? ' open' : ''}`} ref={root} data-value={value}>
            <button
                ref={button}
                type="button"
                className={buttonClassName}
                id={base}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={base + '-lb'}
                aria-labelledby={labelledBy ? `${labelledBy} ${base}` : undefined}
                aria-label={label}
                onClick={(e) => {
                    e.stopPropagation();
                    show(!open);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        show(true);
                    }
                }}
            >
                {display ?? (
                    <>
                        <span className="v">{options[current]?.label}</span>
                        <Chevron />
                    </>
                )}
            </button>
            <div
                className={`k-lb${listClassName ? ' ' + listClassName : ''}`}
                role="listbox"
                id={base + '-lb'}
                tabIndex={-1}
                aria-labelledby={labelledBy}
                aria-label={label}
                aria-activedescendant={open ? `${base}-o${act}` : undefined}
                ref={list}
                onKeyDown={key}
                onBlur={(e) => {
                    if (!root.current?.contains(e.relatedTarget as Node)) {
                        window.setTimeout(() => {
                            if (!root.current?.contains(document.activeElement)) {
                                setOpen(false);
                            }
                        }, 0);
                    }
                }}
            >
                {options.map((o, i) => (
                    <div
                        key={o.value}
                        role="option"
                        id={`${base}-o${i}`}
                        aria-selected={i === current}
                        className={`${o.add ? 'add' : ''}${i === act && open ? ' act' : ''}`.trim() || undefined}
                        onMouseMove={() => act !== i && setAct(i)}
                        onClick={(e) => {
                            e.stopPropagation();
                            pick(i);
                        }}
                    >
                        <span>
                            {o.add && <Plus />}
                            {o.label}
                        </span>
                        <Tick />
                    </div>
                ))}
            </div>
        </div>
    );
}

export type SaveState = 'saved' | 'saving' | 'bad';

/** "Saving…", "Saved", or "Not saved" while a field is wrong. */
export function SavedNote({ state, hidden }: { state: SaveState; hidden?: boolean }) {
    return (
        <span className="k-saved" id="sv" aria-live="polite" data-s={state} style={{ visibility: hidden ? 'hidden' : 'visible' }}>
            <span className="i">{state === 'bad' ? <Alert /> : <SavedIcon />}</span>
            <span className="t">{state === 'bad' ? 'Not saved' : state === 'saving' ? 'Saving…' : 'Saved'}</span>
        </span>
    );
}

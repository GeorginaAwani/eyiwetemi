import { FormEvent, KeyboardEvent, ReactNode, useEffect, useRef, useState } from 'react';
import { inertWhen } from '../../lib/dom';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';
import { bloomStyle } from '../site/Bloom';
import { FieldError } from './Controls';
import Cover, { CoverData } from './Cover';
import { Ring } from './Icons';

const line = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
const ic = (size: number, width: number, d: ReactNode) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth={width} aria-hidden="true">
        {d}
    </svg>
);

/** The icons of the Comments and Reviewers pages (the prototype's set). */
export const PI = {
    heart: ic(17, 1.8, <path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z" />),
    eyeoff: (s = 17) =>
        ic(
            s,
            1.8,
            <>
                <path d="M3 3l18 18" />
                <path d="M10.6 6.1A9.8 9.8 0 0 1 12 6c5 0 8.5 4.4 9.5 6-.4.7-1.3 1.9-2.6 3.1M6.4 7.6C4.5 8.9 3.1 10.8 2.5 12c1 1.6 4.5 6 9.5 6 1.6 0 3-.4 4.3-1" />
                <path d="M9.9 10a3 3 0 0 0 4.2 4.1" />
            </>,
        ),
    eye: ic(
        17,
        1.8,
        <>
            <path d="M2.5 12C3.5 10.4 7 6 12 6s8.5 4.4 9.5 6c-1 1.6-4.5 6-9.5 6s-8.5-4.4-9.5-6z" />
            <circle cx="12" cy="12" r="3" />
        </>,
    ),
    send: ic(19, 1.8, <path d="M5 12h13M13 6l6 6-6 6" />),
    ext: ic(15, 1.8, <path d="M8 16 16 8M9 8h7v7" />),
    reply: ic(
        17,
        1.8,
        <>
            <path d="M9 7 4 12l5 5" />
            <path d="M4 12h10a6 6 0 0 1 6 6" />
        </>,
    ),
    check: (s = 17) => ic(s, 1.8, <path d="m5 12.5 4.5 4.5L19 7.5" />),
    x: (s = 17, w = 1.8) => ic(s, w, <path d="M6 6l12 12M18 6 6 18" />),
    pen: ic(16, 1.8, <path d="M4 20h4L19 9l-4-4L4 16z" />),
    cloud: ic(
        15,
        1.8,
        <>
            <path d="M3 3l18 18" />
            <path d="M7 18h10.5a3.5 3.5 0 0 0 1.2-6.8A6 6 0 0 0 8.7 7.3M5.2 9.8A4.2 4.2 0 0 0 7 18" />
        </>,
    ),
    mail: ic(
        17,
        1.8,
        <>
            <rect x="3" y="5.5" width="18" height="13" rx="2" />
            <path d="m3.5 7 8.5 6 8.5-6" />
        </>,
    ),
    undo: ic(
        17,
        1.8,
        <>
            <path d="M9 7 4 12l5 5" />
            <path d="M4 12h11a5 5 0 0 1 0 10h-2" />
        </>,
    ),
    alert: ic(
        16,
        2,
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7.5v5.5M12 16.5v.01" />
        </>,
    ),
    chev: (s = 18) => ic(s, 1.8, <path d="m6 9 6 6 6-6" />),
    plus: (s = 17, w = 1.8) => ic(s, w, <path d="M12 5v14M5 12h14" />),
    chat: (s = 15) => ic(s, 1.8, <path d="M5 18.5V6.5A1.5 1.5 0 0 1 6.5 5h11A1.5 1.5 0 0 1 19 6.5v8a1.5 1.5 0 0 1-1.5 1.5H9z" />),
    search: ic(
        18,
        1.8,
        <>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </>,
    ),
    tick: (s = 14, w = 2.6) => ic(s, w, <path d="m5 12.5 4.5 4.5L19 7" />),
    globe: ic(
        15,
        1.8,
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
        </>,
    ),
    people: ic(
        15,
        1.8,
        <>
            <circle cx="9" cy="8" r="3.2" />
            <path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M15.5 5.2a3 3 0 0 1 0 5.6M17.5 14.3c1.7.6 2.7 2.2 3 4.7" />
        </>,
    ),
    lock: ic(
        15,
        1.8,
        <>
            <rect x="5" y="11" width="14" height="9" rx="2" />
            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
        </>,
    ),
};

/** A profile photo: the reader's Bloom, or hers (her photo with the rose ring). */
export function Av({ seed, size, photo, me = false }: { seed: string; size: number; photo?: string | null; me?: boolean }) {
    if (me) {
        return <span className="cv me" style={{ width: size, height: size, ...(photo ? { backgroundImage: `url(${photo})` } : bloomStyle(seed, size)) }} aria-hidden="true" />;
    }

    return <span className="cv" style={{ ...bloomStyle(seed, size), width: size, height: size }} aria-hidden="true" />;
}

export type WorkChoice = { slug: string; title: string; sub: string; visibility: 'public' | 'reviewers' | 'private'; cover: CoverData };

const VIS: Record<WorkChoice['visibility'], [ReactNode, string]> = { public: [PI.globe, 'Public'], reviewers: [PI.people, 'Reviewers'], private: [PI.lock, 'Private'] };

/**
 * "Works they can see": a checkbox row per work. A private work shows it will open to reviewers once ticked.
 */
export function WorkPicks({ works, picked, onToggle, labelledBy, className = '', filter = '' }: { works: WorkChoice[]; picked: Set<string>; onToggle: (slug: string, on: boolean) => void; labelledBy: string; className?: string; filter?: string }) {
    const f = filter.trim().toLowerCase();

    return (
        <div className={`cm-wks ${className}`.trim()} role="group" aria-labelledby={labelledBy}>
            {works.map((w) => {
                const on = picked.has(w.slug);
                const vis = w.visibility === 'private' && on ? 'reviewers' : w.visibility;

                return (
                    <label key={w.slug} className={`ad-cb cm-wk${on ? ' on' : ''}`} hidden={!!f && !w.title.toLowerCase().includes(f)}>
                        <input type="checkbox" checked={on} onChange={(e) => onToggle(w.slug, e.target.checked)} />
                        <span className="ad-box">{PI.tick()}</span>
                        <Cover cover={w.cover} title={w.title} style={{ width: 24, height: 36 }} />
                        <span className="nm">
                            <b>{w.title}</b>
                            <span>{w.sub}</span>
                        </span>
                        <span className="vi pp-vi" key={vis} title={VIS[vis][1]}>
                            {VIS[vis][0]}
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

export type Person = {
    key: string;
    kind: 'user' | 'invite';
    id: number;
    name: string;
    email: string;
    seed: string;
    works: string[];
    comments: number;
    last: string;
    state: 'on' | 'invited' | 'expired';
    days: number | null;
    note: string | null;
};

const OPEN = 'admin:invite';
export const INVITED = 'admin:invited';

/** Open the Invite a reviewer dialog, optionally with an email and works already filled in. */
export const openInvite = (pre: { email?: string; works?: string[] } = {}) => window.dispatchEvent(new CustomEvent(OPEN, { detail: pre }));

const EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

/**
 * Invite a reviewer: name, email, the works they can see and an optional note. Sent, it says so and offers another.
 */
export function InviteDialog() {
    const [on, setOn] = useState(false);
    const [works, setWorks] = useState<WorkChoice[]>([]);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [note, setNote] = useState('');
    const [picked, setPicked] = useState<Set<string>>(new Set());
    const [find, setFind] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState('');
    const dialog = useRef<HTMLDivElement>(null);
    const nameInput = useRef<HTMLInputElement>(null);
    const emailBox = useRef<HTMLSpanElement>(null);
    const lastFocus = useRef<HTMLElement | null>(null);

    function reset(pre: { email?: string; works?: string[] }) {
        setName('');
        setEmail(pre.email ?? '');
        setNote('');
        setPicked(new Set(pre.works ?? []));
        setFind('');
        setError('');
        setSent('');
        window.setTimeout(() => nameInput.current?.focus(), prefersReducedMotion() ? 0 : 200);
    }

    useEffect(() => {
        const open = async (e: Event) => {
            lastFocus.current = document.activeElement as HTMLElement;
            reset((e as CustomEvent).detail ?? {});
            setOn(true);
            const { data } = await json<{ works: WorkChoice[] }>('GET', '/reviewers/works');
            setWorks(data.works ?? []);
        };
        window.addEventListener(OPEN, open);

        return () => window.removeEventListener(OPEN, open);
    }, []);

    function close() {
        setOn(false);
        lastFocus.current?.focus?.();
    }

    useEffect(() => {
        if (!on) {
            return;
        }
        const esc = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape' && !e.defaultPrevented) {
                close();
            }
        };
        document.addEventListener('keydown', esc);

        return () => document.removeEventListener('keydown', esc);
    }, [on]);

    function trap(e: KeyboardEvent) {
        if (e.key !== 'Tab' || !dialog.current) {
            return;
        }
        const f = [...dialog.current.querySelectorAll<HTMLElement>('button:not(:disabled),input,textarea,a[href]')].filter((x) => x.offsetParent && !x.closest('[inert],[hidden]'));
        if (e.shiftKey && document.activeElement === f[0]) {
            e.preventDefault();
            f[f.length - 1].focus();
        } else if (!e.shiftKey && document.activeElement === f[f.length - 1]) {
            e.preventDefault();
            f[0].focus();
        }
    }

    const ready = name.trim() !== '' && EMAIL.test(email.trim()) && picked.size > 0 && !busy;

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!ready) {
            return;
        }
        setBusy(true);
        const { status, data } = await json<{ person: Person; errors: Record<string, string[]>; message: string }>('POST', '/reviewers/invites', {
            name: name.trim(),
            email: email.trim(),
            works: [...picked],
            note: note.trim() || null,
        });
        setBusy(false);
        if (status === 201 && data.person) {
            window.dispatchEvent(new CustomEvent(INVITED, { detail: data.person }));
            setSent(name.trim().replace(/\s+/g, ' ').split(' ')[0]);
            window.setTimeout(() => dialog.current?.querySelector<HTMLElement>('[data-done]')?.focus(), 0);

            return;
        }
        const errors = data.errors ?? {};
        setError(errors.email?.[0] ?? Object.values(errors)[0]?.[0] ?? data.message ?? 'That didn’t send. Try again.');
        if (!prefersReducedMotion()) {
            emailBox.current?.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(5px)' }, { transform: 'translateX(0)' }], { duration: 320 });
        }
    }

    return (
        <>
            <div className={`k-scrim${on ? ' on' : ''}`} onClick={close} />
            <div className={`b-dlg pp-dlg${on ? ' on' : ''}`} id="ppIv" role="dialog" aria-modal="true" aria-labelledby="ppIvH" ref={dialog} onKeyDown={trap} {...inertWhen(!on)}>
                <button className="ad-ic x" type="button" aria-label="Close" onClick={close}>
                    {PI.x(20)}
                </button>
                {sent ? (
                    <div className="pp-done" key="sent">
                        <h2 id="ppIvH">Invite sent</h2>
                        <p className="cm-ok">
                            {PI.tick(20, 2)}
                            <span>{sent} gets an email now. You’ll see them as Invited until they join.</span>
                        </p>
                        <div className="acts">
                            <button className="k-btn" type="button" data-done onClick={close}>
                                <span>Done</span>
                            </button>
                            <button className="w-act" type="button" onClick={() => reset({})}>
                                Invite someone else
                            </button>
                        </div>
                    </div>
                ) : (
                    <>
                        <h2 id="ppIvH">Invite a reviewer</h2>
                        <form onSubmit={submit} noValidate>
                            <div className="k-f">
                                <label className="k-fl" htmlFor="ppIvM">
                                    Name
                                </label>
                                <span className="k-in">
                                    <input id="ppIvM" ref={nameInput} autoComplete="off" spellCheck={false} value={name} onChange={(e) => setName(e.target.value)} />
                                </span>
                            </div>
                            <div className="k-f">
                                <label className="k-fl" htmlFor="ppIvE">
                                    Email
                                </label>
                                <span className={`k-in${error ? ' bad' : ''}`} ref={emailBox}>
                                    <input
                                        id="ppIvE"
                                        type="email"
                                        autoComplete="off"
                                        spellCheck={false}
                                        value={email}
                                        onChange={(e) => {
                                            setEmail(e.target.value);
                                            setError('');
                                        }}
                                    />
                                </span>
                                <FieldError message={error} />
                            </div>
                            <div className="k-f">
                                <span className="k-fl" id="ppIvWL">
                                    Works they can see
                                </span>
                                {works.length > 8 && (
                                    <label className="k-search pp-wq" htmlFor="ppIvQ">
                                        {PI.search}
                                        <input id="ppIvQ" type="search" placeholder="Find a work" autoComplete="off" value={find} onChange={(e) => setFind(e.target.value)} />
                                    </label>
                                )}
                                <WorkPicks
                                    works={works}
                                    picked={picked}
                                    filter={find}
                                    labelledBy="ppIvWL"
                                    className="dlg"
                                    onToggle={(slug, add) => {
                                        const next = new Set(picked);
                                        if (add) {
                                            next.add(slug);
                                        } else {
                                            next.delete(slug);
                                        }
                                        setPicked(next);
                                    }}
                                />
                            </div>
                            <div className="k-f">
                                <label className="k-fl" htmlFor="ppIvN">
                                    A note
                                </label>
                                <span className="k-in ta">
                                    <textarea
                                        id="ppIvN"
                                        rows={1}
                                        placeholder="Optional"
                                        value={note}
                                        onChange={(e) => {
                                            setNote(e.target.value);
                                            e.target.style.height = 'auto';
                                            e.target.style.height = e.target.scrollHeight + 'px';
                                        }}
                                    />
                                </span>
                            </div>
                            <div className="acts">
                                <button className={`k-btn${busy ? ' busy' : ''}`} type="submit" disabled={!ready}>
                                    <span>Send invite</span>
                                    <Ring />
                                </button>
                                <button className="w-act" type="button" onClick={close}>
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </>
                )}
            </div>
        </>
    );
}

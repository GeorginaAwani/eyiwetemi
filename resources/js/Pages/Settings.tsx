import { Link, router } from '@inertiajs/react';
import { FormEvent, MouseEvent, ReactNode, useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import Bloom from '@/Components/site/Bloom';
import SendButton, { SendState } from '@/Components/site/SendButton';
import SiteLayout from '@/Layouts/SiteLayout';
import { inertWhen } from '@/lib/dom';
import { fieldErrors, json } from '@/lib/http';
import { forgetReading } from '@/Components/site/Offline';
import { currentTheme, prefersReducedMotion, setThemeFrom, subscribeTheme, THEMES, Theme } from '@/lib/motion';

type Account = {
    name: string;
    username: string;
    email: string;
    seed: string;
    google: boolean;
    hasPassword: boolean;
    pendingEmail: string | null;
    lockedUntil: string | null;
    comments: number;
};
type Reading = { size: number; font: 'serif' | 'sans'; width: 'narrow' | 'wide'; lines: 'close' | 'airy'; theme: Theme };
type Props = { account: Account; notifications: Record<string, boolean>; reading: Reading; status: string | null };
type Help = { node: ReactNode; bad?: boolean } | null;

const ALERT = (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7.5v5.5M12 16.4v.1" />
    </svg>
);
const TICK = (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);
const ARC = (
    <svg className="au-arc" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="9" pathLength="100" />
    </svg>
);
const EYE = (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
        <circle cx="12" cy="12" r="3" />
    </svg>
);
const EYE_OFF = (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-2.6 3.5M6.6 6.6C3.7 8.5 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6" />
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18" />
    </svg>
);
const G = (
    <svg width="16" height="16" viewBox="0 0 48 48" aria-hidden="true">
        <path
            fill="#FFC107"
            d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"
        />
        <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
    </svg>
);
const SHUFFLE = (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5" />
    </svg>
);

const bad = (text: ReactNode): Help => ({
    bad: true,
    node: (
        <>
            <span className="au-bi">{ALERT}</span>
            {text}
        </>
    ),
});
const later = (days = 30) => new Date(Date.now() + days * 864e5).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });

/** A field inside an edit row. */
function EditField({
    name,
    label,
    type = 'text',
    value,
    onChange,
    help,
    at,
    status,
    placeholder = '',
}: {
    name: string;
    label?: string;
    type?: 'text' | 'email' | 'password';
    value: string;
    onChange: (v: string) => void;
    help: Help;
    at?: boolean;
    status?: 'idle' | 'check' | 'ok';
    placeholder?: string;
}) {
    const [show, setShow] = useState(false);

    return (
        <div className="pf-ef">
            {label && <span className="pf-el">{label}</span>}
            <label className={`pf-ein${help?.bad ? ' bad' : ''}`}>
                {at && <span className="pf-at">@</span>}
                <input
                    name={name}
                    type={type === 'password' && show ? 'text' : type}
                    value={value}
                    placeholder={placeholder}
                    aria-label={label}
                    autoCapitalize={at || type === 'email' ? 'none' : undefined}
                    spellCheck={at || type === 'email' ? false : undefined}
                    autoComplete={type === 'password' ? (name === 'cur' ? 'current-password' : 'new-password') : 'off'}
                    onChange={(e) => onChange(e.target.value)}
                />
                {at && <span className="pf-st2">{status === 'ok' ? TICK : status === 'check' ? ARC : null}</span>}
                {type === 'password' && (
                    <button type="button" className={`pf-eye${show ? ' on' : ''}`} aria-label={show ? 'Hide password' : 'Show password'} onClick={() => setShow((s) => !s)}>
                        <span className="e1">{EYE}</span>
                        <span className="e2">{EYE_OFF}</span>
                    </button>
                )}
            </label>
            <div className={`pf-eh${help?.bad ? ' bad' : ''}`}>{help?.node}</div>
        </div>
    );
}

/** A row that opens in place: the value folds away, the form unfolds. */
function EditRow({
    k,
    label,
    open,
    onOpen,
    onClose,
    action,
    locked,
    view,
    form,
    onSubmit,
    sending,
    saveLabel = 'Save',
    extra,
    rowClass = '',
}: {
    k: string;
    label: string;
    open: boolean;
    onOpen: () => void;
    onClose: () => void;
    action: string;
    locked?: boolean;
    view: ReactNode;
    form: ReactNode;
    onSubmit: (e: FormEvent) => void;
    sending: SendState;
    saveLabel?: string;
    extra?: ReactNode;
    rowClass?: string;
}) {
    const row = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open) {
            return;
        }
        const t = window.setTimeout(() => row.current?.querySelector<HTMLInputElement>('.pf-edw input:not([hidden])')?.focus({ preventScroll: true }), prefersReducedMotion() ? 0 : 160);

        return () => window.clearTimeout(t);
    }, [open]);

    return (
        <div
            ref={row}
            className={`pf-srow pf-er${open ? ' editing' : ''}${locked ? ' locked' : ''} ${rowClass}`.trim()}
            data-k={k}
            onKeyDown={(e) => {
                if (e.key === 'Escape' && open) {
                    e.stopPropagation();
                    onClose();
                }
            }}
        >
            <span className="pf-sl">{label}</span>
            <div className="pf-sv2">
                <div className={`pf-cl pf-vw${open ? '' : ' on'}`} {...inertWhen(open)}>
                    <div>{view}</div>
                </div>
                <div className={`pf-cl pf-edw${open ? ' on' : ''}`} {...inertWhen(!open)}>
                    <div>
                        <form className="pf-ebody" noValidate onSubmit={onSubmit}>
                            {form}
                            <div className="pf-eacts">
                                <SendButton className="btn solid pf-esave" type="submit" state={sending}>
                                    {saveLabel}
                                </SendButton>
                                <button type="button" className="pf-ecancel" onClick={onClose}>
                                    Cancel
                                </button>
                                {extra}
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <button type="button" className="pf-sa" aria-disabled={locked || undefined} onClick={() => !locked && onOpen()}>
                {action}
            </button>
        </div>
    );
}

/** "Saved" with a tick, for a moment, beside the value. */
function useSaved(): [boolean, () => void] {
    const [on, setOn] = useState(false);
    const t = useRef<number>();

    return [
        on,
        () => {
            setOn(true);
            window.clearTimeout(t.current);
            t.current = window.setTimeout(() => setOn(false), 2600);
        },
    ];
}
const Saved = ({ on }: { on: boolean }) => (
    <span className={`pf-saved${on ? ' show' : ''}`} aria-live="polite">
        {TICK}
        {on ? 'Saved' : ''}
    </span>
);

/** Your Bloom: Shuffle opens a new one in the same place, over the old one, which fades. */
function Photo({ seed, onShuffle }: { seed: string; onShuffle: () => void }) {
    const [layers, setLayers] = useState([{ seed, key: 0 }]);
    const n = useRef(0);
    useEffect(() => {
        setLayers((all) => (all[all.length - 1].seed === seed ? all : [...all.slice(-1), { seed, key: ++n.current }]));
    }, [seed]);
    const box = useRef<HTMLSpanElement>(null);
    useEffect(() => {
        if (layers.length < 2 || !box.current) {
            return;
        }
        const [oldEl, newEl] = [...box.current.children] as HTMLElement[];
        const still = prefersReducedMotion();
        const ease = 'cubic-bezier(.22,1,.36,1)';
        newEl.animate(
            still
                ? [{ opacity: 0 }, { opacity: 1 }]
                : [
                      { opacity: 0, transform: 'scale(.55)', filter: 'blur(6px)' },
                      { opacity: 1, transform: 'none', filter: 'blur(0)' },
                  ],
            { duration: still ? 300 : 640, easing: ease },
        ).onfinish = () => setLayers((all) => all.slice(-1));
        oldEl.animate([{ opacity: 1 }, { opacity: 0 }], { duration: still ? 300 : 460, easing: ease, fill: 'forwards' });
    }, [layers]);

    return (
        <span className="pf-sv">
            <span ref={box} style={{ display: 'grid', width: 56, height: 56 }}>
                {layers.map((l) => (
                    <span key={l.key} style={{ gridArea: '1/1' }}>
                        <Bloom seed={l.seed} size={56} />
                    </span>
                ))}
            </span>
            <button type="button" className="pf-shuf" onClick={onShuffle}>
                {SHUFFLE}
                Shuffle
            </button>
        </span>
    );
}

const useTheme = () => useSyncExternalStore(subscribeTheme, currentTheme, () => 'white' as const);

/** Delete: the same card as Sign in. Type your username to confirm. */
function DeleteCard({ open, onClose, username, comments }: { open: boolean; onClose: () => void; username: string; comments: number }) {
    const [typed, setTyped] = useState('');
    const [state, setState] = useState<SendState>('idle');
    const [help, setHelp] = useState<string | null>(null);
    const card = useRef<HTMLDivElement>(null);
    const last = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        last.current = document.activeElement as HTMLElement;
        setTyped('');
        setHelp(null);
        const t = window.setTimeout(() => card.current?.querySelector('input')?.focus({ preventScroll: true }), prefersReducedMotion() ? 0 : 220);
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);

        return () => {
            window.clearTimeout(t);
            document.removeEventListener('keydown', onKey);
            last.current?.focus({ preventScroll: true });
        };
    }, [open, onClose]);

    const match = typed.trim().toLowerCase() === username;
    const go = async () => {
        if (!match || state !== 'idle') {
            return;
        }
        setState('sending');
        const { status, data } = await json<{ redirect: string }>('DELETE', '/settings/account', { username: typed });
        if (status === 200) {
            setState('sent');
            /* the chapters kept for offline reading go with the account */
            forgetReading();
            window.setTimeout(() => window.location.assign(data.redirect ?? '/'), 300);

            return;
        }
        setState('idle');
        setHelp(fieldErrors(data).username ?? 'Something went wrong. Try again.');
    };

    return (
        <div className={`au-layer${open ? ' open' : ''}`} aria-hidden={!open} onMouseDown={(e) => e.target === e.currentTarget && onClose()} {...inertWhen(!open)}>
            <div ref={card} className={`au-card a del${open ? ' settle-in' : ''}`} role="dialog" aria-modal="true" aria-labelledby="delT">
                <button className="au-x" type="button" aria-label="Close" onClick={onClose}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
                <div className="au-st">
                    <h2 id="delT">Delete your account?</h2>
                    <p>Your likes, follows and {comments === 1 ? '1 comment' : `${comments} comments`} are deleted. Replies from others stay, under “Comment deleted”.</p>
                    <div className="au-f">
                        <label className="au-l" htmlFor="delIn">
                            Type <b style={{ color: 'var(--ink)' }}>{username}</b> to confirm
                        </label>
                        <div className={`au-in${help ? ' bad' : ''}`}>
                            <input id="delIn" type="text" autoComplete="off" autoCapitalize="none" spellCheck={false} value={typed} onChange={(e) => setTyped(e.target.value)} />
                        </div>
                        {help && <div className="au-help bad">{bad(help)?.node}</div>}
                    </div>
                    <div className="au-mb">
                        <button type="button" className="btn line" onClick={onClose}>
                            Cancel
                        </button>
                        <SendButton type="button" className="pf-danger solid" state={state} disabled={!match} onClick={go}>
                            Delete account
                        </SendButton>
                    </div>
                </div>
            </div>
        </div>
    );
}

const NOTIFY: { key: string; label: string }[] = [
    { key: 'chapters', label: 'New chapters in novels I follow' },
    { key: 'notes', label: 'Notes on works I follow' },
    { key: 'replies', label: 'Replies to my comments' },
    { key: 'new', label: 'New work I haven’t followed yet' },
];
const SEGS: { k: 'size' | 'font' | 'width' | 'lines'; label: string; options: [string | number, string][] }[] = [
    {
        k: 'size',
        label: 'Text size',
        options: [
            [1, 'S'],
            [2, 'M'],
            [3, 'L'],
            [4, 'XL'],
        ],
    },
    {
        k: 'font',
        label: 'Font',
        options: [
            ['serif', 'Serif'],
            ['sans', 'Sans'],
        ],
    },
    {
        k: 'width',
        label: 'Line width',
        options: [
            ['narrow', 'Narrow'],
            ['wide', 'Wide'],
        ],
    },
    {
        k: 'lines',
        label: 'Spacing',
        options: [
            ['close', 'Close'],
            ['airy', 'Airy'],
        ],
    },
];

/** Settings: your account, notifications, how chapters read, and deleting the account. */
export default function Settings({ account, notifications, reading, status }: Props) {
    const [me, setMe] = useState(account);
    const [editing, setEditing] = useState<string | null>(null);
    const [sending, setSending] = useState<string | null>(null);
    const [help, setHelpState] = useState<Record<string, Help>>({});
    const [f, setF] = useState({ name: '', user: '', email: '', pw: '', cur: '', new: '' });
    const [notif, setNotif] = useState(notifications);
    const [read, setRead] = useState(reading);
    const [del, setDel] = useState(false);
    const closeDel = useCallback(() => setDel(false), []);
    const [userCheck, setUserCheck] = useState<'idle' | 'check' | 'ok'>('idle');
    const [toc, setToc] = useState(0);
    const [again, setAgain] = useState<string | null>(null);
    const theme = useTheme();
    const checkSeq = useRef(0);
    const checkTimer = useRef<number>();
    const [nameSaved, flashName] = useSaved();
    const [userSaved, flashUser] = useSaved();
    const [pwSaved, flashPw] = useSaved();

    const setHelp = (k: string, h: Help) => setHelpState((all) => ({ ...all, [k]: h }));
    const set = (k: keyof typeof f, v: string) => setF((all) => ({ ...all, [k]: v }));

    const base: Record<string, Help> = {
        name: { node: 'Shown with your comments.' },
        pw: {
            node: (
                <>
                    So we know it’s you.{' '}
                    <a className="pf-sug pf-fg" onClick={() => forgot('pw')}>
                        Forgot it?
                    </a>
                </>
            ),
        },
        new: { node: 'At least 8 characters.' },
    };

    const open = (k: string) => {
        setEditing(k);
        setF({ name: k === 'name' ? me.name : '', user: k === 'user' ? me.username : '', email: '', pw: '', cur: '', new: '' });
        setHelpState({});
        setUserCheck('idle');
    };
    const close = () => setEditing(null);

    /* the reading defaults this device uses, so the Reader opens the way you set it */
    useEffect(() => {
        try {
            const local = JSON.parse(localStorage.getItem('eyiRead') || '{}');
            const { theme: _t, ...rest } = read;
            localStorage.setItem('eyiRead', JSON.stringify({ ...local, ...rest }));
        } catch {
            /* private mode */
        }
    }, [read]);

    /* contents: follows where you are */
    useEffect(() => {
        const ids = ['account', 'notifications', 'reading'];
        const spy = () => {
            let k = 0;
            ids.forEach((id, i) => {
                const el = document.getElementById(id);
                if (el && el.getBoundingClientRect().top < innerHeight * 0.35) {
                    k = i;
                }
            });
            if (innerHeight + scrollY >= document.documentElement.scrollHeight - 4) {
                k = ids.length - 1;
            }
            setToc(k);
        };
        spy();
        addEventListener('scroll', spy, { passive: true });

        return () => removeEventListener('scroll', spy);
    }, []);

    const failed = (status: number) => bad(status === 429 ? 'Too many tries. Wait a minute.' : 'Something went wrong. Try again.');

    /* ---- name ---- */
    const saveName = async (e: FormEvent) => {
        e.preventDefault();
        const v = f.name.trim();
        if (!v) {
            return setHelp('name', bad('Type your name.'));
        }
        if (v === me.name) {
            return close();
        }
        setSending('name');
        const { status, data } = await json<{ name: string }>('PATCH', '/settings/name', { name: v });
        setSending(null);
        if (status !== 200) {
            return setHelp('name', fieldErrors(data).name ? bad(fieldErrors(data).name) : failed(status));
        }
        setMe((m) => ({ ...m, name: data.name ?? v }));
        close();
        flashName();
        router.reload({ only: ['auth'] });
    };

    /* ---- username: checked as you type ---- */
    const checkUser = useCallback(
        (v: string) => {
            window.clearTimeout(checkTimer.current);
            const id = ++checkSeq.current;
            const u = v.trim().toLowerCase();
            if (!u) {
                setUserCheck('idle');
                return setHelp('user', null);
            }
            if (u === me.username) {
                setUserCheck('idle');
                return setHelp('user', { node: 'That’s your username now.' });
            }
            setUserCheck('check');
            setHelp('user', { node: 'Checking…' });
            checkTimer.current = window.setTimeout(async () => {
                const { status, data } = await json<{ ok: boolean; message: string; suggestions: string[] }>('GET', `/username-check?${new URLSearchParams({ u, name: me.name })}`);
                if (id !== checkSeq.current) {
                    return;
                }
                if (status !== 200) {
                    setUserCheck('idle');
                    return setHelp('user', failed(status));
                }
                if (data.ok) {
                    setUserCheck('ok');
                    return setHelp('user', {
                        node: (
                            <>
                                <span className="pf-okt">@{u} is free.</span> Your next change can be on {later()}. @{me.username} stays held for you for 30 days, and old @mentions follow you.
                            </>
                        ),
                    });
                }
                setUserCheck('idle');
                setHelp(
                    'user',
                    bad(
                        <>
                            {data.message}
                            {(data.suggestions ?? []).length > 0 && ' Try '}
                            {(data.suggestions ?? []).map((s) => (
                                <a
                                    key={s}
                                    className="pf-sug"
                                    onClick={() => {
                                        set('user', s);
                                        checkUser(s);
                                    }}
                                >
                                    @{s}{' '}
                                </a>
                            ))}
                        </>,
                    ),
                );
            }, 480);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [me.username, me.name],
    );
    const saveUser = async (e: FormEvent) => {
        e.preventDefault();
        if (userCheck !== 'ok') {
            return;
        }
        setSending('user');
        const { status, data } = await json<{ username: string; nextChange: string }>('PATCH', '/settings/username', { username: f.user });
        setSending(null);
        if (status !== 200) {
            setUserCheck('idle');
            return setHelp('user', fieldErrors(data).username ? bad(fieldErrors(data).username) : failed(status));
        }
        setMe((m) => ({ ...m, username: data.username ?? m.username, lockedUntil: data.nextChange ?? later() }));
        close();
        flashUser();
        router.reload({ only: ['auth'] });
    };

    /* ---- email: a link to the new address; nothing changes until it's opened ---- */
    const saveEmail = async (e: FormEvent) => {
        e.preventDefault();
        const v = f.email.trim();
        if (!/^\S+@\S+\.\S+$/.test(v)) {
            return setHelp('email', bad(v ? 'That doesn’t look like an email.' : 'Type your new email.'));
        }
        if (v.toLowerCase() === me.email.toLowerCase()) {
            return setHelp('email', bad('That’s your email now.'));
        }
        setHelp('email', null);
        if (!f.pw) {
            return setHelp('pw', bad('Type your password.'));
        }
        setSending('email');
        const { status, data } = await json<{ pending: string }>('POST', '/settings/email', { email: v, password: f.pw });
        setSending(null);
        if (status !== 200) {
            const errors = fieldErrors(data);
            setHelp('email', errors.email ? bad(errors.email) : null);
            return setHelp('pw', errors.password ? bad(errors.password) : errors.email ? base.pw : failed(status));
        }
        setMe((m) => ({ ...m, pendingEmail: data.pending ?? v }));
        close();
    };
    const sendAgain = async () => {
        const { status } = await json('POST', '/settings/email/again');
        setAgain(status === 200 ? 'Sent again' : 'Wait a minute');
        window.setTimeout(() => setAgain(null), 2400);
    };
    const cancelEmail = async () => {
        const { status } = await json('DELETE', '/settings/email/pending');
        if (status === 200) {
            setMe((m) => ({ ...m, pendingEmail: null }));
        }
    };

    /* ---- password ---- */
    const savePw = async (e: FormEvent) => {
        e.preventDefault();
        if (me.hasPassword && !f.cur) {
            return setHelp('cur', bad('Type your current password.'));
        }
        if (f.new.length < 8) {
            return setHelp('new', bad('At least 8 characters.'));
        }
        setSending('pw');
        const { status, data } = await json('PUT', '/settings/password', me.hasPassword ? { current_password: f.cur, password: f.new } : { password: f.new });
        setSending(null);
        if (status !== 200) {
            const errors = fieldErrors(data);
            setHelp('cur', errors.current_password ? bad(errors.current_password) : null);
            return setHelp('new', errors.password ? bad(errors.password) : errors.current_password ? base.new : failed(status));
        }
        setMe((m) => ({ ...m, hasPassword: true }));
        close();
        flashPw();
    };
    async function forgot(field: string) {
        const { status } = await json('POST', '/forgot-password', { email: me.email });
        setHelp(
            field,
            status === 200
                ? {
                      node: (
                          <>
                              We sent a reset link to <b>{me.email}</b>.
                          </>
                      ),
                  }
                : failed(status),
        );
    }

    /* ---- photo, notifications, reading ---- */
    const shuffle = async () => {
        const { status, data } = await json<{ seed: string }>('POST', '/settings/photo');
        if (status === 200 && data.seed) {
            setMe((m) => ({ ...m, seed: data.seed as string }));
            router.reload({ only: ['auth'] });
        }
    };
    const toggle = async (key: string) => {
        const on = !notif[key];
        setNotif((n) => ({ ...n, [key]: on }));
        const { status } = await json('PUT', '/settings/notifications', { key, on });
        if (status !== 200) {
            setNotif((n) => ({ ...n, [key]: !on }));
        }
    };
    const pick = (k: keyof Reading, value: string | number) => {
        setRead((r) => ({ ...r, [k]: value }));
        json('PUT', '/settings/reading', { [k]: value });
    };

    const pwAction = me.hasPassword ? 'Change' : 'Add';
    const goTo = (id: string) => (e: MouseEvent) => {
        e.preventDefault();
        document.getElementById(id)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        history.replaceState(null, '', `#${id}`);
    };

    return (
        <SiteLayout title="Settings">
            <div className="wrap pf3s">
                <Link className="pf-back" href="/profile">
                    ‹ Profile
                </Link>
                <h1 className="pf3-h1">Settings</h1>
                <div className="pf3-sg">
                    <nav className="pf-tocs" aria-label="Settings">
                        {[
                            ['account', 'Account'],
                            ['notifications', 'Notifications'],
                            ['reading', 'Reading'],
                        ].map(([id, label], i) => (
                            <a key={id} className={`pf-toc${toc === i ? ' on' : ''}`} href={`#${id}`} onClick={goTo(id)} aria-current={toc === i ? 'true' : undefined}>
                                {label}
                            </a>
                        ))}
                    </nav>
                    <div className="pf-col">
                        <section className="pf-set" id="account">
                            <h2 className="pf-h2">Account</h2>
                            <div className="pf-srow pf-photo">
                                <span className="pf-sl">Profile photo</span>
                                <Photo seed={me.seed} onShuffle={shuffle} />
                                <span />
                            </div>

                            <EditRow
                                k="name"
                                label="Name"
                                open={editing === 'name'}
                                onOpen={() => open('name')}
                                onClose={close}
                                action="Edit"
                                sending={sending === 'name' ? 'sending' : 'idle'}
                                onSubmit={saveName}
                                view={
                                    <div className="pf-vin">
                                        <span className="pf-val">{me.name}</span>
                                        <Saved on={nameSaved} />
                                    </div>
                                }
                                form={
                                    <EditField
                                        name="name"
                                        value={f.name}
                                        onChange={(v) => {
                                            set('name', v);
                                            setHelp('name', null);
                                        }}
                                        help={help.name ?? base.name}
                                    />
                                }
                            />

                            <EditRow
                                k="user"
                                label="Username"
                                open={editing === 'user'}
                                onOpen={() => open('user')}
                                onClose={close}
                                action="Edit"
                                locked={me.lockedUntil !== null}
                                sending={sending === 'user' ? 'sending' : 'idle'}
                                onSubmit={saveUser}
                                view={
                                    <div className="pf-vin">
                                        <span className="pf-val">@{me.username}</span>
                                        {me.lockedUntil && <small className="pf-lock">Next change from {me.lockedUntil}</small>}
                                        <Saved on={userSaved} />
                                    </div>
                                }
                                form={
                                    <EditField
                                        name="user"
                                        at
                                        status={userCheck}
                                        value={f.user}
                                        onChange={(v) => {
                                            set('user', v);
                                            checkUser(v);
                                        }}
                                        help={help.user ?? null}
                                    />
                                }
                            />

                            {me.google ? (
                                <div className="pf-srow pf-gonly">
                                    <span className="pf-sl">Signed in with</span>
                                    <span className="pf-sv pf-gv">
                                        {G}Google · {me.email}
                                    </span>
                                    <span />
                                </div>
                            ) : (
                                <EditRow
                                    k="email"
                                    label="Email"
                                    open={editing === 'email'}
                                    onOpen={() => open('email')}
                                    onClose={close}
                                    action="Change"
                                    saveLabel="Send link"
                                    sending={sending === 'email' ? 'sending' : 'idle'}
                                    onSubmit={saveEmail}
                                    view={
                                        <div className="pf-vin">
                                            <span className="pf-val">{me.email}</span>
                                            <span className={`pf-wait${me.pendingEmail || status ? ' show' : ''}`} aria-live="polite">
                                                {me.pendingEmail ? (
                                                    <>
                                                        Check <b>{me.pendingEmail}</b> for a link. Your email changes when you open it.{' '}
                                                        <a className="pf-sug" onClick={sendAgain}>
                                                            {again ?? 'Send again'}
                                                        </a>{' '}
                                                        <a className="pf-sug" onClick={cancelEmail}>
                                                            Cancel change
                                                        </a>
                                                    </>
                                                ) : (
                                                    status
                                                )}
                                            </span>
                                        </div>
                                    }
                                    form={
                                        <>
                                            <EditField
                                                name="email"
                                                label="New email"
                                                type="email"
                                                placeholder="you@example.com"
                                                value={f.email}
                                                onChange={(v) => {
                                                    set('email', v);
                                                    setHelp('email', null);
                                                }}
                                                help={help.email ?? null}
                                            />
                                            <EditField
                                                name="pw"
                                                label="Your password"
                                                type="password"
                                                value={f.pw}
                                                onChange={(v) => {
                                                    set('pw', v);
                                                    setHelp('pw', null);
                                                }}
                                                help={help.pw ?? base.pw}
                                            />
                                        </>
                                    }
                                />
                            )}

                            <EditRow
                                k="pw"
                                label="Password"
                                open={editing === 'pw'}
                                onOpen={() => open('pw')}
                                onClose={close}
                                action={pwAction}
                                sending={sending === 'pw' ? 'sending' : 'idle'}
                                onSubmit={savePw}
                                view={
                                    <div className="pf-vin">
                                        {me.hasPassword ? (
                                            <span className="pf-val pf-dots">••••••••••</span>
                                        ) : (
                                            <span className="pf-val" style={{ color: 'var(--muted)' }}>
                                                None yet
                                            </span>
                                        )}
                                        <Saved on={pwSaved} />
                                    </div>
                                }
                                form={
                                    <>
                                        {me.hasPassword && (
                                            <EditField
                                                name="cur"
                                                label="Current password"
                                                type="password"
                                                value={f.cur}
                                                onChange={(v) => {
                                                    set('cur', v);
                                                    setHelp('cur', null);
                                                }}
                                                help={help.cur ?? null}
                                            />
                                        )}
                                        <EditField
                                            name="new"
                                            label="New password"
                                            type="password"
                                            value={f.new}
                                            onChange={(v) => {
                                                set('new', v);
                                                setHelp('new', null);
                                            }}
                                            help={help.new ?? base.new}
                                        />
                                    </>
                                }
                                extra={
                                    me.hasPassword && (
                                        <a className="pf-sug pf-forgot pf-fg" onClick={() => forgot('new')}>
                                            Forgot it?
                                        </a>
                                    )
                                }
                            />
                        </section>

                        <section className="pf-set" id="notifications">
                            <h2 className="pf-h2">Notifications</h2>
                            <div className="pf-nh">
                                <span />
                                <span>On the site</span>
                                <span>By email</span>
                            </div>
                            {NOTIFY.map((n) => (
                                <div className="pf-nrow" key={n.key}>
                                    <span>{n.label}</span>
                                    {(['site', 'email'] as const).map((where) => {
                                        const key = `${n.key}.${where}`;

                                        return (
                                            <button
                                                key={key}
                                                type="button"
                                                className={`pf-tg${notif[key] ? ' on' : ''}`}
                                                role="switch"
                                                aria-checked={notif[key]}
                                                aria-label={`${n.label}: ${where === 'site' ? 'on the site' : 'by email'}`}
                                                onClick={() => toggle(key)}
                                            >
                                                <i />
                                            </button>
                                        );
                                    })}
                                </div>
                            ))}
                        </section>

                        <section className="pf-set" id="reading">
                            <h2 className="pf-h2">Reading</h2>
                            <div className="pf-srow pf-tall">
                                <span className="pf-sl">Theme</span>
                                <span className="pf-sv pf-sws">
                                    {THEMES.map((t) => (
                                        <button
                                            key={t.key}
                                            type="button"
                                            className={`pf-sw${theme === t.key ? ' on' : ''}`}
                                            style={{ background: t.bg, color: t.ink }}
                                            aria-pressed={theme === t.key}
                                            onClick={(e) => {
                                                setThemeFrom(t.key, e.currentTarget);
                                                pick('theme', t.key);
                                            }}
                                        >
                                            {t.name}
                                        </button>
                                    ))}
                                </span>
                                <span />
                            </div>
                            {SEGS.map((seg) => (
                                <div className="pf-srow" key={seg.k}>
                                    <span className="pf-sl">{seg.label}</span>
                                    <span className="pf-sv">
                                        <span className="pf-seg" role="group" aria-label={seg.label}>
                                            {seg.options.map(([value, label]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    className={read[seg.k] === value ? 'on' : undefined}
                                                    aria-pressed={read[seg.k] === value}
                                                    onClick={() => pick(seg.k, value)}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </span>
                                    </span>
                                    <span />
                                </div>
                            ))}
                        </section>

                        <section className="pf-set pf-del">
                            <div className="pf-srow">
                                <span className="pf-sl">Delete account</span>
                                <span className="pf-sv pf-mut">Your likes, follows and comments go with it.</span>
                                <button type="button" className="pf-danger" onClick={() => setDel(true)}>
                                    Delete account
                                </button>
                            </div>
                            <div className="pf-out">
                                <Link className="link" href="/logout" method="post" as="button" onClick={forgetReading}>
                                    Sign out
                                </Link>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
            <DeleteCard open={del} onClose={closeDel} username={me.username} comments={me.comments} />
        </SiteLayout>
    );
}

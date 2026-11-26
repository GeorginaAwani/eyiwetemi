import { router, usePage } from '@inertiajs/react';
import { FormEvent, KeyboardEvent as ReactKeyboardEvent, ReactNode, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { inertWhen } from '@/lib/dom';
import { fieldErrors, json } from '@/lib/http';
import { prefersReducedMotion } from '@/lib/motion';
import { paths } from '@/lib/paths';
import { SignInDetail, usernameFrom } from '@/lib/signin';
import Bloom from './Bloom';
import SendButton, { SendState } from './SendButton';

type View = 'main' | 'google' | 'forgot' | 'reset';
type Tab = 'in' | 'up';
type Help = { node: ReactNode; bad?: boolean } | null;
type Field = 'inEmail' | 'inPw' | 'upName' | 'upUser' | 'upEmail' | 'upPw' | 'gUser' | 'newPw';

/** What the server hands the card on arrival: back from Google, a reset link, a private page, or an error. */
export type SignInFlash = {
    view: View;
    why?: string;
    error?: string;
    google?: { name: string; email: string; username: string };
    reset?: { token: string; email: string };
} | null;

const EMAIL = /^\S+@\S+\.\S+$/;

const G = (
    <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
        <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
        <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
    </svg>
);
const X = (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
        <path d="M6 6l12 12M18 6 6 18" />
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
const TICK = (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);
const ARC = (
    <svg className="au-arc" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="9" pathLength="100" />
    </svg>
);
const ALERT = (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7.5v5.5M12 16.4v.1" />
    </svg>
);

const bad = (text: ReactNode, link?: { label: string; on: () => void }): Help => ({
    bad: true,
    node: (
        <>
            <span className="au-bi">{ALERT}</span>
            {text}
            {link && (
                <>
                    {' '}
                    <a
                        href="#"
                        onClick={(e) => {
                            e.preventDefault();
                            link.on();
                        }}
                    >
                        {link.label}
                    </a>
                </>
            )}
        </>
    ),
});

/** A help line under a field: the old words fade, the new ones come in. */
function HelpLine({ help }: { help: Help }) {
    const [shown, setShown] = useState<Help>(help);
    const [swapping, setSwapping] = useState(false);

    useEffect(() => {
        if (help === shown) {
            return;
        }
        if (!help || !shown || prefersReducedMotion()) {
            setShown(help);

            return;
        }
        setSwapping(true);
        const t = window.setTimeout(() => {
            setShown(help);
            setSwapping(false);
        }, 120);

        return () => window.clearTimeout(t);
    }, [help, shown]);

    return (
        <div className={`au-help${shown?.bad ? ' bad' : ''}${swapping ? ' swap' : ''}`} aria-live="polite">
            {shown?.node}
        </div>
    );
}

type FieldProps = {
    id: string;
    label: string;
    type: 'email' | 'text' | 'pw' | 'user';
    value: string;
    onChange: (v: string) => void;
    help: Help;
    placeholder?: string;
    autoComplete?: string;
    status?: 'idle' | 'check' | 'ok' | 'bad';
    inputRef?: (el: HTMLInputElement | null) => void;
    onBlur?: () => void;
};

function AuField({ id, label, type, value, onChange, help, placeholder = '', autoComplete, status = 'idle', inputRef, onBlur }: FieldProps) {
    const [show, setShow] = useState(false);
    const user = type === 'user';

    return (
        <div className="au-f">
            <label className="au-l" htmlFor={`au-${id}`}>
                {label}
            </label>
            <div className={`au-in${user ? ' user' : ''}${help?.bad ? ' bad' : ''}${status === 'ok' ? ' ok' : ''}${status === 'check' ? ' check' : ''}`}>
                {user && <span className="au-at">@</span>}
                <input
                    ref={inputRef}
                    id={`au-${id}`}
                    name={id}
                    type={type === 'pw' ? (show ? 'text' : 'password') : user ? 'text' : type}
                    value={value}
                    placeholder={placeholder}
                    autoComplete={user ? 'off' : autoComplete}
                    autoCapitalize={user || type === 'email' ? 'none' : undefined}
                    spellCheck={user || type === 'email' ? false : undefined}
                    aria-invalid={help?.bad || undefined}
                    onChange={(e) => onChange(e.target.value)}
                    onBlur={onBlur}
                />
                {user && <span className="au-stt">{status === 'ok' ? TICK : status === 'check' ? ARC : null}</span>}
                {type === 'pw' && (
                    <button type="button" className={`au-eye${show ? ' on' : ''}`} aria-label={show ? 'Hide password' : 'Show password'} onClick={() => setShow((s) => !s)}>
                        <span className="e1">{EYE}</span>
                        <span className="e2">{EYE_OFF}</span>
                    </button>
                )}
            </div>
            <HelpLine help={help} />
        </div>
    );
}

/** The live username check: Checking…, then free (tick) or why not with close ones that are free. */
function useUsername(nameOf: () => string) {
    const [value, setValue] = useState('');
    const [status, setStatus] = useState<'idle' | 'check' | 'ok' | 'bad'>('idle');
    const [help, setHelp] = useState<Help>(null);
    const touched = useRef(false);
    const seq = useRef(0);
    const timer = useRef<number>();

    const check = useCallback(
        (u: string, auto: boolean) => {
            window.clearTimeout(timer.current);
            const id = ++seq.current;
            if (!u.trim()) {
                setStatus('idle');
                setHelp(null);

                return;
            }
            setStatus('check');
            setHelp({ node: 'Checking…' });
            timer.current = window.setTimeout(async () => {
                const { status: code, data: got } = await json<{ ok: boolean; message: string; suggestions: string[] }>(
                    'GET',
                    `/username-check?${new URLSearchParams({ u: u.trim(), name: nameOf() })}`,
                );
                const data = { ok: !!got.ok, message: got.message ?? '', suggestions: got.suggestions ?? [] };
                if (id !== seq.current) {
                    return;
                }
                if (code !== 200) {
                    setStatus('idle');
                    setHelp(code === 429 ? bad('Too many checks. Wait a moment.') : null);

                    return;
                }
                /* a name's first guess that's taken quietly becomes the closest free one */
                if (!data.ok && auto && data.suggestions[0]) {
                    setValue(data.suggestions[0]);
                    check(data.suggestions[0], false);

                    return;
                }
                setStatus(data.ok ? 'ok' : 'bad');
                setHelp(
                    data.ok
                        ? { node: data.message }
                        : bad(
                              <>
                                  {data.message}
                                  {data.suggestions.length > 0 && ' Try '}
                                  {data.suggestions.map((s) => (
                                      <a
                                          key={s}
                                          className="au-s"
                                          href="#"
                                          onClick={(e) => {
                                              e.preventDefault();
                                              touched.current = true;
                                              setValue(s);
                                              check(s, false);
                                          }}
                                      >
                                          @{s}
                                      </a>
                                  ))}
                              </>,
                          ),
                );
            }, 480);
        },
        [nameOf],
    );

    const type = (v: string) => {
        touched.current = true;
        setValue(v);
        check(v, false);
    };
    /* until you type your own, it follows your name */
    const follow = (name: string) => {
        if (touched.current) {
            return;
        }
        const u = usernameFrom(name);
        setValue(u);
        check(u, true);
    };
    const offer = (u: string) => {
        touched.current = false;
        setValue(u);
        check(u, false);
    };

    return { value, status, help, setHelp, type, follow, offer, ok: status === 'ok' };
}

const focusFirst = (root: Element | null) => {
    if (!root) {
        return;
    }
    const inputs = [...root.querySelectorAll<HTMLInputElement>('input')].filter((i) => i.offsetParent !== null);
    const target = inputs.find((i) => !i.value) ?? root.querySelector<HTMLElement>('button:not(.au-x), a[href], input');
    target?.focus({ preventScroll: true });
};

/**
 * Sign in / Create account (A · Tabs). Opens from the header, the phone menu, and any Like, Follow or comment
 * when you're signed out (with a why-line); the thing you were doing carries on once you're in.
 */
export default function SignIn() {
    const page = usePage();
    const flash = (page.props as { signin?: SignInFlash }).signin ?? null;
    const signedIn = page.props.auth.user !== null;

    const [open, setOpen] = useState(false);
    const [view, setView] = useState<View>('main');
    const [tab, setTab] = useState<Tab>('in');
    const [out, setOut] = useState<string | null>(null);
    const [why, setWhy] = useState<SignInDetail | null>(null);
    const [v, setV] = useState({ inEmail: '', inPw: '', upName: '', upEmail: '', upPw: '', newPw: '' });
    const [help, setHelpState] = useState<Partial<Record<Field, Help>>>({});
    const [gHelp, setGHelp] = useState<Help>(null);
    const [sending, setSending] = useState<{ key: string; state: SendState } | null>(null);
    const [google, setGoogle] = useState<{ name: string; email: string } | null>(null);
    const [reset, setReset] = useState<{ token: string; email: string } | null>(null);
    const [forgotMail, setForgotMail] = useState('');
    const [again, setAgain] = useState<string | null>(null);

    const card = useRef<HTMLDivElement>(null);
    const layer = useRef<HTMLDivElement>(null);
    const lastFocus = useRef<HTMLElement | null>(null);
    const then = useRef<(() => void) | null>(null);
    const upNameRef = useRef('');
    upNameRef.current = v.upName;
    const googleName = useRef('');
    googleName.current = google?.name ?? '';

    const upUser = useUsername(useCallback(() => upNameRef.current, []));
    const gUser = useUsername(useCallback(() => googleName.current, []));

    const setHelp = (field: Field, h: Help) => setHelpState((all) => ({ ...all, [field]: h }));
    const set = (field: keyof typeof v, value: string) => setV((all) => ({ ...all, [field]: value }));
    const focus = (id: string, delay = 0) => window.setTimeout(() => document.getElementById(`au-${id}`)?.focus({ preventScroll: true }), delay);

    /* lines in each step settle one after another */
    useLayoutEffect(() => {
        card.current?.querySelectorAll('.au-st').forEach((s) => [...s.children].forEach((c, k) => (c as HTMLElement).style.setProperty('--k', String(k))));
    });

    const show = useCallback((detail: SignInDetail, start: View = 'main') => {
        lastFocus.current = document.activeElement as HTMLElement | null;
        then.current = detail.then ?? null;
        setWhy(detail.why ? detail : null);
        setView(start);
        setTab(detail.tab ?? 'in');
        setOut(null);
        setHelpState({});
        setGHelp(null);
        setSending(null);
        setOpen(true);
        window.setTimeout(() => focusFirst(card.current?.querySelector('.au-view:not([hidden])') ?? null), prefersReducedMotion() ? 0 : 220);
    }, []);

    const close = useCallback((done = false) => {
        setOpen(false);
        const after = done ? then.current : null;
        then.current = null;
        if (lastFocus.current?.isConnected) {
            lastFocus.current.focus({ preventScroll: true });
        }
        if (after) {
            window.setTimeout(after, prefersReducedMotion() ? 60 : 260);
        }
    }, []);

    /* Sign in buttons, and anything that needs you signed in */
    useEffect(() => {
        const onAsk = (e: Event) => {
            const detail = (e as CustomEvent<SignInDetail>).detail ?? {};
            if (signedIn) {
                detail.then?.();

                return;
            }
            show(detail);
        };
        window.addEventListener('eyi:signin', onAsk);

        return () => window.removeEventListener('eyi:signin', onAsk);
    }, [show, signedIn]);

    /* arriving with something to finish: back from Google, a reset link, a private page */
    useEffect(() => {
        if (!flash || signedIn) {
            return;
        }
        const t = window.setTimeout(() => {
            if (flash.view === 'google' && flash.google) {
                setGoogle({ name: flash.google.name, email: flash.google.email });
                googleName.current = flash.google.name;
                gUser.offer(flash.google.username);
            }
            if (flash.view === 'reset' && flash.reset) {
                setReset(flash.reset);
                set('inEmail', flash.reset.email);
            }
            show({ why: flash.why }, flash.view);
            if (flash.error) {
                setGHelp(bad(flash.error));
            }
        }, 350);

        return () => window.clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash]);

    /* the page behind doesn't scroll while the card is open */
    useEffect(() => {
        const el = layer.current;
        if (!open || !el) {
            return;
        }
        const onWheel = (e: WheelEvent) => {
            const c = card.current;
            if (c && (!c.contains(e.target as Node) || c.scrollHeight <= c.clientHeight)) {
                e.preventDefault();
            }
        };
        const onTouch = (e: TouchEvent) => {
            if (!card.current?.contains(e.target as Node)) {
                e.preventDefault();
            }
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        el.addEventListener('touchmove', onTouch, { passive: false });

        return () => {
            el.removeEventListener('wheel', onWheel);
            el.removeEventListener('touchmove', onTouch);
        };
    }, [open]);

    /* Esc closes; Tab stays inside the card */
    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.stopImmediatePropagation();
                close();

                return;
            }
            if (e.key !== 'Tab' || !card.current) {
                return;
            }
            const f = [...card.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled])')].filter((x) => x.offsetParent !== null);
            if (!f.length) {
                return;
            }
            const [a, z] = [f[0], f[f.length - 1]];
            if (e.shiftKey && document.activeElement === a) {
                e.preventDefault();
                z.focus();
            } else if (!e.shiftKey && document.activeElement === z) {
                e.preventDefault();
                a.focus();
            } else if (!card.current.contains(document.activeElement)) {
                e.preventDefault();
                a.focus();
            }
        };
        document.addEventListener('keydown', onKey, true);

        return () => document.removeEventListener('keydown', onKey, true);
    }, [open, close]);

    /**
     * Swap steps: the old one fades and blurs, the card eases to its new height, the new one comes in.
     */
    const swap = (fromKey: string, toKey: string, apply: () => void, focusTo?: string) => {
        const reduce = prefersReducedMotion();
        setOut(fromKey);
        window.setTimeout(
            () => {
                const el = card.current;
                const h0 = el?.offsetHeight ?? 0;
                flushSync(() => {
                    apply();
                    setOut(toKey);
                });
                const h1 = el?.offsetHeight ?? 0;
                if (el && !reduce && Math.abs(h1 - h0) > 2) {
                    el.animate([{ height: `${h0}px` }, { height: `${h1}px` }], { duration: 340, easing: 'cubic-bezier(.22,1,.36,1)' });
                }
                requestAnimationFrame(() => requestAnimationFrame(() => setOut(null)));
                window.setTimeout(() => (focusTo ? focus(focusTo) : focusFirst(card.current?.querySelector('.au-view:not([hidden])') ?? null)), 200);
            },
            reduce ? 0 : 170,
        );
    };

    const goTab = (k: Tab, focusTo?: string) => {
        if (k === tab) {
            return;
        }
        /* the email you typed comes with you */
        if (k === 'up' && !v.upEmail && v.inEmail) {
            set('upEmail', v.inEmail);
        }
        if (k === 'in' && !v.inEmail && v.upEmail) {
            set('inEmail', v.upEmail);
        }
        swap(`p:${tab}`, `p:${k}`, () => setTab(k), focusTo);
    };
    const goView = (k: View) => swap(`v:${view}`, `v:${k}`, () => setView(k));

    /** Signed in: a tick, the page catches up behind the card, the card goes, and what you were doing carries on. */
    const signedInNow = (key: string, redirect?: string | null) => {
        setSending({ key, state: 'sent' });
        if (redirect) {
            window.setTimeout(() => router.visit(redirect), 380);

            return;
        }
        router.reload({
            onFinish: () => window.setTimeout(() => close(true), 380),
        });
    };

    const failed = (status: number): Help => {
        if (status === 429) {
            return bad('Too many tries. Wait a minute and try again.');
        }
        if (status === 419) {
            return bad('This page was open a long time. Reload it and try again.');
        }

        return bad('Something went wrong. Try again.');
    };

    /* ---- sign in ---- */
    const signInSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (sending) {
            return;
        }
        const em = v.inEmail.trim();
        if (!em) {
            setHelp('inEmail', bad('Type your email.'));
            return focus('in-email');
        }
        if (!EMAIL.test(em)) {
            setHelp('inEmail', bad('That doesn’t look like an email.'));
            return focus('in-email');
        }
        setHelp('inEmail', null);
        if (!v.inPw) {
            setHelp('inPw', bad('Type your password.'));
            return focus('in-pw');
        }
        setSending({ key: 'in', state: 'sending' });
        const { status, data } = await json<{ redirect?: string | null }>('POST', '/login', { email: em, password: v.inPw, remember: true });
        if (status === 200) {
            return signedInNow('in', data.redirect);
        }
        setSending(null);
        const errors = fieldErrors(data);
        if (errors.email === 'No account uses this email.') {
            setHelp('inEmail', bad(errors.email, { label: 'Create one', on: () => goTab('up', 'up-name') }));
            return focus('in-email');
        }
        if (errors.password === 'This account uses Google.') {
            return setHelp('inPw', bad(errors.password, { label: 'Continue with Google', on: continueWithGoogle }));
        }
        if (errors.password) {
            setHelp('inPw', bad(errors.password, { label: 'Forgot password?', on: forgot }));
            (document.getElementById('au-in-pw') as HTMLInputElement | null)?.select();
            return;
        }
        setHelp('inPw', errors.email ? bad(errors.email) : failed(status));
    };

    /* ---- create account ---- */
    const upSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (sending) {
            return;
        }
        const name = v.upName.trim();
        const em = v.upEmail.trim();
        if (!name) {
            setHelp('upName', bad('Type your name.'));
            return focus('up-name');
        }
        if (!upUser.ok) {
            if (!upUser.value.trim()) {
                upUser.setHelp(bad('Pick a username.'));
            }
            return focus('up-user');
        }
        if (!EMAIL.test(em)) {
            setHelp('upEmail', bad(em ? 'That doesn’t look like an email.' : 'Type your email.'));
            return focus('up-email');
        }
        if (v.upPw.length < 8) {
            setHelp('upPw', bad('8 or more characters.'));
            return focus('up-pw');
        }
        setSending({ key: 'up', state: 'sending' });
        const { status, data } = await json<{ redirect?: string | null }>('POST', '/register', {
            name,
            username: upUser.value.trim().toLowerCase(),
            email: em,
            password: v.upPw,
        });
        if (status === 200 || status === 201) {
            return signedInNow('up', data.redirect);
        }
        setSending(null);
        const errors = fieldErrors(data);
        if (errors.name) {
            setHelp('upName', bad(errors.name));
            return focus('up-name');
        }
        if (errors.username) {
            upUser.setHelp(bad(errors.username));
            return focus('up-user');
        }
        if (errors.email) {
            setHelp('upEmail', bad(errors.email, errors.email.startsWith('There’s already') ? { label: 'Sign in', on: () => goTab('in', 'in-pw') } : undefined));
            return focus('up-email');
        }
        if (errors.password) {
            setHelp('upPw', bad(errors.password));
            return focus('up-pw');
        }
        setHelp('upPw', failed(status));
    };

    /* ---- Google ---- */
    function continueWithGoogle() {
        if (sending) {
            return;
        }
        setSending({ key: 'google', state: 'sending' });
        const back = window.location.pathname + window.location.search + window.location.hash;
        window.location.assign(`/auth/google?${new URLSearchParams({ back })}`);
    }

    const googleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (sending) {
            return;
        }
        if (!gUser.ok) {
            if (!gUser.value.trim()) {
                gUser.setHelp(bad('Pick a username.'));
            }
            return focus('g-user');
        }
        setSending({ key: 'g', state: 'sending' });
        const { status, data } = await json<{ redirect?: string | null }>('POST', '/auth/google/finish', { username: gUser.value.trim().toLowerCase() });
        if (status === 200) {
            return signedInNow('g', data.redirect);
        }
        setSending(null);
        const errors = fieldErrors(data);
        gUser.setHelp(errors.username ? bad(errors.username) : status === 419 ? bad('That took too long. Close this and continue with Google again.') : failed(status));
    };

    /* ---- forgot password ---- */
    async function forgot() {
        const em = v.inEmail.trim();
        if (!EMAIL.test(em)) {
            setHelp('inEmail', bad('Type your email first.'));
            return focus('in-email');
        }
        const { status, data } = await json('POST', '/forgot-password', { email: em });
        if (status === 200) {
            setForgotMail(em);
            if (view !== 'forgot') {
                goView('forgot');
            }
            return;
        }
        const errors = fieldErrors(data);
        if (view !== 'main') {
            swap(`v:${view}`, 'v:main', () => {
                setView('main');
                setTab('in');
            });
        }
        setHelp(
            'inEmail',
            errors.email === 'No account uses this email.' ? bad(errors.email, { label: 'Create one', on: () => goTab('up', 'up-name') }) : errors.email ? bad(errors.email) : failed(status),
        );
    }

    const sendAgain = async () => {
        if (again) {
            return;
        }
        const { status, data } = await json('POST', '/forgot-password', { email: forgotMail });
        setAgain(status === 200 ? 'Sent again' : (fieldErrors(data).email ?? 'Something went wrong'));
        window.setTimeout(() => setAgain(null), 2400);
    };

    /* ---- a new password, from the email's link ---- */
    const resetSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (sending || !reset) {
            return;
        }
        if (v.newPw.length < 8) {
            setHelp('newPw', bad('8 or more characters.'));
            return focus('new-pw');
        }
        setSending({ key: 'reset', state: 'sending' });
        const r = await json('POST', '/reset-password', { token: reset.token, email: reset.email, password: v.newPw });
        if (r.status !== 200) {
            setSending(null);
            const errors = fieldErrors(r.data);
            if (errors.email === 'This link has expired.') {
                return setHelp('newPw', bad(errors.email, { label: 'Send a new one', on: () => forgot() }));
            }
            return setHelp('newPw', errors.password ? bad(errors.password) : errors.email ? bad(errors.email) : failed(r.status));
        }
        const { status, data } = await json<{ redirect?: string | null }>('POST', '/login', { email: reset.email, password: v.newPw, remember: true });
        if (status === 200) {
            return signedInNow('reset', data.redirect);
        }
        setSending(null);
        setHelp('newPw', failed(status));
    };

    const onTabsKey = (e: ReactKeyboardEvent) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
            return;
        }
        const k: Tab = tab === 'in' ? 'up' : 'in';
        goTab(k);
        document.getElementById(`au-tab-${k}`)?.focus();
    };

    const btn = (key: string): SendState => (sending?.key === key ? sending.state : 'idle');
    const cls = (base: string, key: string) => `${base}${out === key ? ' out' : ''}`;
    const pic = why?.picture;
    const fine = (
        <div className="au-fine">
            By creating an account you agree to the Terms and <a href={paths.privacy}>Privacy</a>.
        </div>
    );

    return (
        <div
            className={`au-layer${open ? ' open' : ''}`}
            aria-hidden={!open}
            ref={layer}
            onMouseDown={(e) => e.target === e.currentTarget && close()}
            {...inertWhen(!open)}
        >
            <div ref={card} className={`au-card a${open ? ' settle-in' : ''}`} role="dialog" aria-modal="true" aria-labelledby={view === 'main' ? 'auTitle' : `auH-${view}`}>
                <button className="au-x" type="button" aria-label="Close" onClick={() => close()}>
                    {X}
                </button>

                <div className={cls('au-view', 'v:main')} hidden={view !== 'main'}>
                    {why && (
                        <div className="au-why">
                            {pic?.src ? <img className="au-wth" src={pic.src} alt="" /> : pic?.color ? <span className="au-wth" style={{ background: pic.color }} /> : null}
                            <span>
                                <b>{why.why}</b>
                                {why.sub && <small>{why.sub}</small>}
                            </span>
                        </div>
                    )}
                    <div className="au-tabs" role="tablist" id="auTitle" onKeyDown={onTabsKey}>
                        {(['in', 'up'] as Tab[]).map((k) => (
                            <button
                                key={k}
                                id={`au-tab-${k}`}
                                type="button"
                                role="tab"
                                className={`au-tab${tab === k ? ' on' : ''}`}
                                aria-selected={tab === k}
                                tabIndex={tab === k ? 0 : -1}
                                onClick={() => goTab(k)}
                            >
                                {k === 'in' ? 'Sign in' : 'Create account'}
                            </button>
                        ))}
                    </div>

                    <form className={cls('au-pane au-body au-st', 'p:in')} hidden={tab !== 'in'} noValidate onSubmit={signInSubmit}>
                        <SendButton type="button" className="au-g" state={btn('google')} onClick={continueWithGoogle}>
                            {G}
                            <span>Continue with Google</span>
                        </SendButton>
                        {gHelp && (
                            <div className="au-help bad" style={{ marginTop: -4 }}>
                                {gHelp.node}
                            </div>
                        )}
                        <div className="au-or">
                            <span>or</span>
                        </div>
                        <AuField
                            id="in-email"
                            label="Email"
                            type="email"
                            placeholder="you@example.com"
                            autoComplete="email"
                            value={v.inEmail}
                            help={help.inEmail ?? null}
                            onChange={(x) => {
                                set('inEmail', x);
                                setHelp('inEmail', null);
                            }}
                        />
                        <AuField
                            id="in-pw"
                            label="Password"
                            type="pw"
                            autoComplete="current-password"
                            value={v.inPw}
                            help={help.inPw ?? null}
                            onChange={(x) => {
                                set('inPw', x);
                                setHelp('inPw', null);
                            }}
                        />
                        <SendButton className="au-go" type="submit" state={btn('in')}>
                            Sign in
                        </SendButton>
                        <div className="au-foot">
                            <a
                                href="#"
                                onClick={(e) => {
                                    e.preventDefault();
                                    forgot();
                                }}
                            >
                                Forgot password?
                            </a>
                        </div>
                    </form>

                    <form className={cls('au-pane au-body au-st', 'p:up')} hidden={tab !== 'up'} noValidate onSubmit={upSubmit}>
                        <SendButton type="button" className="au-g" state={btn('google')} onClick={continueWithGoogle}>
                            {G}
                            <span>Continue with Google</span>
                        </SendButton>
                        <div className="au-or">
                            <span>or</span>
                        </div>
                        <AuField
                            id="up-name"
                            label="Name"
                            type="text"
                            placeholder="Your name"
                            autoComplete="name"
                            value={v.upName}
                            help={help.upName ?? null}
                            onChange={(x) => {
                                set('upName', x);
                                upNameRef.current = x;
                                setHelp('upName', null);
                                upUser.follow(x);
                            }}
                        />
                        <AuField id="up-user" label="Username" type="user" placeholder="username" value={upUser.value} help={upUser.help} status={upUser.status} onChange={upUser.type} />
                        <AuField
                            id="up-email"
                            label="Email"
                            type="email"
                            placeholder="you@example.com"
                            autoComplete="email"
                            value={v.upEmail}
                            help={help.upEmail ?? null}
                            onChange={(x) => {
                                set('upEmail', x);
                                setHelp('upEmail', null);
                            }}
                        />
                        <AuField
                            id="up-pw"
                            label="Password"
                            type="pw"
                            autoComplete="new-password"
                            value={v.upPw}
                            help={help.upPw ?? null}
                            onChange={(x) => {
                                set('upPw', x);
                                setHelp('upPw', x.length && x.length < 8 ? { node: '8 or more characters' } : null);
                            }}
                        />
                        <SendButton className="au-go" type="submit" state={btn('up')}>
                            Create account
                        </SendButton>
                        {fine}
                    </form>
                </div>

                <form className={cls('au-view au-body au-st', 'v:google')} hidden={view !== 'google'} noValidate onSubmit={googleSubmit}>
                    <h2 className="au-h" id="auH-google">
                        One more thing
                    </h2>
                    <div className="au-gacct">
                        <Bloom seed={google?.name ?? 'reader'} size={40} />
                        <span>
                            <b>{google?.name}</b>
                            <small>{google?.email}</small>
                        </span>
                        {G}
                    </div>
                    <AuField id="g-user" label="Username" type="user" placeholder="username" value={gUser.value} help={gUser.help} status={gUser.status} onChange={gUser.type} />
                    <SendButton className="au-go" type="submit" state={btn('g')}>
                        Create account
                    </SendButton>
                    {fine}
                </form>

                <div className={cls('au-view au-body au-st', 'v:forgot')} hidden={view !== 'forgot'}>
                    <h2 className="au-h" id="auH-forgot">
                        Check your email
                    </h2>
                    <p className="au-p">
                        We sent a link to <b>{forgotMail}</b>. It works for an hour.
                    </p>
                    <button className="au-go" type="button" onClick={() => goView('main')}>
                        <span>Back to sign in</span>
                    </button>
                    <div className="au-foot">
                        <a
                            href="#"
                            style={again ? { pointerEvents: 'none' } : undefined}
                            onClick={(e) => {
                                e.preventDefault();
                                sendAgain();
                            }}
                        >
                            {again ?? 'Send it again'}
                        </a>
                    </div>
                </div>

                <form className={cls('au-view au-body au-st', 'v:reset')} hidden={view !== 'reset'} noValidate onSubmit={resetSubmit}>
                    <h2 className="au-h" id="auH-reset">
                        Choose a new password
                    </h2>
                    <p className="au-p">
                        For <b>{reset?.email}</b>.
                    </p>
                    <AuField
                        id="new-pw"
                        label="New password"
                        type="pw"
                        autoComplete="new-password"
                        value={v.newPw}
                        help={help.newPw ?? null}
                        onChange={(x) => {
                            set('newPw', x);
                            setHelp('newPw', x.length && x.length < 8 ? { node: '8 or more characters' } : null);
                        }}
                    />
                    <SendButton className="au-go" type="submit" state={btn('reset')}>
                        Save and sign in
                    </SendButton>
                </form>
            </div>
        </div>
    );
}

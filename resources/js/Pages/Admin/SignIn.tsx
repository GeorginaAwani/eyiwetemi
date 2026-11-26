import { Head, router } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import AdminTop from '../../Components/admin/AdminTop';
import { Alert, Back, Clock, Eye, EyeOff, Google, Mail, Next, Ring, Tick } from '../../Components/admin/Icons';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Step = 'email' | 'pw' | 'code' | 'backup' | 'forgot' | 'sent' | 'paused' | 'reset';
type Answer = { redirect?: string; next?: 'code'; pausedFor?: number; expired?: boolean; message?: string; errors?: Record<string, string[]> };

type Props = { step: 'email' | 'code' | 'reset'; email: string | null; pausedFor: number; error: string | null };

const EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const greet = () => {
    const h = new Date().getHours();

    return h >= 18 || h < 4 ? 'Good evening' : h < 12 ? 'Good morning' : 'Good afternoon';
};
const TITLE: Record<Step, () => string> = {
    email: greet,
    pw: greet,
    code: () => 'Your code',
    backup: () => 'Backup code',
    forgot: () => 'Reset password',
    sent: () => 'Check your email',
    paused: () => 'Paused',
    reset: () => 'New password',
};
const FOCUS: Partial<Record<Step, string>> = { email: '#em', pw: '#pw', code: '.ad-c', backup: '#bk', forgot: '#fe', reset: '#np' };
const OFFLINE = 'No connection. Try again.';

const wait = (ms: number) => new Promise((r) => window.setTimeout(r, ms));
const $ = <T extends Element = HTMLElement>(s: string) => document.querySelector<T>(s)!;

function settle(el: Element) {
    el.classList.remove('settle');
    void (el as HTMLElement).offsetWidth;
    el.classList.add('settle');
}

function nudge(el: Element) {
    el.classList.remove('nudge');
    void (el as HTMLElement).offsetWidth;
    el.classList.add('nudge');
    el.addEventListener('animationend', () => el.classList.remove('nudge'), { once: true });
}

let dotsTimer: number | undefined;
function loading(on: boolean) {
    window.clearTimeout(dotsTimer);
    if (on) {
        dotsTimer = window.setTimeout(() => document.documentElement.classList.add('loading'), 300);
    } else {
        document.documentElement.classList.remove('loading');
    }
}

function busy(el: HTMLElement, on: boolean) {
    el.classList.toggle('busy', on);
    if (el.tagName === 'BUTTON') {
        el.setAttribute('aria-busy', String(on));
    }
    loading(on);
}

/** The line under a field: unfolds with the message, the field's underline turns rose. */
function say(id: string, msg: string, input?: HTMLInputElement | null) {
    const e = $(id);
    e.querySelector('.t')!.textContent = msg;
    e.classList.toggle('show', !!msg);
    input?.closest('.ad-in')?.classList.toggle('bad', !!msg);
}

function NextButton({ id, label }: { id: string; label: string }) {
    return (
        <button className="ad-nx" id={id} type="submit" aria-label={label}>
            <Next />
            <Ring />
        </button>
    );
}

function Err({ id }: { id: string }) {
    return (
        <div className="ad-err" id={id} role="alert">
            <div>
                <p>
                    <span className="ad-bi">
                        <Alert />
                    </span>
                    <span className="t" />
                </p>
            </div>
        </div>
    );
}

/**
 * The admin sign-in (C · Blank page): email → password → code (skipped on a remembered device), with Google, an
 * emailed link, Forgot it?, a backup code, and the 15-minute pause after five wrong tries.
 */
export default function SignIn(props: Props) {
    const [title, setTitle] = useState(TITLE[props.step === 'reset' ? 'reset' : props.step === 'code' ? 'code' : 'email']());
    const [eye, setEye] = useState(false);
    const [resetEye, setResetEye] = useState(false);
    const [againIn, setAgainIn] = useState(0);
    const [pauseLeft, setPauseLeft] = useState(0);
    const state = useRef({ cur: null as Step | null, back: 'email' as Step, email: props.email ?? '', sentKind: 'link' as 'link' | 'forgot' });
    const heading = useRef<HTMLHeadingElement>(null);

    const cells = () => [...document.querySelectorAll<HTMLInputElement>('.ad-c')];

    function show(step: Step, focus = true) {
        const s = state.current;
        if (s.cur === step) {
            return;
        }
        const first = s.cur === null;
        if (s.cur && !['sent', 'paused'].includes(s.cur)) {
            s.back = s.cur;
        }
        const next = $(`#s-${step}`);
        const t = TITLE[step]();
        const h = heading.current!;
        const swapH = first || h.textContent !== t;
        document.querySelectorAll<HTMLElement>('.ad-step').forEach((el) => {
            if (el !== next) {
                el.classList.remove('on', 'settle');
                el.inert = true;
                el.setAttribute('aria-hidden', 'true');
            }
        });
        if (swapH && !first) {
            h.classList.add('out');
        }
        const enter = () => {
            if (swapH) {
                setTitle(t);
                h.textContent = t;
                h.classList.remove('out');
                settle(h);
            }
            next.classList.add('on');
            next.inert = false;
            next.setAttribute('aria-hidden', 'false');
            [...next.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i + (swapH ? 1 : 0))));
            settle(next);
        };
        if (first) {
            enter();
        } else {
            window.setTimeout(enter, 170);
        }
        s.cur = step;
        if (step === 'pw') {
            $('#emShow').textContent = s.email;
        }
        const f = FOCUS[step];
        if (focus && f) {
            window.setTimeout(() => document.querySelector<HTMLElement>(f)?.focus({ preventScroll: true }), first ? 0 : 300);
        }
    }

    /* signed in: the page goes faint (the dots bob if it takes a while), then the desk */
    function signedIn(url: string) {
        document.documentElement.classList.add('leaving');
        loading(true);
        window.setTimeout(() => router.visit(url), prefersReducedMotion() ? 200 : 520);
    }

    function pause(seconds: number) {
        setPauseLeft(seconds);
        show('paused');
    }

    function clearCode() {
        cells().forEach((c) => (c.value = ''));
        $('#code').classList.remove('bad', 'clr', 'busy');
    }

    function sent(to: string) {
        $('#sentTo').textContent = to;
        show('sent');
        setAgainIn(45);
    }

    /* the steps' answers that every step shares: in, the code, the pause, or time ran out */
    function common(status: number, data: Answer): boolean {
        if (data.redirect) {
            signedIn(data.redirect);

            return true;
        }
        if (status === 423 && data.pausedFor) {
            pause(data.pausedFor);

            return true;
        }
        if (status === 409 && data.expired) {
            show('email');
            say('#emErr', 'That took too long. Sign in again.', $<HTMLInputElement>('#em'));

            return true;
        }

        return false;
    }

    /* ---------- first paint ---------- */
    useEffect(() => {
        $<HTMLInputElement>('#em').value = state.current.email;
        document.querySelectorAll<HTMLElement>('.ad-in').forEach((f) =>
            f.addEventListener('mousedown', (e) => {
                if (e.target === f) {
                    e.preventDefault();
                    f.querySelector('input')?.focus();
                }
            }),
        );
        const done = () => {
            document.documentElement.classList.remove('leaving');
            loading(false);
        };
        const pageshow = (e: PageTransitionEvent) => e.persisted && done();
        window.addEventListener('pageshow', pageshow);

        if (props.pausedFor > 0) {
            setPauseLeft(props.pausedFor);
            show('paused', false);
        } else if (props.step === 'code') {
            show('code');
        } else if (props.step === 'reset') {
            show('reset');
        } else {
            show('email');
            if (props.error) {
                say('#emErr', props.error);
            }
        }

        return () => window.removeEventListener('pageshow', pageshow);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* ---------- the two countdowns: Send again, and the pause ---------- */
    useEffect(() => {
        if (againIn <= 0) {
            return;
        }
        const t = window.setTimeout(() => setAgainIn(againIn - 1), 1000);

        return () => window.clearTimeout(t);
    }, [againIn]);

    useEffect(() => {
        if (pauseLeft <= 0) {
            if (state.current.cur === 'paused') {
                $<HTMLInputElement>('#pw').value = '';
                show(state.current.email ? 'pw' : 'email');
            }

            return;
        }
        const t = window.setTimeout(() => setPauseLeft(pauseLeft - 1), 1000);

        return () => window.clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pauseLeft]);

    /* ---------- email ---------- */
    async function onEmail(e: FormEvent) {
        e.preventDefault();
        const input = $<HTMLInputElement>('#em');
        const v = input.value.trim();
        if (!EMAIL.test(v)) {
            say('#emErr', v ? 'That doesn’t look like an email.' : 'Type your email first.', input);
            input.focus();

            return;
        }
        say('#emErr', '', input);
        state.current.email = v;
        const go = $('#emGo');
        busy(go, true);
        const { status, data } = await json<Answer>('POST', '/sign-in/email', { email: v });
        busy(go, false);
        if (status !== 200) {
            say('#emErr', status === 0 ? OFFLINE : 'That doesn’t look like an email.', input);

            return;
        }
        if (data.pausedFor) {
            pause(data.pausedFor);

            return;
        }
        const pw = $<HTMLInputElement>('#pw');
        pw.value = '';
        say('#pwErr', '', pw);
        show('pw');
    }

    /* ---------- password ---------- */
    async function onPassword(e: FormEvent) {
        e.preventDefault();
        const input = $<HTMLInputElement>('#pw');
        if (!input.value) {
            say('#pwErr', 'Type your password first.', input);
            input.focus();

            return;
        }
        const go = $('#pwGo');
        busy(go, true);
        const { status, data } = await json<Answer>('POST', '/sign-in/password', { email: state.current.email, password: input.value });
        busy(go, false);
        if (common(status, data)) {
            say('#pwErr', '', input);

            return;
        }
        if (data.next === 'code') {
            clearCode();
            say('#cdErr', '');
            show('code');

            return;
        }
        say('#pwErr', status === 0 ? OFFLINE : (data.message ?? 'That password isn’t right.'), input);
        nudge(input.closest('.ad-in')!);
        input.select();
    }

    /* ---------- code ---------- */
    const codeValue = () => cells().map((c) => c.value).join('');

    async function checkCode() {
        const code = $('#code');
        const all = cells();
        code.classList.add('busy');
        all.forEach((c) => c.blur());
        loading(true);
        const { status, data } = await json<Answer>('POST', '/sign-in/code', { code: codeValue(), remember: $<HTMLInputElement>('#rem').checked });
        code.classList.remove('busy');
        loading(false);
        if (common(status, data)) {
            return;
        }
        code.classList.add('bad');
        nudge(code);
        say('#cdErr', status === 0 ? OFFLINE : (data.message ?? 'That code isn’t right. Try the newest one.'));
        await wait(500);
        code.classList.add('clr');
        await wait(320);
        all.forEach((c) => (c.value = ''));
        code.classList.remove('clr');
        all[0].focus();
    }

    function fill(i: number, digits: string) {
        const all = cells();
        for (let k = 0; k < digits.length && i + k < 6; k++) {
            all[i + k].value = digits[k];
        }
        all[Math.min(5, i + digits.length)].focus();
        if (codeValue().length === 6) {
            void checkCode();
        }
    }

    function onCell(i: number, c: HTMLInputElement) {
        const d = c.value.replace(/\D/g, '');
        if (d.length > 1) {
            fill(i, d);

            return;
        }
        c.value = d;
        if (d) {
            c.classList.remove('typed');
            void c.offsetWidth;
            c.classList.add('typed');
        }
        const code = $('#code');
        if (code.classList.contains('bad')) {
            code.classList.remove('bad');
            say('#cdErr', '');
        }
        if (d && i < 5) {
            cells()[i + 1].focus();
        }
        if (codeValue().length === 6) {
            void checkCode();
        }
    }

    function onCellKey(i: number, e: React.KeyboardEvent<HTMLInputElement>) {
        const all = cells();
        if (e.key === 'Backspace' && !e.currentTarget.value && i > 0) {
            all[i - 1].value = '';
            all[i - 1].focus();
            e.preventDefault();
        }
        if (e.key === 'ArrowLeft' && i > 0) {
            all[i - 1].focus();
        }
        if (e.key === 'ArrowRight' && i < 5) {
            all[i + 1].focus();
        }
    }

    /* ---------- backup code ---------- */
    async function onBackup(e: FormEvent) {
        e.preventDefault();
        const input = $<HTMLInputElement>('#bk');
        const v = input.value.trim();
        if (!v) {
            say('#bkErr', 'Type a backup code first.', input);

            return;
        }
        const go = $('#bkGo');
        busy(go, true);
        const { status, data } = await json<Answer>('POST', '/sign-in/backup', { code: v });
        busy(go, false);
        if (common(status, data)) {
            return;
        }
        say('#bkErr', status === 0 ? OFFLINE : (data.message ?? 'That backup code isn’t right.'), input);
        input.select();
    }

    /* ---------- Forgot it?, the emailed link, Send again ---------- */
    async function onForgot(e: FormEvent) {
        e.preventDefault();
        const input = $<HTMLInputElement>('#fe');
        const v = input.value.trim();
        if (!EMAIL.test(v)) {
            say('#feErr', v ? 'That doesn’t look like an email.' : 'Type your email first.', input);

            return;
        }
        const go = $('#feGo');
        busy(go, true);
        const { status } = await json('POST', '/sign-in/forgot', { email: v });
        busy(go, false);
        if (status !== 200) {
            say('#feErr', status === 0 ? OFFLINE : 'Too many tries. Wait a minute.', input);

            return;
        }
        state.current.sentKind = 'forgot';
        sent(v);
    }

    async function mailLink(b: HTMLElement) {
        const s = state.current;
        let to = s.email;
        if (s.cur === 'email') {
            const input = $<HTMLInputElement>('#em');
            const v = input.value.trim();
            if (!EMAIL.test(v)) {
                say('#emErr', v ? 'That doesn’t look like an email.' : 'Type your email first.', input);
                input.focus();

                return;
            }
            to = s.email = v;
        }
        if (!to) {
            show('email');

            return;
        }
        busy(b, true);
        const { status } = await json('POST', '/sign-in/link', { email: to });
        busy(b, false);
        if (status !== 200) {
            const where = s.cur === 'pw' ? '#pwErr' : s.cur === 'paused' ? null : '#emErr';
            if (where) {
                say(where, status === 0 ? OFFLINE : 'Too many tries. Wait a minute.');
            }

            return;
        }
        s.sentKind = 'link';
        sent(to);
    }

    async function again() {
        const to = $('#sentTo').textContent ?? '';
        const kind = state.current.sentKind;
        setAgainIn(45);
        await json('POST', kind === 'forgot' ? '/sign-in/forgot' : '/sign-in/link', { email: to });
    }

    function goBack() {
        const s = state.current;
        show(s.cur === 'forgot' ? 'pw' : s.back === 'forgot' ? 'pw' : s.back);
    }

    /* ---------- Google: away to Google and back (the code follows on a new device) ---------- */
    function google(b: HTMLElement) {
        busy(b, true);
        window.location.href = '/auth/google';
    }

    /* ---------- New password (from the reset email) ---------- */
    async function onReset(e: FormEvent) {
        e.preventDefault();
        const input = $<HTMLInputElement>('#np');
        if (!input.value) {
            say('#npErr', 'Type a password.', input);

            return;
        }
        const go = $('#npGo');
        busy(go, true);
        const { status, data } = await json<Answer>('POST', '/sign-in/new-password', { password: input.value });
        busy(go, false);
        if (common(status, data)) {
            return;
        }
        if (data.next === 'code') {
            clearCode();
            show('code');

            return;
        }
        say('#npErr', status === 0 ? OFFLINE : (data.errors?.password?.[0] ?? 'That link doesn’t work any more.'), input);
        input.select();
    }

    async function leaveReset() {
        await json('DELETE', '/sign-in/new-password');
        show('email');
    }

    const googleButton = (
        <button className="ad-a ml ad-goog" type="button" onClick={(e) => google(e.currentTarget)}>
            <Google />
            <span>Continue with Google</span>
        </button>
    );
    const linkButton = (
        <button className="ad-a ml" type="button" onClick={(e) => mailLink(e.currentTarget)}>
            <Mail />
            <span>Email me a sign-in link</span>
        </button>
    );
    const backButton = (
        <button className="ad-a ml" type="button" onClick={goBack}>
            <Back />
            <span>Back</span>
        </button>
    );
    const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

    return (
        <>
            <Head title="Sign in" />
            <main className="ad-page">
                <AdminTop />
                <div className="ad-form">
                    <h1 className="ad-h" id="adH" ref={heading} aria-live="polite">
                        {title}
                    </h1>
                    <div className="ad-stage">
                        <form className="ad-step" id="s-email" noValidate onSubmit={onEmail}>
                            <div className="ad-f">
                                <label className="ad-l" htmlFor="em">Email</label>
                                <span className="ad-row">
                                    <span className="ad-in">
                                        <input id="em" type="email" autoComplete="username" spellCheck={false} autoCapitalize="off" inputMode="email" onInput={(e) => say('#emErr', '', e.currentTarget)} />
                                    </span>
                                    <NextButton id="emGo" label="Continue" />
                                </span>
                            </div>
                            <Err id="emErr" />
                            <div className="ad-alt">
                                {googleButton}
                                {linkButton}
                            </div>
                        </form>

                        <form className="ad-step" id="s-pw" noValidate onSubmit={onPassword}>
                            <div className="ad-chip">
                                <span id="emShow" />
                                <button className="ad-a sm" type="button" onClick={() => show('email')}>Change</button>
                            </div>
                            <div className="ad-f">
                                <span className="ad-l">
                                    <label htmlFor="pw">Password</label>
                                    <button
                                        className="ad-a sm"
                                        type="button"
                                        onClick={() => {
                                            const fe = $<HTMLInputElement>('#fe');
                                            fe.value = state.current.email;
                                            say('#feErr', '', fe);
                                            show('forgot');
                                        }}
                                    >
                                        Forgot it?
                                    </button>
                                </span>
                                <span className="ad-row">
                                    <span className="ad-in">
                                        <input id="pw" type={eye ? 'text' : 'password'} autoComplete="current-password" onInput={(e) => say('#pwErr', '', e.currentTarget)} />
                                        <button
                                            className="ad-eye"
                                            type="button"
                                            aria-label={eye ? 'Hide password' : 'Show password'}
                                            aria-pressed={eye}
                                            onClick={() => {
                                                setEye(!eye);
                                                $('#pw').focus();
                                            }}
                                        >
                                            <span className="on"><Eye /></span>
                                            <span className="off"><EyeOff /></span>
                                        </button>
                                    </span>
                                    <NextButton id="pwGo" label="Sign in" />
                                </span>
                            </div>
                            <Err id="pwErr" />
                            <div className="ad-alt">
                                {googleButton}
                                {linkButton}
                            </div>
                        </form>

                        <form className="ad-step" id="s-code" noValidate onSubmit={(e) => e.preventDefault()}>
                            <p className="ad-p">From your authenticator app.</p>
                            <div className="ad-code" id="code">
                                {[0, 1, 2, 3, 4, 5].map((i) => (
                                    <input
                                        key={i}
                                        className="ad-c"
                                        inputMode="numeric"
                                        maxLength={i === 0 ? 6 : 1}
                                        aria-label={`Digit ${i + 1}`}
                                        autoComplete={i === 0 ? 'one-time-code' : 'off'}
                                        onInput={(e) => onCell(i, e.currentTarget)}
                                        onKeyDown={(e) => onCellKey(i, e)}
                                        onPaste={(e) => {
                                            e.preventDefault();
                                            fill(i, (e.clipboardData.getData('text') || '').replace(/\D/g, ''));
                                        }}
                                        onFocus={(e) => e.currentTarget.select()}
                                    />
                                ))}
                            </div>
                            <Err id="cdErr" />
                            <label className="ad-cb">
                                <input type="checkbox" id="rem" defaultChecked />
                                <span className="ad-box"><Tick /></span>
                                Remember this device for 30 days
                            </label>
                            <div className="ad-alt">
                                <button
                                    className="ad-a ml"
                                    type="button"
                                    onClick={() => {
                                        const bk = $<HTMLInputElement>('#bk');
                                        bk.value = '';
                                        say('#bkErr', '', bk);
                                        show('backup');
                                    }}
                                >
                                    Use a backup code
                                </button>
                            </div>
                        </form>

                        <form className="ad-step" id="s-backup" noValidate onSubmit={onBackup}>
                            <div className="ad-f">
                                <label className="ad-l" htmlFor="bk">Backup code</label>
                                <span className="ad-row">
                                    <span className="ad-in">
                                        <input id="bk" autoComplete="off" spellCheck={false} autoCapitalize="off" onInput={(e) => say('#bkErr', '', e.currentTarget)} />
                                    </span>
                                    <NextButton id="bkGo" label="Confirm" />
                                </span>
                            </div>
                            <Err id="bkErr" />
                            <div className="ad-alt">
                                <button
                                    className="ad-a ml"
                                    type="button"
                                    onClick={() => {
                                        clearCode();
                                        say('#cdErr', '');
                                        show('code');
                                    }}
                                >
                                    Use the app instead
                                </button>
                            </div>
                        </form>

                        <form className="ad-step" id="s-forgot" noValidate onSubmit={onForgot}>
                            <div className="ad-f">
                                <label className="ad-l" htmlFor="fe">Email</label>
                                <span className="ad-row">
                                    <span className="ad-in">
                                        <input id="fe" type="email" autoComplete="username" spellCheck={false} autoCapitalize="off" inputMode="email" onInput={(e) => say('#feErr', '', e.currentTarget)} />
                                    </span>
                                    <NextButton id="feGo" label="Send link" />
                                </span>
                            </div>
                            <Err id="feErr" />
                            <div className="ad-alt">{backButton}</div>
                        </form>

                        <div className="ad-step" id="s-sent">
                            <div className="ad-note">
                                <span className="ad-ni"><Mail /></span>
                                <p>
                                    Sent to <b id="sentTo" />. The link works for {state.current.sentKind === 'forgot' ? 'an hour' : '15 minutes'}.
                                </p>
                            </div>
                            <div className="ad-alt">
                                <button className="ad-a ml" type="button" disabled={againIn > 0} onClick={again}>
                                    {againIn > 0 ? (
                                        <>
                                            Send again in <span id="againT">{clock(againIn)}</span>
                                        </>
                                    ) : (
                                        'Send again'
                                    )}
                                </button>
                                {backButton}
                            </div>
                        </div>

                        <div className="ad-step" id="s-paused">
                            <div className="ad-note">
                                <span className="ad-ni"><Clock /></span>
                                <p>
                                    Too many tries. Sign-in opens again in <b id="pauseT">{clock(pauseLeft)}</b>.
                                </p>
                            </div>
                            <div className="ad-alt">{linkButton}</div>
                        </div>

                        <form className="ad-step" id="s-reset" noValidate onSubmit={onReset}>
                            <div className="ad-f">
                                <label className="ad-l" htmlFor="np">New password</label>
                                <span className="ad-row">
                                    <span className="ad-in">
                                        <input id="np" type={resetEye ? 'text' : 'password'} autoComplete="new-password" onInput={(e) => say('#npErr', '', e.currentTarget)} />
                                        <button
                                            className="ad-eye"
                                            type="button"
                                            aria-label={resetEye ? 'Hide password' : 'Show password'}
                                            aria-pressed={resetEye}
                                            onClick={() => {
                                                setResetEye(!resetEye);
                                                $('#np').focus();
                                            }}
                                        >
                                            <span className="on"><Eye /></span>
                                            <span className="off"><EyeOff /></span>
                                        </button>
                                    </span>
                                    <NextButton id="npGo" label="Save" />
                                </span>
                            </div>
                            <Err id="npErr" />
                            <div className="ad-alt">
                                <button className="ad-a ml" type="button" onClick={leaveReset}>
                                    <Back />
                                    <span>Back</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </>
    );
}

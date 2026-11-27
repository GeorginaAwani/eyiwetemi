import { Link } from '@inertiajs/react';
import { CSSProperties, FormEvent, Fragment, ReactNode, useEffect, useRef, useState } from 'react';
import { KSelect, Unfold, wait } from '../../Components/admin/Controls';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { Av, PI } from '../../Components/admin/People';
import AdminLayout from '../../Layouts/AdminLayout';
import { beacon, json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Reply = { id: number; who: string; seed: string; me: boolean; at: string; text: string };
type RComment = { id: number; who: string; seed: string; at: string; text: string; likes: number; liked: boolean; hidden: boolean; new: boolean; replies: Reply[] };
type GroupWork = { slug: string; title: string; cover: CoverData };
type RGroup = { key: string; item: number; work: GroupWork; title: string; sub: string; new: number; open: string; comments: RComment[]; earlier: number };
type VNote = {
    id: number;
    who: string;
    seed: string;
    at: string;
    kind: 'line' | 'edit' | 'comment' | 'book';
    scope: string | null;
    pre: string;
    quote: string;
    post: string;
    del: string;
    ins: string;
    text: string;
    status: 'open' | 'accepted' | 'rejected' | 'resolved';
    new: boolean;
    replies: Reply[];
};
type VGroup = { key: string; item: number | null; work: GroupWork; title: string; sub: string; new: number; open: string; notes: VNote[] };
type WorkOpt = { slug: string; title: string; new: number };
type Tab = 'r' | 'v';
type Props = {
    tab: Tab;
    w: string | null;
    who: string | null;
    counts: { r: number; v: number };
    works: { r: WorkOpt[]; v: WorkOpt[] };
    mail: { r: boolean; v: boolean };
    me: { name: string; photo: string | null };
};
type Readers = { groups: RGroup[]; total: number; more: boolean; hits: number | null; page: number };
type Reviewers = { open: VGroup[]; done: VGroup[]; hits: number | null };

const DONE: Record<string, string> = { accepted: 'Accepted', rejected: 'Rejected', resolved: 'Resolved' };

/** The search term marked where it appears. */
function hl(text: string, q: string): ReactNode {
    if (!q) {
        return text;
    }
    const i = text.toLowerCase().indexOf(q);

    return i < 0 ? (
        text
    ) : (
        <>
            {text.slice(0, i)}
            <mark className="cm-mk">{text.slice(i, i + q.length)}</mark>
            {text.slice(i + q.length)}
        </>
    );
}

/** A row leaves: its height closes as it fades (a plain fade with reduced motion). */
function bye(el: Element | null): Promise<void> {
    if (!(el instanceof HTMLElement)) {
        return Promise.resolve();
    }
    if (prefersReducedMotion()) {
        return el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' }).finished.then(() => undefined);
    }
    el.style.overflow = 'hidden';

    return el
        .animate(
            [
                { height: el.offsetHeight + 'px', opacity: 1, filter: 'none' },
                { height: '0px', opacity: 0, filter: 'blur(4px)' },
            ],
            { duration: 320, easing: 'cubic-bezier(.22,.8,.2,1)', fill: 'forwards' },
        )
        .finished.then(() => undefined);
}

/** Something new arrives in place. */
function arrive(el: Element | null) {
    if (!(el instanceof HTMLElement)) {
        return;
    }
    if (prefersReducedMotion()) {
        el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200 });

        return;
    }
    el.animate(
        [
            { opacity: 0, filter: 'blur(4px)', transform: 'translateY(4px)' },
            { opacity: 1, filter: 'none', transform: 'none' },
        ],
        { duration: 420, easing: 'cubic-bezier(.22,.8,.2,1)' },
    );
}

function ReplyRow({ r, me, q }: { r: Reply; me: Props['me']; q: string }) {
    return (
        <div className="cm-rp" data-r={r.id}>
            <Av seed={r.seed} size={28} me={r.me} photo={me.photo} />
            <div className="bd">
                <div className="hd">
                    <b>{r.me ? me.name.split(' ')[0] : r.who}</b>
                    {r.me && <span className="cm-at">Author</span>}
                    <span className="tm">{r.at}</span>
                </div>
                <p>{hl(r.text, q)}</p>
            </div>
        </div>
    );
}

/** The reply field under a comment or note: it unfolds in place; Enter sends, Esc folds it away. */
function ReplyForm({ show, me, onSend, onClose }: { show: boolean; me: Props['me']; onSend: (text: string) => Promise<boolean>; onClose: () => void }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (show) {
            window.setTimeout(() => input.current?.focus(), prefersReducedMotion() ? 0 : 220);
        }
    }, [show]);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!text.trim() || busy) {
            return;
        }
        setBusy(true);
        if (await onSend(text.trim())) {
            setText('');
        }
        setBusy(false);
    }

    return (
        <Unfold show={show} className="pp-rfu">
            <form
                className="cm-rf"
                onSubmit={submit}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.stopPropagation();
                        onClose();
                    }
                }}
            >
                <span className="cm-me">
                    <Av seed="me" size={28} me photo={me.photo} />
                </span>
                <span className="k-in sm">
                    <input ref={input} placeholder="Reply" aria-label="Reply" autoComplete="off" value={text} onChange={(e) => setText(e.target.value)} />
                </span>
                <button className="ad-ic cm-snd" type="submit" aria-label="Send" disabled={!text.trim() || busy}>
                    {PI.send}
                </button>
            </form>
        </Unfold>
    );
}

function GroupHead({ g, q, right }: { g: { work: GroupWork; title: string; sub: string; new: number }; q: string; right: ReactNode }) {
    return (
        <div className="b-gh">
            <Cover cover={g.work.cover} title={g.work.title} style={{ width: 30, height: 45 }} />
            <div className="tt">
                <h2>{hl(g.title, q)}</h2>
                <span className="k-meta">{g.sub}</span>
            </div>
            {g.new > 0 && <span className="cm-new">{g.new} new</span>}
            {right}
        </div>
    );
}

/**
 * Comments: one inbox for every work. Readers' comments and reviewers' notes in two tabs, grouped by chapter, with a
 * work filter and a search. Replies unfold in place; what she has looked at stops counting as new once she leaves.
 */
export default function Comments(props: Props) {
    const [tab, setTab] = useState<Tab>(props.tab);
    const [wf, setWf] = useState(props.w ?? '');
    const [who, setWho] = useState(props.who ?? '');
    const [query, setQuery] = useState('');
    const [q, setQ] = useState('');
    const [counts, setCounts] = useState(props.counts);
    const [mail, setMail] = useState(props.mail);
    const [readers, setReaders] = useState<Readers | null>(null);
    const [reviewers, setReviewers] = useState<Reviewers | null>(null);
    const [out, setOut] = useState(true);
    const [settleKey, setSettleKey] = useState(0);
    const [replying, setReplying] = useState<string>('');
    const [resOpen, setResOpen] = useState(false);
    const [pop, setPop] = useState(false);
    const [popAt, setPopAt] = useState<CSSProperties>({});
    const [stale, setStale] = useState<Record<number, string>>({});
    const list = useRef<HTMLDivElement>(null);
    const emailsButton = useRef<HTMLButtonElement>(null);
    const popBox = useRef<HTMLDivElement>(null);
    const seen = useRef(new Set<number>());
    const timer = useRef(0);
    const first = useRef(true);
    const { me } = props;

    /* the margin's count follows the tabs' */
    useEffect(() => {
        window.dispatchEvent(new CustomEvent('admin:comments-new', { detail: counts.r + counts.v }));
    }, [counts]);

    /* what she has looked at stops counting as new once she leaves the page */
    useEffect(() => {
        const send = () => {
            if (seen.current.size) {
                beacon('/comments/seen', { ids: [...seen.current] });
                seen.current.clear();
            }
        };
        const hidden = () => document.visibilityState === 'hidden' && send();
        window.addEventListener('pagehide', send);
        document.addEventListener('visibilitychange', hidden);

        return () => {
            send();
            window.removeEventListener('pagehide', send);
            document.removeEventListener('visibilitychange', hidden);
        };
    }, []);

    function url(t: Tab, w: string, p: string) {
        const u = new URLSearchParams();
        if (t === 'v') {
            u.set('tab', 'rev');
        }
        if (w) {
            u.set('w', w);
        }
        if (p) {
            u.set('who', p);
        }
        const s = u.toString();
        window.history.replaceState(window.history.state, '', '/comments' + (s ? '?' + s : ''));
    }

    async function load(t: Tab, w: string, p: string, term: string, page = 1): Promise<void> {
        const u = new URLSearchParams();
        if (w) {
            u.set('w', w);
        }
        if (term) {
            u.set('q', term);
        }
        if (t === 'r') {
            u.set('page', String(page));
            const { data } = await json<Readers>('GET', '/comments/readers?' + u);
            const next = { groups: data.groups ?? [], total: data.total ?? 0, more: !!data.more, hits: data.hits ?? null, page };
            setReaders((prev) => (page > 1 && prev ? { ...next, groups: [...prev.groups, ...next.groups] } : next));
        } else {
            if (p) {
                u.set('who', p);
            }
            const { data } = await json<Reviewers>('GET', '/comments/reviewers?' + u);
            setReviewers({ open: data.open ?? [], done: data.done ?? [], hits: data.hits ?? null });
        }
    }

    /* a new tab, work, person or search fades the list out and lets the new one settle in */
    useEffect(() => {
        let gone = false;
        (async () => {
            if (!first.current) {
                setOut(true);
            }
            const [, waited] = await Promise.all([load(tab, wf, who, q), wait(first.current || prefersReducedMotion() ? 0 : 160)]);
            void waited;
            if (!gone) {
                first.current = false;
                setOut(false);
                setSettleKey((k) => k + 1);
            }
        })();

        return () => {
            gone = true;
        };
    }, [tab, wf, who, q]);

    /* remember what was shown as new */
    useEffect(() => {
        readers?.groups.forEach((g) => g.comments.forEach((c) => c.new && seen.current.add(c.id)));
        reviewers?.open.forEach((g) => g.notes.forEach((n) => n.new && seen.current.add(n.id)));
    }, [readers, reviewers]);

    useEffect(() => {
        const el = list.current;
        if (!el || !settleKey) {
            return;
        }
        el.classList.remove('settle');
        void el.offsetWidth;
        el.classList.add('settle');
    }, [settleKey]);

    /* the Emails popover sits under its button and closes on Esc or a click outside */
    useEffect(() => {
        if (!pop) {
            return;
        }
        const outside = (e: MouseEvent) => {
            const t = e.target as Element;
            if (!t.closest('#ppEmP,#ppEm')) {
                setPop(false);
            }
        };
        const esc = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape') {
                setPop(false);
                emailsButton.current?.focus();
            }
        };
        document.addEventListener('mousedown', outside);
        document.addEventListener('keydown', esc);

        return () => {
            document.removeEventListener('mousedown', outside);
            document.removeEventListener('keydown', esc);
        };
    }, [pop]);

    function openPop() {
        const b = emailsButton.current;
        const box = popBox.current?.offsetParent as HTMLElement | null;
        if (b && box) {
            const r = b.getBoundingClientRect();
            const m = box.getBoundingClientRect();
            setPopAt({ top: r.bottom - m.top + 10, left: Math.max(0, Math.min(r.right - m.left - 340, m.width - 340)) });
        }
        setPop(true);
        window.setTimeout(() => popBox.current?.querySelector<HTMLInputElement>('input')?.focus(), prefersReducedMotion() ? 0 : 120);
    }

    async function setMailOn(key: 'r' | 'v', on: boolean) {
        setMail((m) => ({ ...m, [key]: on }));
        const { status, data } = await json<{ mail: Props['mail'] }>('PATCH', '/comments/mail', { key, on });
        if (status === 200 && data.mail) {
            setMail(data.mail);
        }
    }

    function changeTab(t: Tab) {
        if (t === tab) {
            return;
        }
        setTab(t);
        setWf('');
        setWho('');
        setReplying('');
        url(t, '', '');
    }

    /* a comment or note she acts on is no longer new */
    function unNew(t: Tab, id: number, wasNew: boolean) {
        if (!wasNew) {
            return;
        }
        seen.current.delete(id);
        setCounts((c) => ({ ...c, [t]: Math.max(0, c[t] - 1) }));
        if (t === 'r') {
            setReaders((d) => d && { ...d, groups: d.groups.map((g) => ({ ...g, new: Math.max(0, g.new - (g.comments.some((c) => c.id === id) ? 1 : 0)), comments: g.comments.map((c) => (c.id === id ? { ...c, new: false } : c)) })) });
        } else {
            setReviewers((d) => d && { ...d, open: d.open.map((g) => ({ ...g, new: Math.max(0, g.new - (g.notes.some((n) => n.id === id) ? 1 : 0)), notes: g.notes.map((n) => (n.id === id ? { ...n, new: false } : n)) })) });
        }
    }

    const patchComment = (id: number, f: (c: RComment) => RComment) => setReaders((d) => d && { ...d, groups: d.groups.map((g) => ({ ...g, comments: g.comments.map((c) => (c.id === id ? f(c) : c)) })) });

    async function like(c: RComment, button: HTMLButtonElement) {
        const liked = !c.liked;
        patchComment(c.id, (x) => ({ ...x, liked, likes: x.likes + (liked ? 1 : -1) }));
        unNew('r', c.id, c.new);
        if (liked && !prefersReducedMotion()) {
            button.querySelector('svg')?.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.28)' }, { transform: 'scale(1)' }], { duration: 360, easing: 'cubic-bezier(.34,1.45,.64,1)' });
        }
        const { status, data } = await json<{ likes: number; liked: boolean }>(liked ? 'PUT' : 'DELETE', `/comments/${c.id}/like`);
        if (status === 200) {
            patchComment(c.id, (x) => ({ ...x, liked: !!data.liked, likes: data.likes ?? x.likes }));
        }
    }

    async function hide(c: RComment) {
        const card = list.current?.querySelector(`[data-id="${c.id}"]`);
        const hidden = !c.hidden;
        if (card instanceof HTMLElement && !prefersReducedMotion()) {
            await card.animate([{ opacity: 1 }, { opacity: 0.3 }], { duration: 140 }).finished;
        }
        patchComment(c.id, (x) => ({ ...x, hidden, new: false }));
        if (replying === 'r' + c.id) {
            setReplying('');
        }
        unNew('r', c.id, c.new);
        window.setTimeout(() => {
            const el = list.current?.querySelector<HTMLElement>(`[data-id="${c.id}"]`);
            if (el && !prefersReducedMotion()) {
                el.animate([{ opacity: 0.3, filter: 'blur(3px)' }, { opacity: 1, filter: 'none' }], { duration: 320 });
            }
            el?.querySelector<HTMLElement>(`[data-a="${hidden ? 'unhide' : 'hide'}"]`)?.focus();
        }, 0);
        await json('PATCH', `/comments/${c.id}/hide`, { hidden });
    }

    async function reply(t: Tab, id: number, wasNew: boolean, text: string): Promise<boolean> {
        const { status, data } = await json<{ reply: Reply }>('POST', `/comments/${id}/reply`, { body: text });
        if (status !== 201 || !data.reply) {
            return false;
        }
        const r = data.reply;
        if (t === 'r') {
            patchComment(id, (c) => ({ ...c, replies: [...c.replies, r] }));
        } else {
            setReviewers((d) => d && { ...d, open: d.open.map((g) => ({ ...g, notes: g.notes.map((n) => (n.id === id ? { ...n, replies: [...n.replies, r] } : n)) })) });
        }
        unNew(t, id, wasNew);
        setReplying('');
        window.setTimeout(() => {
            const card = list.current?.querySelector(`[data-${t === 'r' ? 'id' : 'key'}="${id}"]`);
            arrive(card?.querySelector(`[data-r="${r.id}"]`) ?? null);
            card?.querySelector<HTMLElement>('[data-a="reply"]')?.focus();
        }, 0);

        return true;
    }

    async function earlier(g: RGroup) {
        const u = q ? '?q=' + encodeURIComponent(q) : '';
        const { data } = await json<{ comments: RComment[] }>('GET', `/comments/readers/${g.item}${u}`);
        const all = data.comments ?? [];
        const have = new Set(g.comments.map((c) => c.id));
        setReaders((d) => d && { ...d, groups: d.groups.map((x) => (x.key === g.key ? { ...x, comments: all, earlier: 0 } : x)) });
        window.setTimeout(() => {
            const sec = list.current?.querySelector(`[data-g="${g.key}"]`);
            sec?.querySelectorAll('[data-id]').forEach((el, i) => {
                if (!have.has(Number((el as HTMLElement).dataset.id)) && !prefersReducedMotion()) {
                    (el as HTMLElement).animate([{ opacity: 0, filter: 'blur(4px)' }, { opacity: 1, filter: 'none' }], { duration: 380, delay: i * 30, fill: 'backwards' });
                }
            });
        }, 0);
    }

    async function status(n: VNote, to: 'accepted' | 'rejected' | 'resolved' | 'open') {
        const { status: code, data } = await json<{ note: VNote; message: string }>('PATCH', `/comments/${n.id}/status`, { status: to });
        if (code === 409) {
            setStale((s) => ({ ...s, [n.id]: data.message ?? 'That line has changed.' }));

            return;
        }
        if (code !== 200 || !data.note) {
            return;
        }
        const row = list.current?.querySelector(`[data-key="${n.id}"]`);
        unNew('v', n.id, n.new);
        await bye(row ?? null);
        const moved = data.note;
        setReviewers((d) => {
            if (!d) {
                return d;
            }
            const from = to === 'open' ? d.done : d.open;
            const into = to === 'open' ? d.open : d.done;
            const g = from.find((x) => x.notes.some((y) => y.id === n.id));
            if (!g) {
                return d;
            }
            const left = from.map((x) => (x === g ? { ...x, notes: x.notes.filter((y) => y.id !== n.id) } : x)).filter((x) => x.notes.length);
            const target = into.find((x) => x.key === g.key);
            const added = target ? into.map((x) => (x === target ? { ...x, notes: [...x.notes, moved] } : x)) : [{ ...g, new: 0, notes: [moved] }, ...into];

            return to === 'open' ? { ...d, done: left, open: added } : { ...d, open: left, done: added };
        });
    }

    const term = q.toLowerCase();

    function readerComment(c: RComment) {
        const key = 'r' + c.id;

        return (
            <div className={`cm-c b${c.new && !c.hidden ? ' new' : ''}${c.hidden ? ' hid' : ''}`} data-id={c.id} key={c.id}>
                <span className="dot" />
                <Av seed={c.seed} size={36} />
                <div className="bd">
                    <div className="hd">
                        <b>{c.who}</b>
                        {c.hidden && (
                            <span className="cm-hid">
                                {PI.eyeoff(15)}
                                Hidden
                            </span>
                        )}
                        <span className="tm">{c.at}</span>
                    </div>
                    <p>{hl(c.text, term)}</p>
                    <div className="cm-acts">
                        <button className={`w-act cm-lk${c.liked ? ' on' : ''}`} type="button" data-a="like" aria-pressed={c.liked} aria-label="Like" onClick={(e) => like(c, e.currentTarget)}>
                            {PI.heart}
                            <span>{c.likes}</span>
                        </button>
                        {!c.hidden && (
                            <button className="w-act" type="button" data-a="reply" aria-expanded={replying === key} onClick={() => setReplying(replying === key ? '' : key)}>
                                {PI.reply}
                                <span>Reply</span>
                            </button>
                        )}
                        <button className="w-act" type="button" data-a={c.hidden ? 'unhide' : 'hide'} onClick={() => hide(c)}>
                            {c.hidden ? PI.eye : PI.eyeoff()}
                            <span>{c.hidden ? 'Unhide' : 'Hide'}</span>
                        </button>
                    </div>
                    <div className="cm-reps">
                        {c.replies.map((r) => (
                            <ReplyRow key={r.id} r={r} me={me} q={term} />
                        ))}
                    </div>
                    {!c.hidden && <ReplyForm show={replying === key} me={me} onSend={(t) => reply('r', c.id, c.new, t)} onClose={() => setReplying('')} />}
                </div>
            </div>
        );
    }

    function note(n: VNote) {
        const key = 'v' + n.id;
        const done = n.status !== 'open';
        const mid =
            n.kind === 'edit' && !done ? (
                <>
                    <del>{n.del}</del> <ins>{n.ins}</ins>
                </>
            ) : n.pre || n.post ? (
                <span className="pp-q">{hl(n.quote, term)}</span>
            ) : (
                hl(n.quote, term)
            );

        return (
            <div className={`b-pr${done ? ' done' : ''}${n.new && !done ? ' new' : ''}`} data-key={n.id} key={n.id}>
                {n.quote ? (
                    <blockquote className="cm-ql">
                        {hl(n.pre, term)}
                        {mid}
                        {hl(n.post, term)}
                    </blockquote>
                ) : (
                    <p className="cm-scope">{n.scope}</p>
                )}
                <div className="b-note">
                    <div className="hd">
                        <Av seed={n.seed} size={28} />
                        <b>{n.who}</b>
                        <span className="tm">{n.at}</span>
                    </div>
                    {done ? (
                        <span className="cm-hid">
                            {PI.check(15)}
                            {DONE[n.status]}
                        </span>
                    ) : (
                        n.kind === 'edit' && <span className="cm-tag">Suggested edit</span>
                    )}
                    {n.text && <p>{hl(n.text, term)}</p>}
                    <div className="cm-reps">
                        {n.replies.map((r) => (
                            <ReplyRow key={r.id} r={r} me={me} q={term} />
                        ))}
                    </div>
                    <div className="cm-acts">
                        {done ? (
                            n.status === 'resolved' && (
                                <button className="w-act" type="button" data-a="reopen" onClick={() => status(n, 'open')}>
                                    {PI.undo}
                                    <span>Reopen</span>
                                </button>
                            )
                        ) : (
                            <>
                                {n.kind === 'edit' ? (
                                    <>
                                        <button className="w-act" type="button" data-a="acc" onClick={() => status(n, 'accepted')}>
                                            {PI.check()}
                                            <span>Accept</span>
                                        </button>
                                        <button className="w-act" type="button" data-a="rej" onClick={() => status(n, 'rejected')}>
                                            {PI.x()}
                                            <span>Reject</span>
                                        </button>
                                    </>
                                ) : (
                                    <button className="w-act" type="button" data-a="res" onClick={() => status(n, 'resolved')}>
                                        {PI.check()}
                                        <span>Resolve</span>
                                    </button>
                                )}
                                <button className="w-act" type="button" data-a="reply" aria-expanded={replying === key} onClick={() => setReplying(replying === key ? '' : key)}>
                                    {PI.reply}
                                    <span>Reply</span>
                                </button>
                            </>
                        )}
                    </div>
                    <Unfold show={!!stale[n.id]}>
                        <div className="k-err" role="alert">
                            <p>
                                {PI.alert}
                                <span className="t">{stale[n.id]}</span>
                            </p>
                        </div>
                    </Unfold>
                    {!done && <ReplyForm show={replying === key} me={me} onSend={(t) => reply('v', n.id, n.new, t)} onClose={() => setReplying('')} />}
                </div>
            </div>
        );
    }

    const block = (groups: VGroup[]) => (
        <div className="b-st">
            {groups.map((g) => (
                <section className="b-g" key={g.key}>
                    <GroupHead
                        g={g}
                        q={term}
                        right={
                            <Link className="k-l b-ga" href={g.open}>
                                {PI.pen}
                                <span>Open in draft</span>
                            </Link>
                        }
                    />
                    {g.notes.map(note)}
                </section>
            ))}
        </div>
    );

    function readersList(d: Readers) {
        if (!d.groups.length) {
            return (
                <div className="cm-empty">
                    {q ? (
                        <h2>No comments match “{q}”.</h2>
                    ) : (
                        <>
                            <h2>No comments yet.</h2>
                            <p>Readers’ comments land here.</p>
                        </>
                    )}
                </div>
            );
        }

        return (
            <>
                {d.hits !== null && (
                    <p className="cm-hits">
                        {d.hits} {d.hits === 1 ? 'comment' : 'comments'}
                    </p>
                )}
                <div className="b-st">
                    {d.groups.map((g) => (
                        <section className="b-g" data-g={g.key} key={g.key}>
                            <GroupHead
                                g={g}
                                q={term}
                                right={
                                    <a className="k-l b-ga" href={g.open} target="_blank" rel="noopener">
                                        <span>Open</span>
                                        {PI.ext}
                                    </a>
                                }
                            />
                            {g.comments.map(readerComment)}
                            {g.earlier > 0 && (
                                <button className="w-act b-gm" type="button" onClick={() => earlier(g)}>
                                    {PI.chev(17)}
                                    <span>{g.earlier} earlier</span>
                                </button>
                            )}
                        </section>
                    ))}
                </div>
                {d.more && (
                    <div className="a-more">
                        <button
                            className="k-btn line sm"
                            type="button"
                            onClick={async () => {
                                await load('r', wf, who, q, d.page + 1);
                                setSettleKey((k) => k + 1);
                            }}
                        >
                            Show more
                        </button>
                        <span className="k-meta">
                            {d.groups.length} of {d.total} {d.total === 1 ? 'place' : 'places'}
                        </span>
                    </div>
                )}
            </>
        );
    }

    function reviewersList(d: Reviewers) {
        const total = d.open.reduce((n, g) => n + g.notes.length, 0) + d.done.reduce((n, g) => n + g.notes.length, 0);
        const doneCount = d.done.reduce((n, g) => n + g.notes.length, 0);

        return (
            <>
                {d.hits !== null && (
                    <p className="cm-hits">
                        {total} {total === 1 ? 'note' : 'notes'}
                    </p>
                )}
                {d.open.length ? (
                    block(d.open)
                ) : (
                    <div className="cm-empty">
                        {q ? (
                            <h2>Nothing matches “{q}”.</h2>
                        ) : (
                            <>
                                <h2>Nothing open.</h2>
                                <p>Your reviewers’ notes land here.</p>
                            </>
                        )}
                    </div>
                )}
                {doneCount > 0 && (
                    <>
                        <button className={`w-act b-res${resOpen ? ' open' : ''}`} type="button" aria-expanded={resOpen} onClick={() => setResOpen(!resOpen)}>
                            {PI.chev(17)}
                            <span>{doneCount} resolved</span>
                        </button>
                        <Unfold show={resOpen}>{block(d.done)}</Unfold>
                    </>
                )}
            </>
        );
    }

    const works = props.works[tab === 'r' ? 'r' : 'v'];
    const newTotal = works.reduce((n, w) => n + w.new, 0);
    const options = [
        { value: '', label: 'All works', note: newTotal ? `${newTotal} new` : undefined },
        ...(wf && !works.some((w) => w.slug === wf) ? [{ value: wf, label: wf }] : []),
        ...works.map((w) => ({ value: w.slug, label: w.title, note: w.new ? `${w.new} new` : undefined })),
    ];
    const whoSeed = reviewers?.open.concat(reviewers.done).flatMap((g) => g.notes).find((n) => n.who === who)?.seed ?? who;

    return (
        <AdminLayout section="comments">
            <div className="b-wh">
                <h1 className="b-h" id="sT">
                    Comments
                </h1>
                <button className="w-act cm-em" type="button" id="ppEm" ref={emailsButton} aria-haspopup="dialog" aria-expanded={pop} onClick={() => (pop ? setPop(false) : openPop())}>
                    {PI.mail}
                    <span>Emails</span>
                </button>
            </div>
            <div className="b-tools cm-tools">
                <div className="k-tabs" role="tablist">
                    {(
                        [
                            ['r', 'Readers', counts.r],
                            ['v', 'Reviewers', counts.v],
                        ] as [Tab, string, number][]
                    ).map(([id, label, n]) => (
                        <a
                            key={id}
                            href="#"
                            role="tab"
                            className={tab === id ? 'on' : ''}
                            aria-selected={tab === id}
                            onClick={(e) => {
                                e.preventDefault();
                                changeTab(id);
                            }}
                        >
                            {label}
                            <small className="cm-n">{n || ''}</small>
                        </a>
                    ))}
                </div>
                <div className="cm-tr">
                    <KSelect
                        key={tab}
                        className="cm-wsw"
                        buttonClassName="k-sbtn cm-ws"
                        label="Work"
                        value={wf}
                        options={options}
                        onPick={(v) => {
                            setWf(v);
                            url(tab, v, who);
                        }}
                    />
                    <label className="k-search cm-q" htmlFor="ppQ">
                        {PI.search}
                        <input
                            id="ppQ"
                            type="search"
                            placeholder="Search"
                            autoComplete="off"
                            aria-label="Search comments"
                            value={query}
                            onChange={(e) => {
                                const v = e.target.value;
                                setQuery(v);
                                window.clearTimeout(timer.current);
                                timer.current = window.setTimeout(() => setQ(v.trim()), 180);
                            }}
                        />
                        {query && (
                            <button
                                className="ad-ic"
                                type="button"
                                aria-label="Clear search"
                                onClick={() => {
                                    setQuery('');
                                    setQ('');
                                }}
                            >
                                {PI.x(16)}
                            </button>
                        )}
                    </label>
                </div>
            </div>
            <div id="ppWho">
                {who && tab === 'v' && (
                    <div className="pp-who">
                        <span className="k-chip">
                            <Av seed={whoSeed} size={22} />
                            <span>{who}</span>
                            <button
                                className="ad-ic"
                                type="button"
                                aria-label="Show everyone"
                                onClick={() => {
                                    setWho('');
                                    url(tab, wf, '');
                                }}
                            >
                                {PI.x(16)}
                            </button>
                        </span>
                    </div>
                )}
            </div>
            <div className={`pp-list${out ? ' pp-out' : ''}`} id="ppL" aria-live="polite" ref={list}>
                {tab === 'r' ? readers && readersList(readers) : reviewers && reviewersList(reviewers)}
            </div>
            <div className={`cm-pop${pop ? ' on' : ''}`} id="ppEmP" role="dialog" aria-label="Emails" ref={popBox} style={popAt}>
                <button
                    className="ad-ic x"
                    type="button"
                    aria-label="Close"
                    onClick={() => {
                        setPop(false);
                        emailsButton.current?.focus();
                    }}
                >
                    {PI.x(16)}
                </button>
                <h3>Emails</h3>
                {(
                    [
                        ['r', 'Readers’ comments, once a day', '20:00, Nigeria time'],
                        ['v', 'Reviewers’ comments, straight away', 'Once per visit, not per comment'],
                    ] as ['r' | 'v', string, string][]
                ).map(([k, label, small]) => (
                    <Fragment key={k}>
                        <label className="ad-cb">
                            <input type="checkbox" checked={mail[k]} onChange={(e) => setMailOn(k, e.target.checked)} />
                            <span className="ad-box">{PI.tick()}</span>
                            <span>
                                {label}
                                <small>{small}</small>
                            </span>
                        </label>
                    </Fragment>
                ))}
            </div>
        </AdminLayout>
    );
}

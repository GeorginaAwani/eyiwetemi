import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Unfold, wait } from '../../Components/admin/Controls';
import { Ring } from '../../Components/admin/Icons';
import { Av, INVITED, openInvite, Person, PI, WorkChoice, WorkPicks } from '../../Components/admin/People';
import AdminLayout from '../../Layouts/AdminLayout';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Tab = 'all' | 'inv';

/** A row leaves: its height closes as it fades (a plain fade with reduced motion). */
async function bye(el: Element | null) {
    if (!(el instanceof HTMLElement)) {
        return;
    }
    if (prefersReducedMotion()) {
        await el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' }).finished;

        return;
    }
    el.style.overflow = 'hidden';
    await el.animate(
        [
            { height: el.offsetHeight + 'px', opacity: 1, filter: 'none' },
            { height: '0px', opacity: 0, filter: 'blur(4px)' },
        ],
        { duration: 320, easing: 'cubic-bezier(.22,.8,.2,1)', fill: 'forwards' },
    ).finished;
}

const first = (name: string) => name.split(' ')[0];

/**
 * One person's row, unfolded: the works they can see (ticking a private work opens it to reviewers), then their
 * comments and Remove, or, for an invite, Resend and Cancel.
 */
function Body({ p, works, onChange, onGone }: { p: Person; works: WorkChoice[]; onChange: (p: Person) => void; onGone: () => void }) {
    const [order] = useState(() => [...works].sort((a, b) => Number(p.works.includes(b.slug)) - Number(p.works.includes(a.slug))));
    const [find, setFind] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const base = p.kind === 'user' ? `/reviewers/${p.id}` : `/reviewers/invites/${p.id}`;
    const name = first(p.name);

    async function toggle(slug: string, on: boolean) {
        onChange({ ...p, works: on ? [...p.works, slug] : p.works.filter((s) => s !== slug) });
        const { status, data } = await json<{ person: Person }>('PATCH', `${base}/works`, { work: slug, on });
        if (status === 200 && data.person) {
            onChange(data.person);
        }
    }

    async function remove() {
        setBusy(true);
        const [{ status }] = await Promise.all([json('DELETE', base), wait(prefersReducedMotion() ? 0 : 500)]);
        setBusy(false);
        if (status === 200) {
            onGone();
        }
    }

    async function resend() {
        setBusy(true);
        const [{ status, data }] = await Promise.all([json<{ person: Person }>('POST', `${base}/resend`), wait(prefersReducedMotion() ? 0 : 500)]);
        setBusy(false);
        if (status === 200 && data.person) {
            onChange(data.person);
            setSent(true);
            window.setTimeout(() => setSent(false), 2400);
        }
    }

    const list = (
        <div className="k-f">
            <span className="k-fl" id={`wl-${p.key}`}>
                Works they can see
            </span>
            {works.length > 8 && (
                <label className="k-search pp-wq">
                    {PI.search}
                    <input type="search" placeholder="Find a work" autoComplete="off" aria-label="Find a work" value={find} onChange={(e) => setFind(e.target.value)} />
                </label>
            )}
            <WorkPicks works={order} picked={new Set(p.works)} onToggle={toggle} labelledBy={`wl-${p.key}`} className="two pp-scroll" filter={find} />
        </div>
    );

    if (p.kind === 'user') {
        return (
            <div className="b-pbody" ref={root}>
                <p className="k-meta">{p.email}</p>
                {list}
                <div className="acts">
                    <Link className="k-l" href={`/comments?tab=rev&who=${encodeURIComponent(p.name)}`}>
                        {PI.chat(17)}
                        <span>{p.comments} comments</span>
                    </Link>
                    <button
                        className="w-act cm-dng"
                        type="button"
                        data-a="rm"
                        onClick={() => {
                            setConfirm(true);
                            window.setTimeout(() => root.current?.querySelector<HTMLElement>('[data-a="rmno"]')?.focus(), prefersReducedMotion() ? 0 : 200);
                        }}
                    >
                        Remove {name}
                    </button>
                </div>
                <Unfold show={confirm}>
                    <div className="cm-conf">
                        <p>
                            {name} can’t open your works any more. {name}’s {p.comments} comments stay with you.
                        </p>
                        <div className="acts">
                            <button className={`k-btn sm danger${busy ? ' busy' : ''}`} type="button" onClick={remove}>
                                <span>Remove {name}</span>
                                <Ring />
                            </button>
                            <button
                                className="w-act"
                                type="button"
                                data-a="rmno"
                                onClick={() => {
                                    setConfirm(false);
                                    root.current?.querySelector<HTMLElement>('[data-a="rm"]')?.focus();
                                }}
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </Unfold>
            </div>
        );
    }

    return (
        <div className="b-pbody" ref={root}>
            <p className="k-meta">
                {p.email} · {p.state === 'expired' ? 'Invite expired' : `Expires in ${p.days} ${p.days === 1 ? 'day' : 'days'}`}
            </p>
            {p.note && <blockquote className="cm-note">{p.note}</blockquote>}
            {list}
            <div className="acts">
                <button className={`k-btn line sm${busy ? ' busy' : ''}`} type="button" onClick={resend}>
                    <span>Resend invite</span>
                    <Ring />
                </button>
                <span className={`k-saved pp-sent${sent ? ' on' : ''}`} aria-live="polite">
                    <span className="i">{PI.tick(15, 2.4)}</span>
                    <span className="t">Sent</span>
                </span>
                <button
                    className="w-act"
                    type="button"
                    onClick={async () => {
                        const { status } = await json('DELETE', base);
                        if (status === 200) {
                            onGone();
                        }
                    }}
                >
                    Cancel invite
                </button>
            </div>
        </div>
    );
}

/**
 * Reviewers: everyone who reads before readers do, and the invites still waiting. Rows unfold into the works they
 * can see; Invite a reviewer opens the dialog.
 */
export default function Reviewers({ people: initial, works, open: firstOpen }: { people: Person[]; works: WorkChoice[]; open: string | null }) {
    const [people, setPeople] = useState(initial);
    const [tab, setTab] = useState<Tab>('all');
    const [query, setQuery] = useState('');
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(firstOpen ?? '');
    const [last, setLast] = useState(firstOpen ?? '');
    const [out, setOut] = useState(false);
    const list = useRef<HTMLDivElement>(null);
    const timer = useRef(0);
    const titles = Object.fromEntries(works.map((w) => [w.slug, w.title]));

    /* someone invited from the dialog arrives at the end of the list */
    useEffect(() => {
        const added = (e: Event) => {
            const p = (e as CustomEvent<Person>).detail;
            setPeople((ps) => [...ps.filter((x) => x.key !== p.key), p]);
            window.setTimeout(() => {
                const row = list.current?.querySelector<HTMLElement>(`[data-p="${p.key}"]`);
                if (row) {
                    row.animate(prefersReducedMotion() ? [{ opacity: 0 }, { opacity: 1 }] : [{ opacity: 0, filter: 'blur(4px)', transform: 'translateY(4px)' }, { opacity: 1, filter: 'none', transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.22,.8,.2,1)' });
                }
            }, 0);
        };
        window.addEventListener(INVITED, added);

        return () => window.removeEventListener(INVITED, added);
    }, []);

    useEffect(() => {
        if (!firstOpen) {
            return;
        }
        window.setTimeout(() => {
            const r = list.current?.querySelector(`[data-p="${firstOpen}"]`)?.getBoundingClientRect();
            if (r && r.top > window.innerHeight * 0.6) {
                window.scrollTo({ top: window.scrollY + r.top - 120, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
            }
        }, 60);
    }, [firstOpen]);

    async function swap(apply: () => void) {
        setOut(true);
        await wait(prefersReducedMotion() ? 0 : 160);
        apply();
        setOut(false);
        const el = list.current;
        if (el) {
            el.classList.remove('settle');
            void el.offsetWidth;
            el.classList.add('settle');
        }
    }

    function toggle(key: string) {
        const next = open === key ? '' : key;
        setOpen(next);
        if (next) {
            setLast(next);
        }
        window.history.replaceState(window.history.state, '', '/reviewers' + (next ? '?p=' + next : ''));
    }

    const term = q.toLowerCase();
    const shown = people.filter((p) => (tab === 'all' || p.state !== 'on') && (!term || [p.name, p.email, ...p.works.map((s) => titles[s] ?? '')].join(' ').toLowerCase().includes(term)));
    const invited = people.filter((p) => p.state !== 'on').length;

    return (
        <AdminLayout section="reviewers">
            <div className="b-wh">
                <h1 className="b-h" id="sT">
                    Reviewers
                </h1>
                <button className="k-btn" type="button" aria-label="Invite a reviewer" onClick={() => openInvite()}>
                    {PI.plus(18, 2)}
                    <span>Invite a reviewer</span>
                </button>
            </div>
            {people.length > 0 && (
                <div className="b-tools cm-tools p">
                    <div className="k-tabs" role="tablist">
                        {(
                            [
                                ['all', 'All', people.length],
                                ['inv', 'Invited', invited],
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
                                    if (id !== tab) {
                                        swap(() => setTab(id));
                                    }
                                }}
                            >
                                {label}
                                <small>{n || ''}</small>
                            </a>
                        ))}
                    </div>
                    <label className="k-search cm-q" htmlFor="ppQ">
                        {PI.search}
                        <input
                            id="ppQ"
                            type="search"
                            placeholder="Search"
                            autoComplete="off"
                            aria-label="Search reviewers"
                            value={query}
                            onChange={(e) => {
                                const v = e.target.value;
                                setQuery(v);
                                window.clearTimeout(timer.current);
                                timer.current = window.setTimeout(() => swap(() => setQ(v.trim())), 180);
                            }}
                        />
                    </label>
                </div>
            )}
            <div className={`b-pl pp-list${out ? ' pp-out' : ''}`} id="ppL" ref={list}>
                {people.length === 0 ? (
                    <div className="cm-empty">
                        <h2>No reviewers yet.</h2>
                        <p>Invite someone to read a work before everyone else.</p>
                    </div>
                ) : shown.length === 0 ? (
                    <div className="cm-empty">
                        <h2>{q ? `Nobody matches “${q}”.` : 'No invites waiting.'}</h2>
                    </div>
                ) : (
                    shown.map((p) => {
                        const on = open === p.key;
                        const names = p.works.map((s) => titles[s]).filter(Boolean).join(', ') || 'No works';

                        return (
                            <div className={`b-pr2${on ? ' open' : ''}${p.state !== 'on' ? ' pend' : ''}`} data-p={p.key} key={p.key}>
                                <button className="b-prh" type="button" aria-expanded={on} aria-controls={`pb-${p.key}`} onClick={() => toggle(p.key)}>
                                    <Av seed={p.seed} size={40} />
                                    <span className="tx">
                                        <b>{p.name}</b>
                                        <span className="ws">{names}</span>
                                    </span>
                                    {p.state === 'on' ? (
                                        <>
                                            <span className="k-fact">
                                                {PI.chat()}
                                                {p.comments}
                                            </span>
                                            <span className="k-meta tm">{p.last}</span>
                                        </>
                                    ) : (
                                        <>
                                            <span className="cm-pend">{p.state === 'expired' ? 'Expired' : 'Invited'}</span>
                                            <span className="k-meta tm" />
                                        </>
                                    )}
                                    <span className="cv2">{PI.chev()}</span>
                                </button>
                                <Unfold show={on} id={`pb-${p.key}`}>
                                    {(on || last === p.key) && (
                                        <Body
                                            p={p}
                                            works={works}
                                            onChange={(n) => setPeople((ps) => ps.map((x) => (x.key === n.key ? n : x)))}
                                            onGone={async () => {
                                                setOpen('');
                                                await bye(list.current?.querySelector(`[data-p="${p.key}"]`) ?? null);
                                                setPeople((ps) => ps.filter((x) => x.key !== p.key));
                                            }}
                                        />
                                    )}
                                </Unfold>
                            </div>
                        );
                    })
                )}
            </div>
        </AdminLayout>
    );
}

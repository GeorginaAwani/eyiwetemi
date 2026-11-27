import { useEffect, useRef, useState } from 'react';
import { KSelect } from '../Controls';
import { bye, inA, turn } from '../notes/lines';
import { json } from '../../../lib/http';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { dragSort, Field, flipFrom, I, moved, SavedMark, SaveMark } from './kit';

export type ContactData = {
    email: string;
    line: string;
    links: { network: string; handle: string }[];
};

/* each site's mark (the public footer uses the same) */
const MARKS: Record<string, React.ReactNode> = {
    X: (
        <>
            <path d="M4.5 4h4.2L19.5 20h-4.2z" />
            <path d="M19 4l-5.9 6.8M5 20l5.9-6.8" />
        </>
    ),
    Instagram: (
        <>
            <rect x="3.8" y="3.8" width="16.4" height="16.4" rx="4.8" />
            <circle cx="12" cy="12" r="3.9" />
            <circle cx="17.1" cy="6.9" r=".6" fill="currentColor" />
        </>
    ),
    Goodreads: (
        <>
            <circle cx="11.5" cy="10" r="4.6" />
            <path d="M16.1 4.5v9.8a5 5 0 0 1-9.4 2.4" />
        </>
    ),
    TikTok: (
        <>
            <path d="M13.5 3.8v11a3.6 3.6 0 1 1-3.6-3.6" />
            <path d="M13.5 3.8c.5 2.8 2.4 4.4 5.3 4.6" />
        </>
    ),
    Threads: (
        <>
            <path d="M16.5 11.2c-.6-2.6-2.4-3.7-4.6-3.7-2.6 0-4.4 1.9-4.4 4.5s1.8 4.5 4.6 4.5c2.3 0 4-1.3 4-3.3 0-1.7-1.3-2.7-3.2-2.7-1.6 0-2.7.8-2.7 2 0 1 .8 1.7 2 1.7 1.8 0 2.8-1.6 2.7-4.3" />
            <path d="M19.5 8.5C18.4 5.3 15.6 3.5 12 3.5 6.9 3.5 3.8 7 3.8 12s3.1 8.5 8.2 8.5c3.3 0 5.9-1.6 7-4.2" />
        </>
    ),
    YouTube: (
        <>
            <rect x="2.8" y="5.5" width="18.4" height="13" rx="4" />
            <path d="m10.2 9.3 4.6 2.7-4.6 2.7z" />
        </>
    ),
    Facebook: <path d="M14.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2.3H9v3h2.5V21" />,
    Substack: <path d="M5.5 4.5h13M5.5 8h13M5.5 11.5h13v8.5l-6.5-3.6L5.5 20z" />,
    Website: (
        <>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17M12 3.5c2.4 2.5 3.4 5.3 3.4 8.5s-1 6-3.4 8.5c-2.4-2.5-3.4-5.3-3.4-8.5s1-6 3.4-8.5z" />
        </>
    ),
};
const mark = (n: string) => (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {MARKS[n]}
    </svg>
);
const isWeb = (n: string) => n === 'Website' || n === 'Substack';

/** Contact: her email and footer line, and her links elsewhere (drag to order), as the footer shows them. */
export default function ContactTab({ s, contact, networks, setContact }: { s: SiteCtx; contact: ContactData; networks: string[]; setContact: (c: ContactData) => void }) {
    const [email, setEmail] = useState(contact.email);
    const [bad, setBad] = useState(false);
    const [saved, setSaved] = useState<SaveMark>('idle');
    const [gen, setGen] = useState(0);
    const [foot, setFoot] = useState(contact);
    const t = useRef(0);
    const ft = useRef(0);
    const box = useRef<HTMLDivElement>(null);
    const latest = useRef(contact);
    latest.current = contact;

    function save(c: ContactData) {
        setContact(c);
        window.clearTimeout(t.current);
        t.current = window.setTimeout(async () => {
            const { status } = await json('PATCH', '/site/contact', latest.current as unknown as Record<string, unknown>);
            setSaved(status === 200 ? 'saved' : status === 0 ? 'device' : 'bad');
        }, 400);
        window.clearTimeout(ft.current);
        ft.current = window.setTimeout(() => setFoot(latest.current), 350);
    }
    useEffect(() => () => window.clearTimeout(t.current), []);
    const setLinks = (links: ContactData['links'], structural = false) => {
        save({ ...latest.current, links });
        if (structural) {
            setGen((g) => g + 1);
        }
    };
    function move(i: number, to: number, keys: boolean) {
        const L = contact.links;
        to = Math.max(0, Math.min(L.length - 1, to));
        if (to === i) {
            return;
        }
        const order = L.map((_, j) => j);
        const [m] = order.splice(i, 1);
        order.splice(to, 0, m);
        const play = keys ? flipFrom(box.current, 'i') : null;
        setLinks(moved(L, i, to), true);
        s.after(() => {
            play?.((nk) => String(order[Number(nk)]));
            if (keys) {
                box.current?.querySelector<HTMLElement>(`[data-i="${to}"] [data-grip]`)?.focus();
            }
        });
    }

    return (
        <>
            <section className="nw-m sp-m">
                <h2>Write to me</h2>
                <div className="sp-cg2">
                    <Field
                        id="spEm"
                        label="Email"
                        type="email"
                        value={email}
                        placeholder="hello@…"
                        bad={bad}
                        onChange={(v) => {
                            setEmail(v);
                            const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim());
                            setBad(!ok);
                            if (ok) {
                                save({ ...latest.current, email: v.trim() });
                            }
                        }}
                    />
                    <Field id="spLn" label="Line in the footer" value={contact.line} placeholder="One line" onChange={(v) => save({ ...latest.current, line: v })} />
                </div>
                <SavedMark state={saved} cls="sp-csv" />
                <p className="k-err sp-cerr" id="spEmE" hidden={!bad}>
                    That doesn’t look like an email address.
                </p>
            </section>
            <section className="nw-m sp-m">
                <h2>Elsewhere</h2>
                <div className="sp-lrs" id="spLrs" ref={box}>
                    {contact.links.map((l, i) => (
                        <div className="sp-rw sp-lr" data-i={i} data-drag="" key={`${gen}-${i}`}>
                            <button
                                className="sp-grip"
                                type="button"
                                aria-label={`Move ${l.network}. Use the up and down arrows`}
                                data-grip
                                onKeyDown={(e) => {
                                    if (/^Arrow(Up|Down)$/.test(e.key)) {
                                        e.preventDefault();
                                        move(i, i + (e.key === 'ArrowUp' ? -1 : 1), true);
                                    }
                                }}
                                onPointerDown={(e) => {
                                    if (e.button) {
                                        return;
                                    }
                                    e.preventDefault();
                                    const r = (e.target as Element).closest<HTMLElement>('.sp-lr')!;
                                    dragSort(
                                        e,
                                        r,
                                        () => [...box.current!.querySelectorAll<HTMLElement>(':scope>.sp-lr')],
                                        (from, to) => from !== to && move(from, to, false),
                                    );
                                }}
                            >
                                {I.grip15}
                            </button>
                            <span className="sp-li">{mark(l.network)}</span>
                            <KSelect
                                className="sp-ks sp-lsel"
                                buttonClassName="k-sbtn sp-sel sm"
                                label="Site"
                                value={l.network}
                                options={networks.map((n) => ({
                                    value: n,
                                    label: n,
                                }))}
                                onPick={(v) => {
                                    const li = box.current?.querySelector(`[data-i="${i}"] .sp-li`) ?? null;
                                    turn(li, () => setLinks(contact.links.map((x, j) => (j === i ? { ...x, network: v } : x))));
                                }}
                            />
                            <span className="k-in sp-in sm">
                                <input
                                    defaultValue={l.handle}
                                    placeholder={isWeb(l.network) ? 'yoursite.com' : '@handle'}
                                    aria-label={`${l.network} address`}
                                    onChange={(e) => {
                                        const v = e.target.value.trim().replace(/^@/, '');
                                        setLinks(latest.current.links.map((x, j) => (j === i ? { ...x, handle: v } : x)));
                                    }}
                                />
                            </span>
                            <button
                                className="ad-ic"
                                type="button"
                                aria-label={`Remove ${l.network}`}
                                onClick={(e) => {
                                    const r = e.currentTarget.closest<HTMLElement>('.sp-lr');
                                    const go = () =>
                                        setLinks(
                                            latest.current.links.filter((_, j) => j !== i),
                                            true,
                                        );
                                    if (r) {
                                        bye(r, go);
                                    } else {
                                        go();
                                    }
                                }}
                            >
                                {I.x15}
                            </button>
                        </div>
                    ))}
                </div>
                <div className="sp-addrow">
                    <button
                        className="w-act sp-add"
                        type="button"
                        onClick={() => {
                            const used = contact.links.map((l) => l.network);
                            const n = networks.find((x) => !used.includes(x)) ?? 'Website';
                            setLinks([...contact.links, { network: n, handle: '' }], true);
                            s.after(() => {
                                const r = box.current?.lastElementChild ?? null;
                                inA(r);
                                r?.querySelector<HTMLInputElement>('input')?.focus();
                            });
                        }}
                    >
                        {I.plus16}
                        <span>Add a link</span>
                    </button>
                </div>
                <div className="sp-fpv">
                    <span className="k-fl">In the footer</span>
                    <div id="spFpv">
                        <div className="sp-ft">
                            <div>
                                <span className="h">Write to me</span>
                                <span className="e">{foot.email}</span>
                                <span className="sp-fic">
                                    {foot.links
                                        .filter((l) => l.handle)
                                        .map((l, i) => (
                                            <span className="ic nw-tip" tabIndex={0} data-tip={l.network} key={i}>
                                                {mark(l.network)}
                                            </span>
                                        ))}
                                </span>
                            </div>
                            <div className="r">
                                <span className="wm">
                                    <span className="r">ey</span>
                                    <span className="r i first">ı</span>wetem
                                    <span className="i">ı</span>
                                </span>
                                <span className="m">
                                    {foot.line} © {new Date().getFullYear()}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </>
    );
}

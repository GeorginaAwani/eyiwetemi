import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { wait } from '../../Components/admin/Controls';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { FACT_ICONS, Plus, Search } from '../../Components/admin/Icons';
import { openNewWork } from '../../Components/admin/NewWork';
import AdminLayout from '../../Layouts/AdminLayout';
import { prefersReducedMotion } from '../../lib/motion';

type Fact = { icon: string; text: string; rose?: boolean };
type Row = { slug: string; href: string; title: string; tab: string; sub: string; cover: CoverData; status: Fact; visibility: Fact; length: string; edited: string };

const TABS: [string, string][] = [
    ['all', 'All'],
    ['novel', 'Novels'],
    ['story', 'Stories'],
    ['poem', 'Poetry'],
    ['essay', 'Essays'],
];
const PAGE = 12;

const fold = (t: string) =>
    t
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase();

function FactLine({ fact }: { fact: Fact }) {
    return (
        <span className={`k-fact${fact.rose ? ' k-rose' : ''}`}>
            {FACT_ICONS[fact.icon]}
            {fact.text}
        </span>
    );
}

/**
 * Works: every work, newest edit first, in Library-style tabs with a search; twelve at a time.
 */
export default function Works({ works }: { works: Row[] }) {
    const [tab, setTab] = useState(() => {
        const k = new URLSearchParams(window.location.search).get('k') ?? 'all';

        return TABS.some(([v]) => v === k) ? k : 'all';
    });
    const [query, setQuery] = useState('');
    const [shownQuery, setShownQuery] = useState('');
    const [shown, setShown] = useState(PAGE);
    const [animateFrom, setAnimateFrom] = useState<number | null>(null);
    const [out, setOut] = useState(false);
    const timer = useRef(0);

    const rows = useMemo(() => {
        const s = fold(shownQuery);

        return works.filter((w) => (tab === 'all' || w.tab === tab) && (!s || fold(w.title).includes(s)));
    }, [works, tab, shownQuery]);

    /* a new tab or search fades the list out and lets the new one settle in */
    async function swap(apply: () => void) {
        setOut(true);
        await wait(prefersReducedMotion() ? 0 : 160);
        apply();
        setShown(PAGE);
        setAnimateFrom(0);
        setOut(false);
    }

    useEffect(() => () => window.clearTimeout(timer.current), []);

    return (
        <AdminLayout section="works">
            <div className="b-wh">
                <h1 className="b-h">Works</h1>
                <button className="k-btn" type="button" aria-label="New work" onClick={openNewWork}>
                    <Plus />
                    <span>New work</span>
                </button>
            </div>
            <div className="b-tools">
                <div className="k-tabs" role="tablist" id="wTabs">
                    {TABS.map(([v, label]) => (
                        <a
                            key={v}
                            href="#"
                            role="tab"
                            aria-selected={v === tab}
                            className={v === tab ? 'on' : ''}
                            onClick={(e) => {
                                e.preventDefault();
                                if (v === tab) {
                                    return;
                                }
                                window.history.replaceState(window.history.state, '', v === 'all' ? '/works' : `/works?k=${v}`);
                                swap(() => setTab(v));
                            }}
                        >
                            {label}
                            <small>{v === 'all' ? works.length : works.filter((w) => w.tab === v).length}</small>
                        </a>
                    ))}
                </div>
                <label className="k-search" htmlFor="wq">
                    <Search />
                    <input
                        id="wq"
                        type="search"
                        placeholder="Search works"
                        autoComplete="off"
                        value={query}
                        onChange={(e) => {
                            const v = e.target.value;
                            setQuery(v);
                            window.clearTimeout(timer.current);
                            timer.current = window.setTimeout(() => swap(() => setShownQuery(v)), 180);
                        }}
                    />
                </label>
            </div>
            <div className={`wlist${out ? ' out' : ''}`} id="wList" aria-live="polite">
                {rows.length === 0 ? (
                    <p className="wempty">
                        {shownQuery.trim() ? (
                            <>
                                Nothing called <b>{shownQuery.trim()}</b>.
                            </>
                        ) : (
                            'Nothing here yet.'
                        )}
                    </p>
                ) : (
                    rows.slice(0, shown).map((w, i) => {
                        const fresh = animateFrom !== null && i >= animateFrom;

                        return (
                            <Link key={w.slug} className={`b-w${fresh ? ' in' : ''}`} href={w.href} style={fresh ? ({ '--k': i - animateFrom! } as React.CSSProperties) : undefined}>
                                <Cover cover={w.cover} title={w.title} />
                                <span className="t">{w.title}</span>
                                <span className="s">{w.sub}</span>
                                <span className="f">
                                    <FactLine fact={w.status} />
                                    <FactLine fact={w.visibility} />
                                    <span className="k-meta">{w.length}</span>
                                    <span className="k-meta">{w.edited}</span>
                                </span>
                            </Link>
                        );
                    })
                )}
            </div>
            <div className="b-more" id="wMore" hidden={rows.length <= shown}>
                <button
                    className="k-btn line sm"
                    type="button"
                    onClick={() => {
                        setAnimateFrom(shown);
                        setShown(shown + PAGE);
                    }}
                >
                    Show more
                </button>
                <span className="k-meta">
                    {Math.min(shown, rows.length)} of {rows.length}
                </span>
            </div>
        </AdminLayout>
    );
}

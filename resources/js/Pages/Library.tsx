import { Link } from '@inertiajs/react';
import { CSSProperties, KeyboardEvent, useEffect, useRef, useState } from 'react';
import BookPicture from '@/Components/site/BookPicture';
import SendButton from '@/Components/site/SendButton';
import StatusIcon from '@/Components/site/StatusIcon';
import SiteLayout from '@/Layouts/SiteLayout';
import { prefersReducedMotion } from '@/lib/motion';
import { ChapterRow, WorkCard } from '@/types/works';
import { askSignIn } from '@/lib/signin';

type TabKey = 'all' | 'novel' | 'story' | 'poem' | 'essay';
type Lead = WorkCard & {
    kindWord: string;
    statusLine: string;
    hasUnits: boolean;
    count: number;
    chapters: ChapterRow[];
    startHref: string;
    startLabel: string;
    allLabel: string;
    excerpt: string | null;
    stanza: { text: string; indent: number }[];
};
type Line = { type: 'excerpt' | 'chapter' | 'finished' | 'print' | 'soon'; text: string | null; isNew?: boolean };
type Row = WorkCard & { type: 'work'; sub: string; line: Line; printOnly: boolean; dt: string | null };
type Group = { type: 'series'; name: string; href: string; sub: string; rows: Row[] };
type Panel = { lead: Lead | null; rows: (Row | Group)[]; shown: number; total: number };

type Props = {
    tab: TabKey;
    tabs: { key: TabKey; label: string; count: number }[];
    panels: Record<TabKey, Panel>;
};

const v = (vars: Record<string, string | number>) => vars as CSSProperties;

/** The lead: the tab's newest work, as on Home. */
function LeadBlock({ lead }: { lead: Lead }) {
    const wide = lead.kind !== 'novel';

    return (
        <div className={`lead2${wide ? ' wide' : ''} work`}>
            {wide ? (
                <BookPicture work={lead} width={400} height={267} landscape type={{ k: 11, t: 38, pad: '30px 36px' }} />
            ) : (
                <BookPicture work={lead} width={220} height={330} type={{ k: 9, t: 29, w: 11, pad: '22px' }} />
            )}
            <div>
                <div className="label">Newest · {lead.date}</div>
                <h3>
                    <Link className="tl" href={lead.href}>
                        {lead.title}
                    </Link>
                </h3>
                {lead.hasUnits ? (
                    <>
                        <div className="meta">
                            <StatusIcon name={lead.status.icon === 'check' ? 'check' : lead.isCollection ? 'layers' : 'pen'} size={17} stroke="var(--accent)" />
                            {lead.statusLine}
                        </div>
                        {lead.series && (
                            <div className="meta" style={{ marginTop: 6 }}>
                                <StatusIcon name="book" size={17} stroke="var(--accent)" />
                                Book {lead.series.position} of{' '}
                                <Link className="link" href={lead.series.href} style={{ fontFamily: 'var(--NR)', fontWeight: 400, fontSize: 18 }}>
                                    {lead.series.name}
                                </Link>
                            </div>
                        )}
                        <div className="chaps">
                            {lead.chapters.map((c) => (
                                <Link key={c.number} className="chap" href={c.href}>
                                    <span>
                                        {c.label}
                                        {c.isNew && <span className="here">new</span>}
                                    </span>
                                    <small>{c.minutes} min</small>
                                </Link>
                            ))}
                        </div>
                        <div className="acts" style={{ display: 'flex', gap: 20, marginTop: 28, alignItems: 'center' }}>
                            <Link className="btn solid" href={lead.startHref}>
                                {lead.startLabel}
                            </Link>
                            <Link className="link" href={lead.href}>
                                {lead.allLabel}
                            </Link>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="meta">
                            {lead.kindWord} · {lead.minutes} min
                        </div>
                        {lead.stanza.length > 0 ? (
                            <div className="stanza in" style={{ marginTop: 24 }}>
                                {lead.stanza.map((line, i) => (
                                    <p key={i} style={{ ...v({ '--l': i }), paddingLeft: line.indent ? `${line.indent * 1.5}em` : undefined }}>
                                        {line.text}
                                    </p>
                                ))}
                            </div>
                        ) : (
                            lead.excerpt && <p className="excerpt">{lead.excerpt}</p>
                        )}
                        <div style={{ display: 'flex', gap: 20, marginTop: 28 }}>
                            <Link className="btn solid" href={lead.startHref}>
                                {lead.startLabel}
                            </Link>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

/** One row: the whole row is a link to the work. */
function WorkRow({ row, i, arrive }: { row: Row; i: number; arrive: boolean }) {
    const portrait = row.kind === 'novel';
    const soon = row.line.type === 'soon';

    return (
        <Link className={`wr${arrive ? ' arrive' : ''}`} href={row.href} style={arrive ? v({ '--i': i }) : undefined}>
            <span className="pic">
                {soon ? (
                    <div className="book turn soonf" style={{ width: 80, height: 120, padding: 9, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }} aria-hidden="true">
                        <span className="caps" style={{ fontSize: 6, color: 'var(--accent)' }}>
                            Coming soon
                        </span>
                        <span className="ft" style={{ fontSize: 11, color: 'var(--accent)' }}>
                            {row.title}
                        </span>
                        <span />
                    </div>
                ) : portrait ? (
                    <BookPicture work={row} width={80} height={120} type={{ k: 5, t: 11, w: 5, pad: '8px' }} linked />
                ) : (
                    <BookPicture work={row} width={150} height={100} landscape type={{ k: 6, t: 15, pad: '11px 13px' }} linked collClass="collt" />
                )}
            </span>
            <span className="tc">
                <span className="tt">
                    <span className="tl">{row.title}</span>
                </span>
                <span className="sub">{row.sub}</span>
                {row.line.type === 'excerpt' ? (
                    row.line.text && <span className="l3 ex">{row.line.text}</span>
                ) : row.line.type === 'soon' ? (
                    <span className="l3">
                        <span
                            className="link"
                            style={{ fontSize: 14 }}
                            role="button"
                            tabIndex={0}
                            onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                askSignIn({ follow: row.id, why: `Sign in to hear when ${row.title} is out` });
                            }}
                            onKeyDown={(e: KeyboardEvent) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    askSignIn({ follow: row.id, why: `Sign in to hear when ${row.title} is out` });
                                }
                            }}
                        >
                            {row.line.text}
                        </span>
                    </span>
                ) : (
                    <span className="l3 ch">
                        {row.line.text}
                        {row.line.isNew && <span className="here">new</span>}
                        {row.printOnly ? (
                            <span className="pill" style={{ margin: '0 0 0 12px' }}>
                                Where to buy ↗
                            </span>
                        ) : (
                            row.inPrint && (
                                <span className="pill" style={{ margin: '0 0 0 12px' }}>
                                    In print · from {row.inPrint} ↗
                                </span>
                            )
                        )}
                    </span>
                )}
            </span>
            <span className="fc">
                <div className="st">
                    <StatusIcon name={row.status.icon} />
                    <span>{row.status.icon === 'layers' ? row.status.text.replace('Collection · ', '') : row.status.text}</span>
                </div>
            </span>
            <span className="dt">{row.dt}</span>
        </Link>
    );
}

function Rows({ rows, arriveFrom }: { rows: (Row | Group)[]; arriveFrom: number }) {
    return (
        <>
            {rows.map((r, i) =>
                r.type === 'series' ? (
                    <div className={`grp${i >= arriveFrom ? ' arrive' : ''}`} key={`s-${r.name}`}>
                        <div className="gh">
                            <b>
                                <Link className="tl" href={r.href}>
                                    {r.name}
                                </Link>
                            </b>
                            <small>{r.sub}</small>
                        </div>
                        {r.rows.map((m, j) => (
                            <WorkRow key={m.id} row={m} i={j} arrive={i >= arriveFrom} />
                        ))}
                    </div>
                ) : (
                    <WorkRow key={r.id} row={r} i={i - arriveFrom} arrive={i >= arriveFrom} />
                ),
            )}
        </>
    );
}

/** A tab's panel: lead, "Earlier", the rows, and Show more while there are more. */
function PanelView({ tab, panel, on }: { tab: TabKey; panel: Panel; on: boolean }) {
    const [rows, setRows] = useState(panel.rows);
    const [shown, setShown] = useState(panel.shown);
    const [arriveFrom, setArriveFrom] = useState(Number.MAX_SAFE_INTEGER);
    const [state, setState] = useState<'idle' | 'sending' | 'failed'>('idle');

    const more = async () => {
        if (state === 'sending') {
            return;
        }
        setState('sending');
        try {
            const res = await fetch(`/library-rows?tab=${tab}&shown=${shown}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                throw new Error(String(res.status));
            }
            const next: { rows: (Row | Group)[]; shown: number } = await res.json();
            setArriveFrom(rows.length);
            setRows((r) => [...r, ...next.rows]);
            setShown(next.shown);
            setState('idle');
        } catch {
            setState('failed');
        }
    };

    if (!panel.lead && rows.length === 0) {
        return (
            <div className={`panel${on ? ' on' : ''}`} data-p={tab} role="tabpanel" aria-hidden={!on}>
                <p className="label" style={{ padding: '56px 0' }}>
                    Nothing here yet.
                </p>
            </div>
        );
    }

    return (
        <div className={`panel${on ? ' on' : ''}`} data-p={tab} role="tabpanel" aria-hidden={!on} {...(on ? {} : { inert: '' })}>
            {panel.lead && <LeadBlock lead={panel.lead} />}
            {rows.length > 0 && (
                <>
                    <div className="earlier">Earlier</div>
                    <div className="wl">
                        <Rows rows={rows} arriveFrom={arriveFrom} />
                    </div>
                </>
            )}
            {shown < panel.total && (
                <div className="more">
                    <SendButton className="btn line" type="button" state={state} retryLabel="Try again" onClick={more}>
                        Show more
                    </SendButton>
                    <span className="label" style={{ fontSize: 14 }}>
                        {shown} of {panel.total}
                    </span>
                </div>
            )}
        </div>
    );
}

/** Library: the title and tabs share one line (T1); each tab leads with its newest work (A2). */
export default function Library({ tab, tabs, panels }: Props) {
    const [current, setCurrent] = useState<TabKey>(tab);
    const bar = useRef<HTMLDivElement>(null);
    const [atEnd, setAtEnd] = useState(false);

    /* the header's Library menu can change the tab while you're here */
    useEffect(() => setCurrent(tab), [tab]);

    /* the picked tab scrolls into view on phones; the edge fade flips at the end */
    useEffect(() => {
        const el = bar.current;
        const t = el?.querySelector<HTMLElement>('.tab.on');
        if (!el || !t || el.scrollWidth <= el.clientWidth) {
            return;
        }
        const last = t === el.lastElementChild;
        el.scrollTo({
            left: last ? el.scrollWidth : Math.max(0, t.offsetLeft - el.offsetLeft - (el.clientWidth - t.offsetWidth) / 2),
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        });
    }, [current]);

    useEffect(() => {
        const el = bar.current;
        if (!el) {
            return;
        }
        const edge = () => setAtEnd(el.scrollWidth > el.clientWidth && el.scrollLeft + el.clientWidth >= el.scrollWidth - 2);
        edge();
        el.addEventListener('scroll', edge, { passive: true });

        return () => el.removeEventListener('scroll', edge);
    }, []);

    const pick = (key: TabKey) => {
        if (key === current) {
            return;
        }
        setCurrent(key);
        const url = new URL(window.location.href);
        if (key === 'all') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', key);
        }
        window.history.replaceState(window.history.state, '', url);
    };

    const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
        const i = tabs.findIndex((t) => t.key === current);
        const next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
        if (next === null) {
            return;
        }
        e.preventDefault();
        const key = tabs[(next + tabs.length) % tabs.length].key;
        pick(key);
        bar.current?.querySelector<HTMLElement>(`[data-k="${key}"]`)?.focus();
    };

    return (
        <SiteLayout title="Library" section="library">
            <div className="wrap wtop t1">
                <div className="whead">
                    <h1>Library</h1>
                    <div className={`tabs${atEnd ? ' end' : ''}`} role="tablist" aria-label="Kinds" ref={bar} onKeyDown={onKey}>
                        {tabs.map((t) => (
                            <a
                                key={t.key}
                                className={`tab${t.key === current ? ' on' : ''}`}
                                href={t.key === 'all' ? '/library' : `/library?tab=${t.key}`}
                                role="tab"
                                data-k={t.key}
                                aria-selected={t.key === current}
                                tabIndex={t.key === current ? 0 : -1}
                                onClick={(e) => {
                                    e.preventDefault();
                                    pick(t.key);
                                }}
                            >
                                {t.label}
                                <small>{t.count}</small>
                            </a>
                        ))}
                    </div>
                </div>
                <div className="panels">
                    {tabs.map((t) => (
                        <PanelView key={t.key} tab={t.key} panel={panels[t.key]} on={t.key === current} />
                    ))}
                </div>
            </div>
        </SiteLayout>
    );
}

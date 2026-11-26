import { Link } from '@inertiajs/react';
import Cover, { CoverData } from '../../Components/admin/Cover';
import { Broken, Dot, Heart, Pen, Pin } from '../../Components/admin/Icons';
import AdminLayout from '../../Layouts/AdminLayout';

type Row = { href: string };
type Props = {
    lead: (Row & { cover: CoverData; kick: string; title: string; meta: string }) | null;
    comments: { count: number; rows: (Row & { who: string; on: string; ago: string; text: string })[] };
    reviewers: { count: number; rows: (Row & { who: string; ago: string; text: string })[] };
    week: {
        reads: number;
        before: number;
        spark: number[];
        top: (Row & { title: string; cover: CoverData; reads: number }) | null;
        words: number;
        subscribers: number;
        newSubscribers: number;
    };
    scheduled: { count: number; rows: (Row & { label: string; at: string })[] };
    notes: { rows: (Row & { text: string; meta: string; scheduled: boolean })[]; hearts: number };
    site: (Row & { kind: 'broken' | 'notice' | 'pin'; before: string; bold: string | null; after: string; action: string })[];
};

const n = (v: number) => Math.round(v).toLocaleString('en-GB');

/** Big numbers shorten from 100,000 (123K, 1.2M); the exact figure shows as a tooltip. */
function compact(v: number): string {
    v = Math.round(v);
    if (v < 1e5) {
        return n(v);
    }
    if (v < 999500) {
        return `${Math.round(v / 1e3)}K`;
    }
    const m = v / 1e6;

    return `${m < 100 ? String(+m.toFixed(1)) : String(Math.round(m))}M`;
}

function Figure({ value }: { value: number }) {
    return <b title={value >= 1e5 ? n(value) : undefined}>{compact(value)}</b>;
}

/** The change against the week before: +12% in rose, a drop or no change in grey. */
function Delta({ now, before }: { now: number; before: number }) {
    if (!before) {
        return null;
    }
    const p = Math.round(((now - before) / before) * 100);
    if (!p) {
        return <em className="eq">±0%</em>;
    }

    return p > 0 ? <em>+{p}%</em> : <em className="dn">−{-p}%</em>;
}

/** Reads for the last seven days, today's point marked. */
function Spark({ values }: { values: number[] }) {
    const W = 200;
    const H = 52;
    const max = Math.max(...values);
    const min = Math.min(...values) * 0.8;
    const pts = values.map((v, i) => [(i * W) / (values.length - 1), H - 4 - ((v - min) / (max - min || 1)) * (H - 10)]);
    const last = pts[pts.length - 1];

    return (
        <svg className="k-spark" width={W} height={H} viewBox={`0 0 ${W} ${H}`} aria-hidden="true">
            <polyline points={pts.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ')} fill="none" stroke="var(--accent)" strokeWidth="1.6" strokeLinejoin="round" strokeLinecap="round" />
            <circle cx={last[0].toFixed(1)} cy={last[1].toFixed(1)} r="3.2" fill="var(--accent)" />
        </svg>
    );
}

function Head({ title, count, more }: { title: string; count?: number; more?: [string, string] }) {
    return (
        <div className="k-sh">
            <h2>
                {title}
                {count ? (
                    <>
                        {' '}
                        <small>{count}</small>
                    </>
                ) : null}
            </h2>
            {more && (
                <Link className="k-l" href={more[1]}>
                    <span>{more[0]}</span>
                </Link>
            )}
        </div>
    );
}

const SITE_ICONS = { broken: <Broken />, notice: <Dot />, pin: <Pin /> };

/**
 * The desk (B · Margins): back to writing first, then new comments and reviewers, this week's figures,
 * what's scheduled, notes, and anything on the site that needs her.
 */
export default function Desk({ lead, comments, reviewers, week, scheduled, notes, site }: Props) {
    return (
        <AdminLayout section="desk">
            {lead && (
                <section className="b-hero" id="lead">
                    <Link className="lc" href={lead.href} tabIndex={-1} aria-hidden="true">
                        <Cover cover={lead.cover} title={lead.title} className="k-cov hero" />
                    </Link>
                    <div className="tx">
                        <span className="k-kick">{lead.kick}</span>
                        <h1 className="lt">{lead.title}</h1>
                        <span className="k-meta">{lead.meta}</span>
                        <div className="a-acts">
                            <Link className="k-btn" href={lead.href}>
                                <Pen />
                                <span>Continue writing</span>
                            </Link>
                        </div>
                    </div>
                </section>
            )}

            <div className="b-cols">
                <div className="b-col">
                    <div>
                        <Head title="New comments" count={comments.count} more={['All comments', '/comments']} />
                        {comments.rows.map((c, i) => (
                            <Link key={i} className="b-c pp-dc" href={c.href}>
                                <div className="w">
                                    <span>
                                        <b>{c.who}</b> on {c.on}
                                    </span>
                                    <span>{c.ago}</span>
                                </div>
                                <p>{c.text}</p>
                            </Link>
                        ))}
                    </div>
                    <div>
                        <Head title="Reviewers" count={reviewers.count} more={['All reviewers', '/reviewers']} />
                        {reviewers.rows.map((r, i) => (
                            <Link key={i} className="b-c pp-dc" href={r.href}>
                                <div className="w">
                                    <span>
                                        <b>{r.who}</b>
                                    </span>
                                    <span>{r.ago}</span>
                                </div>
                                <p>{r.text}</p>
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="b-col fig">
                    <div>
                        <Head title="This week" more={['Stats', '/stats']} />
                        <div className="b-fig">
                            <div className="wide">
                                <div>
                                    <Figure value={week.reads} />
                                    <span>
                                        Reads this week <Delta now={week.reads} before={week.before} />
                                    </span>
                                </div>
                                <Spark values={week.spark} />
                            </div>
                            {week.top && (
                                <Link className="st-top" href={week.top.href}>
                                    <Cover cover={week.top.cover} title="" className="k-cov st-cv" style={{ width: 22, height: 33 }} />
                                    <span className="l">Most read</span>
                                    <b>{week.top.title}</b>
                                    <span className="v">{compact(week.top.reads)}</span>
                                </Link>
                            )}
                            <div>
                                <Figure value={week.words} />
                                <span>Words written</span>
                            </div>
                            <Link href="/newsletter?tab=subs" className="nw-dk">
                                <div>
                                    <Figure value={week.subscribers} />
                                    <span>
                                        Subscribers {week.newSubscribers > 0 && <em>+{n(week.newSubscribers)}</em>}
                                    </span>
                                </div>
                            </Link>
                        </div>
                    </div>

                    {scheduled.count > 0 && (
                        <div>
                            <Head title="Scheduled" count={scheduled.count} />
                            {scheduled.rows.map((s, i) => (
                                <Link key={i} className="b-s" href={s.href}>
                                    <span>{s.label}</span>
                                    <span className="k-meta">{s.at}</span>
                                </Link>
                            ))}
                        </div>
                    )}

                    {(notes.rows.length > 0 || notes.hearts > 0) && (
                        <div id="ntDesk">
                            <Head title="Notes" more={['All notes', '/notes']} />
                            {notes.rows.map((note, i) => (
                                <Link key={i} className="b-s" href={note.href}>
                                    <span>{note.text}</span>
                                    <span className={`k-meta${note.scheduled ? ' nt-dsch' : ''}`}>{note.meta}</span>
                                </Link>
                            ))}
                            {notes.hearts > 0 && (
                                <Link className="b-s" href="/notes">
                                    <span>New hearts on notes</span>
                                    <span className="k-meta nt-dh">
                                        <Heart />
                                        {n(notes.hearts)}
                                    </span>
                                </Link>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {site.length > 0 && (
                <section className="sp-dk">
                    <h2>Site</h2>
                    {site.map((s, i) => (
                        <div key={i} className="sp-dkr">
                            {SITE_ICONS[s.kind]}
                            <span>
                                {s.before}
                                {s.bold && <b>{s.bold}</b>}
                                {s.after}
                            </span>
                            <Link className="k-l" href={s.href}>
                                <span>{s.action}</span>
                            </Link>
                        </div>
                    ))}
                </section>
            )}
        </AdminLayout>
    );
}

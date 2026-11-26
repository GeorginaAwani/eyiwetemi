import { Link } from '@inertiajs/react';
import { CSSProperties, Fragment, useState } from 'react';
import BookPicture from '@/Components/site/BookPicture';
import FollowButton from '@/Components/site/FollowButton';
import StatusIcon from '@/Components/site/StatusIcon';
import SiteLayout from '@/Layouts/SiteLayout';
import { Action, WorkCard } from '@/types/works';

type Row = { number: number; title: string | null; label: string; minutes: number; href: string | null; isNew: boolean; unavailable: boolean };
type Print = { format: string; stores: { store: string; price: string; url: string }[]; from: string | null };
type Props = {
    work: WorkCard & {
        statusLine: string;
        genres: string[];
        blurb: string | null;
        likes: string;
        comments: string;
        note: { label: string; text: string; href: string } | null;
        printOnly: boolean;
    };
    facts: { label: string; value: string }[];
    prints: Print[];
    actions: Action[];
    list: { title: string; count: number; untitled: boolean; rows: Row[]; foldFrom: number | null };
};

const v = (vars: Record<string, string | number>) => vars as CSSProperties;

const Heart = () => (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7z" />
    </svg>
);
const Bubble = () => (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z" />
    </svg>
);
const Tag = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z" />
        <circle cx="7.5" cy="7.5" r="1" fill="currentColor" />
    </svg>
);
const Chevron = () => (
    <svg className="pch" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="m6 9 6 6 6-6" />
    </svg>
);

/** In print: one row per format. One store links straight there; several unfold under the row. */
function Prints({ prints }: { prints: Print[] }) {
    const [open, setOpen] = useState<string | null>(null);

    return (
        <div className="prints" id="in-print">
            <div className="caps">In print</div>
            <div className="plist">
                {prints.map((p) =>
                    p.stores.length === 1 ? (
                        <a key={p.format} className="prow" href={p.stores[0].url} target="_blank" rel="noopener">
                            <span>{p.format}</span>
                            <small>
                                {p.stores[0].store} · {p.stores[0].price} ↗
                            </small>
                        </a>
                    ) : (
                        <Fragment key={p.format}>
                            <button
                                type="button"
                                className={`prow pfmt${open === p.format ? ' open' : ''}`}
                                aria-expanded={open === p.format}
                                onClick={() => setOpen((o) => (o === p.format ? null : p.format))}
                            >
                                <span>{p.format}</span>
                                <small>
                                    from {p.from}
                                    <Chevron />
                                </small>
                            </button>
                            <div className={`pst${open === p.format ? ' open' : ''}`}>
                                <div {...(open === p.format ? {} : { inert: '' })}>
                                    {p.stores.map((s) => (
                                        <a key={s.store + s.price} className="srow" href={s.url} target="_blank" rel="noopener">
                                            <span>{s.store}</span>
                                            <small>{s.price} ↗</small>
                                        </a>
                                    ))}
                                </div>
                            </div>
                        </Fragment>
                    ),
                )}
            </div>
        </div>
    );
}

function ChapterRowView({ row, untitled }: { row: Row; untitled: boolean }) {
    const inner = (
        <>
            {!untitled && <span className="n">{row.number}</span>}
            <span className="ct">
                <span className="cx">{untitled ? `${row.label}` : row.label}</span>
                {row.isNew && <span className="here">new</span>}
            </span>
            <small>{row.unavailable ? 'Not available right now' : `${row.minutes} min`}</small>
        </>
    );

    return row.href ? (
        <Link className="crow" href={row.href}>
            {inner}
        </Link>
    ) : (
        <div className="crow off">{inner}</div>
    );
}

/** The contents. A long list keeps about ten rows open and folds the rest behind "Show N more". */
function Contents({ list, workKind }: { list: Props['list']; workKind: string }) {
    const [open, setOpen] = useState(false);
    const shown = list.foldFrom !== null ? list.rows.slice(0, list.foldFrom) : list.rows;
    const tail = list.foldFrom !== null ? list.rows.slice(list.foldFrom) : [];
    const word = workKind === 'novel' ? 'Chapters' : list.title;

    return (
        <>
            <div className="lh">
                <h2>{list.title}</h2>
                <small>{list.count}</small>
            </div>
            <div className={`clist${list.untitled ? ' untitled' : ''}`}>
                {shown.map((r) => (
                    <ChapterRowView key={r.number} row={r} untitled={list.untitled} />
                ))}
                {tail.length > 0 && (
                    <>
                        <button type="button" className={`fold${open ? ' gone' : ''}`} aria-expanded={open} onClick={() => setOpen(true)}>
                            {!list.untitled && <span className="n" />}
                            <span className="ct">
                                <span className="cx">
                                    {word} {tail[0].number}–{tail[tail.length - 1].number}
                                </span>
                            </span>
                            <small>Show {tail.length} more</small>
                        </button>
                        <div className={`hid${open ? ' open' : ''}`}>
                            <div {...(open ? {} : { inert: '' })}>
                                {tail.map((r) => (
                                    <ChapterRowView key={r.number} row={r} untitled={list.untitled} />
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

/** A novel's or collection's page (W2 Margins). */
export default function Work({ work, facts, prints, actions, list }: Props) {
    const words = work.title.split(' ');

    return (
        <SiteLayout title={work.title} section="library">
            <div className="wrap wp w2">
                <aside className="w2s">
                    <BookPicture work={work} width={240} height={360} type={{ k: 10, t: 32, w: 12, pad: '24px 24px' }} linked />
                    <div className="mfacts">
                        {facts.map((f) => (
                            <div className="mf" key={f.label}>
                                <span className="caps">{f.label}</span>
                                <b>{f.value}</b>
                            </div>
                        ))}
                    </div>
                    {prints.length > 0 && <Prints prints={prints} />}
                </aside>
                <div className="w2c">
                    <div className="label">{work.kindLabel}</div>
                    <h1>
                        {words.map((w, i) => (
                            <Fragment key={i}>
                                <span className="word" style={v({ '--w': i })}>
                                    {w}
                                </span>
                                {i < words.length - 1 ? ' ' : null}
                            </Fragment>
                        ))}
                    </h1>
                    <div className="meta">
                        <StatusIcon name={work.isCollection ? 'layers' : work.status.icon === 'check' ? 'check' : 'pen'} size={17} />
                        {work.statusLine}
                    </div>
                    {work.genres.length > 0 && (
                        <div className="meta gmeta" aria-label="Genre">
                            <Tag />
                            {work.genres.join(' · ')}
                        </div>
                    )}
                    {work.series && (
                        <div className="meta">
                            <StatusIcon name="book" size={17} />
                            Book {work.series.position} of{' '}
                            <Link className="link sl" href={work.series.href}>
                                {work.series.name}
                            </Link>
                        </div>
                    )}
                    {work.blurb && <p className="blurb">{work.blurb}</p>}
                    {work.note && (
                        <div className="wnote">
                            <span className="k">{work.note.label}</span>
                            <Link href={work.note.href}>{work.note.text}</Link>
                        </div>
                    )}
                    {actions.length > 0 && (
                        <div className="acts wacts" style={{ alignItems: 'center', columnGap: actions.some((a) => a.style === 'link') ? 24 : 16 }}>
                            {actions.map((a) => (
                                <Link key={a.label} className={a.style === 'link' ? 'link' : `btn ${a.style}`} href={a.href}>
                                    {a.label}
                                </Link>
                            ))}
                        </div>
                    )}
                    <div className="stats">
                        <span aria-label={`${work.likes} likes`}>
                            <Heart />
                            {work.likes}
                        </span>
                        <span aria-label={`${work.comments} comments`}>
                            <Bubble />
                            {work.comments}
                        </span>
                        <FollowButton following={false} workId={work.id} title={work.title} />
                    </div>
                    <Contents list={list} workKind={work.kind} />
                </div>
            </div>
        </SiteLayout>
    );
}

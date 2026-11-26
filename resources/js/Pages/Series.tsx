import { Link } from '@inertiajs/react';
import { CSSProperties, Fragment } from 'react';
import BookPicture from '@/Components/site/BookPicture';
import FollowButton from '@/Components/site/FollowButton';
import StatusIcon from '@/Components/site/StatusIcon';
import SiteLayout from '@/Layouts/SiteLayout';
import { Action, WorkCard } from '@/types/works';

type Book = WorkCard & { position: number; soon: boolean; statusText: string; href: string };
type Props = {
    series: { id: number; name: string; blurb: string | null; statusLine: string; facts: { label: string; value: string }[] };
    actions: Action[];
    books: Book[];
};

const v = (vars: Record<string, string | number>) => vars as CSSProperties;

/** A coming-soon book: a dashed blush front with its title. */
function SoonFront({ book, width, height, big }: { book: Book; width: number; height: number; big?: boolean }) {
    return (
        <div className="book soonf" style={{ width, height, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', padding: big ? 10 : 7 }} aria-hidden="true">
            {big && (
                <>
                    <span className="caps" style={{ fontSize: 8, color: 'var(--accent)' }}>
                        Coming soon
                    </span>
                    <span style={{ fontFamily: 'var(--CD)', fontSize: 18, color: 'var(--accent)' }}>{book.title}</span>
                    <span />
                </>
            )}
        </div>
    );
}

/** The series' picture: its books overlapped, the first in front. */
function Stack({ books }: { books: Book[] }) {
    const shown = books.slice(0, 3);
    const n = shown.length;

    return (
        <div className="sercov" style={{ position: 'relative', width: 240, height: 360 }} aria-hidden="true">
            {[...shown].reverse().map((b, i) => {
                const k = n - 1 - i;
                return (
                    <div key={b.id} style={{ position: 'absolute', left: k * 45.5, top: (n - 1 - k) * 68 }}>
                        {b.soon ? (
                            <SoonFront book={b} width={148} height={223} big />
                        ) : (
                            <BookPicture work={b} width={148} height={223} type={{ k: 7, t: 19, w: 7, pad: '14px 14px' }} linked />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** A series' page (W2 Margins): the books in order. */
export default function Series({ series, actions, books }: Props) {
    const words = series.name.split(' ');

    return (
        <SiteLayout title={series.name} section="library">
            <div className="wrap wp w2">
                <aside className="w2s">
                    <Stack books={books} />
                    <div className="mfacts">
                        {series.facts.map((f) => (
                            <div className="mf" key={f.label}>
                                <span className="caps">{f.label}</span>
                                <b>{f.value}</b>
                            </div>
                        ))}
                    </div>
                </aside>
                <div className="w2c">
                    <div className="label">A series</div>
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
                        <StatusIcon name="book" size={17} />
                        {series.statusLine}
                    </div>
                    {series.blurb && <p className="blurb">{series.blurb}</p>}
                    {actions.length > 0 && (
                        <div className="acts wacts">
                            {actions.map((a) => (
                                <Link key={a.label} className={`btn ${a.style}`} href={a.href}>
                                    {a.label}
                                </Link>
                            ))}
                        </div>
                    )}
                    <div className="stats">
                        <FollowButton following={false} workId={series.id} title={series.name} />
                    </div>
                    <div className="lh">
                        <h2>Books</h2>
                        <small>{books.length}</small>
                    </div>
                    <div className="blist">
                        {books.map((b) => {
                            const body = (
                                <>
                                    {b.soon ? (
                                        <SoonFront book={b} width={72} height={108} />
                                    ) : (
                                        <BookPicture work={b} width={72} height={108} type={{ k: 7, t: 9, w: 7, pad: '7px 7px' }} linked />
                                    )}
                                    <div className="bt">
                                        <span className="caps" style={{ fontSize: 10 }}>
                                            Book {b.position}
                                        </span>
                                        <div className="tt">
                                            <span className="tl">{b.title}</span>
                                        </div>
                                        <div className="st">
                                            <StatusIcon name={b.soon ? 'clock' : b.status.icon === 'check' ? 'check' : 'pen'} />
                                            <span>{b.statusText}</span>
                                        </div>
                                        {b.inPrint && (
                                            <span className="pill" style={{ marginTop: 10 }}>
                                                In print · from {b.inPrint} ↗
                                            </span>
                                        )}
                                        {b.soon && (
                                            <div style={{ marginTop: 10 }}>
                                                <button
                                                    type="button"
                                                    className="link"
                                                    style={{ fontSize: 14 }}
                                                    onClick={() => window.dispatchEvent(new CustomEvent('eyi:signin', { detail: { follow: b.id, why: `Sign in to hear when ${b.title} is out` } }))}
                                                >
                                                    Tell me when it’s out
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </>
                            );

                            return b.href ? (
                                <Link key={b.id} className="brow" href={b.href}>
                                    {body}
                                </Link>
                            ) : (
                                <div key={b.id} className="brow">
                                    {body}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </SiteLayout>
    );
}

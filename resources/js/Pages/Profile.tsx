import { Link } from '@inertiajs/react';
import Bloom from '@/Components/site/Bloom';
import BookPicture from '@/Components/site/BookPicture';
import { GearIcon } from '@/Components/site/Icons';
import { CommentRow, CommentRowT, FollowRow, FollowRowT, LikeGroup, LikeGroups, Picture, Th } from '@/Components/site/ProfileRows';
import SiteLayout from '@/Layouts/SiteLayout';
import { WorkCard } from '@/types/works';
import { paths } from '@/lib/paths';

type Props = {
    me: { name: string; username: string; seed: string; joined: string };
    shared: { id: number; title: string; href: string; picture: Picture; private: boolean; shared: string; open: number }[];
    place: {
        work: { title: string; href: string; cover: { src: string | null; color: string; dark: boolean }; kindLabel: string; kind: WorkCard['kind']; isCollection: boolean };
        label: string;
        title: string | null;
        href: string;
        percent: number;
        left: string;
        also: { title: string; href: string; picture: Picture; label: string }[];
    } | null;
    following: { count: number; rows: FollowRowT[] };
    likes: { count: number; groups: LikeGroup[] };
    comments: { count: number; rows: CommentRowT[] };
};

const LOCK = (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
        <rect x="4" y="11" width="16" height="10" rx="2" />
        <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
);

/** The heading above a section, with "All N" when there's more. */
function Head({ title, count, href }: { title: string; count: number; href: string }) {
    return (
        <div className="pf3-sh">
            <h2 className="pf-h2b">
                {title}
                <small>{count}</small>
            </h2>
            <Link className="link" href={href}>
                All {count}
            </Link>
        </div>
    );
}

function Empty({ title, text, link }: { title: string; text: string; link: string }) {
    return (
        <section className="pf3-sec">
            <h2 className="pf-h2b">{title}</h2>
            <div className="pf-empty">
                <p>{text}</p>
                <Link className="link" href={paths.library()}>
                    {link}
                </Link>
            </div>
        </section>
    );
}

/** P3 · Newest leads: your place first, then what you follow, then your likes and comments side by side. */
export default function Profile({ me, shared, place, following, likes, comments }: Props) {
    return (
        <SiteLayout title="Profile">
            <div className="wrap pf3">
                <div className="pf3-top">
                    <div className="pf-me-h">
                        <Bloom seed={me.seed} size={64} />
                        <div className="pf-id">
                            <h1>{me.name}</h1>
                            <small>
                                @{me.username} · Joined {me.joined}
                            </small>
                        </div>
                    </div>
                    <Link className="btn line pf-setb" href="/settings">
                        <GearIcon width={18} height={18} />
                        <span>Settings</span>
                    </Link>
                </div>

                {place ? (
                    <section className="pf3-lead">
                        <BookPicture work={place.work} className="pf3-cov" />
                        <div className="pf3-lm">
                            <div className="caps">Your place</div>
                            <Link className="tl pf3-t" href={place.work.href}>
                                {place.work.title}
                            </Link>
                            <div className="pf3-ch">
                                {place.label}
                                {place.title && <span className="pf3-ct">&nbsp;· {place.title}</span>}
                                <span className="here">you’re here</span>
                            </div>
                            <div className="pf3-bar">
                                <i style={{ width: `${place.percent}%` }} />
                            </div>
                            <div className="pf3-sm">{place.left}</div>
                            <div className="pf3-acts">
                                <Link className="btn solid" href={place.href}>
                                    Continue
                                </Link>
                            </div>
                            {place.also.length > 0 && (
                                <div className="pf3-also">
                                    <span className="caps">Also reading</span>
                                    {place.also.map((a) => (
                                        <Link key={a.href} href={a.href}>
                                            <Th picture={a.picture} title={a.title} w={28} h={42} font={6} />
                                            <span>
                                                <b>{a.title}</b>
                                                <small>{a.label}</small>
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>
                ) : (
                    <section className="pf3-lead pf3-none">
                        <div className="caps">Your place</div>
                        <p className="pf3-nt">Start a novel and your place will wait here.</p>
                        <Link className="btn solid" href={paths.library()}>
                            Browse the Library
                        </Link>
                    </section>
                )}

                {shared.length > 0 && (
                    <section className="pf3-sec">
                        <div className="pf3-sh">
                            <h2 className="pf-h2b">
                                Shared with me<small>{shared.length}</small>
                            </h2>
                        </div>
                        <div className="pf-flist">
                            {shared.map((w, n) => (
                                <div className="pf-frow" key={w.id} style={{ ['--i' as string]: n }}>
                                    <Link className="pf-thl" href={w.href}>
                                        <Th picture={w.picture} title={w.title} w={56} h={84} font={9} />
                                    </Link>
                                    <div className="pf-fm">
                                        <Link className="tl pf-ftl" href={w.href}>
                                            {w.title}
                                        </Link>
                                        <div className="pf-st">
                                            {w.private && LOCK}
                                            {w.private ? `Private · ${w.shared}` : w.shared}
                                        </div>
                                        {w.open > 0 && (
                                            <div className="pf-fx2">
                                                {w.open} open<span className="pf-mk">{w.open === 1 ? 'mark' : 'marks'}</span>
                                            </div>
                                        )}
                                    </div>
                                    <Link className="btn line pf-open" href={w.href}>
                                        Open
                                    </Link>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                {following.count > 0 ? (
                    <section className="pf3-sec">
                        <Head title="Following" count={following.count} href="/profile/following" />
                        <div className="pf-flist">
                            {following.rows.map((row, n) => (
                                <FollowRow key={`${row.type}-${row.id}`} row={row} n={n} />
                            ))}
                        </div>
                    </section>
                ) : (
                    <Empty title="Following" text="You’re not following anything yet." link="Browse the Library" />
                )}

                <div className="pf3-two">
                    {likes.count > 0 ? (
                        <section className="pf3-sec">
                            <Head title="Likes" count={likes.count} href="/profile/likes" />
                            <LikeGroups groups={likes.groups} />
                        </section>
                    ) : (
                        <Empty title="Likes" text="Nothing liked yet." link="Start reading" />
                    )}
                    {comments.count > 0 ? (
                        <section className="pf3-sec">
                            <Head title="Comments" count={comments.count} href="/profile/comments" />
                            <div className="pf-clist">
                                {comments.rows.map((row, n) => (
                                    <CommentRow key={row.id} row={row} n={n} />
                                ))}
                            </div>
                        </section>
                    ) : (
                        <Empty title="Comments" text="No comments yet." link="Start reading" />
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

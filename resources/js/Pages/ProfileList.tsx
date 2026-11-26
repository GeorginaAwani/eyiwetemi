import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CommentRow, CommentRowT, FollowRow, FollowRowT, LikeGroup, LikeGroups } from '@/Components/site/ProfileRows';
import SiteLayout from '@/Layouts/SiteLayout';
import { paths } from '@/lib/paths';

type Props =
    | { which: 'following'; count: number; rows: FollowRowT[] }
    | { which: 'likes'; count: number; groups: LikeGroup[] }
    | { which: 'comments'; count: number; rows: CommentRowT[] };

const TITLE = { following: 'Following', likes: 'Likes', comments: 'Comments' } as const;
/** How many rows show at first, and how many each "Show more" adds. */
const STEP = { following: 8, likes: 12, comments: 8 } as const;
const EMPTY = {
    following: ['You’re not following anything yet.', 'Browse the Library'],
    likes: ['Nothing liked yet.', 'Start reading'],
    comments: ['No comments yet.', 'Start reading'],
} as const;

/** All your follows, likes or comments: the same rows as the Profile, a page at a time. */
export default function ProfileList(props: Props) {
    const { which, count } = props;
    const [shown, setShown] = useState<number>(STEP[which]);
    const total = props.which === 'likes' ? props.groups.reduce((n, g) => n + g.rows.length, 0) : props.rows.length;
    const left = total - shown;

    return (
        <SiteLayout title={TITLE[which]}>
            <div className="wrap pf3s pf-all">
                <Link className="pf-back" href="/profile">
                    ‹ Profile
                </Link>
                <h1 className="pf3-h1 pf-lh">
                    {TITLE[which]}
                    <small>{count}</small>
                </h1>
                {total === 0 ? (
                    <div className="pf-empty">
                        <p>{EMPTY[which][0]}</p>
                        <Link className="link" href={paths.library()}>
                            {EMPTY[which][1]}
                        </Link>
                    </div>
                ) : (
                    <>
                        {props.which === 'following' && (
                            <div className="pf-flist">
                                {props.rows.slice(0, shown).map((row, n) => (
                                    <FollowRow key={`${row.type}-${row.id}`} row={row} n={n % STEP.following} />
                                ))}
                            </div>
                        )}
                        {props.which === 'likes' && <LikeGroups groups={props.groups} limit={shown} />}
                        {props.which === 'comments' && (
                            <div className="pf-clist">
                                {props.rows.slice(0, shown).map((row, n) => (
                                    <CommentRow key={row.id} row={row} n={n % STEP.comments} />
                                ))}
                            </div>
                        )}
                        {left > 0 && (
                            <div className="pf-more">
                                <button className="pf-show" type="button" onClick={() => setShown((s) => s + STEP[which])}>
                                    Show {left} more
                                </button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </SiteLayout>
    );
}

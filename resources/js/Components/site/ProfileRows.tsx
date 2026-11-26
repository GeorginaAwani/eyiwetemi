import { Link } from '@inertiajs/react';
import { CSSProperties, useRef, useState } from 'react';
import { json } from '@/lib/http';
import { prefersReducedMotion } from '@/lib/motion';
import Bloom from './Bloom';
import FollowButton from './FollowButton';
import StatusIcon from './StatusIcon';

export type Picture = { src: string | null; color: string; dark?: boolean } | null;
export type FollowRowT = {
    type: 'work' | 'series';
    id: number;
    title: string;
    href: string;
    picture: Picture;
    status: { icon: string | null; text: string };
    extra: { label: string; href: string; pill: string } | null;
};
export type LikeRowT = { type: 'work' | 'work_item' | 'note'; id: number; picture: Picture; thumb: string; label: string; href: string; small: string; quote: boolean; when: string };
export type LikeGroup = { label: string; rows: LikeRowT[] };
export type CommentRowT = {
    id: number;
    picture: Picture;
    thumb: string;
    where: string;
    href: string;
    when: string;
    text: string;
    likes: number;
    replies: number;
    reply: { name: string; author: boolean; photo: string | null; seed: string; tag: string | null; text: string } | null;
};

const i = (n: number) => ({ '--i': n }) as CSSProperties;

/** A small picture: the cover, or its colour with the title on it. */
export function Th({ picture, title, w, h, font }: { picture: Picture; title: string; w: number; h: number; font: number }) {
    if (picture?.src) {
        return (
            <span className="pf-th" style={{ width: w, height: h }}>
                <img src={picture.src} alt="" />
            </span>
        );
    }

    return (
        <span className="pf-th pf-fr" style={{ width: w, height: h, background: picture?.color ?? 'var(--surf)' }}>
            <b style={{ fontSize: font, color: picture?.dark ? '#3A2229' : undefined }}>{title}</b>
        </span>
    );
}

/** A follow: unfollowing fades the row but keeps it, so you can follow again. */
export function FollowRow({ row, n }: { row: FollowRowT; n: number }) {
    const [following, setFollowing] = useState(true);
    const busy = useRef(false);
    const toggle = async () => {
        if (busy.current) {
            return;
        }
        busy.current = true;
        const next = !following;
        setFollowing(next);
        const { status } = await json(next ? 'PUT' : 'DELETE', `/follows/${row.type}/${row.id}`);
        if (status !== 200) {
            setFollowing(!next);
        }
        busy.current = false;
    };

    return (
        <div className={`pf-frow${following ? '' : ' unf'}`} style={i(n)}>
            <Link className="pf-thl" href={row.href}>
                <Th picture={row.picture} title={row.title} w={56} h={84} font={9} />
            </Link>
            <div className="pf-fm">
                <Link className="tl pf-ftl" href={row.href}>
                    {row.title}
                </Link>
                <div className="pf-st">
                    <StatusIcon name={row.status.icon} size={14} />
                    {row.status.text}
                </div>
                {row.extra && (
                    <Link className="pf-fx" href={row.extra.href}>
                        {row.extra.label}
                        <span className="here">{row.extra.pill}</span>
                    </Link>
                )}
            </div>
            <FollowButton following={following} onToggle={toggle} workId={row.id} title={row.title} />
        </div>
    );
}

const Heart = ({ size = 18, filled = true }: { size?: number; filled?: boolean }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" aria-hidden="true">
        <path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z" />
    </svg>
);

/** A like: unliking fades the row but keeps it, so you can like it again. */
export function LikeRow({ row, n }: { row: LikeRowT; n: number }) {
    const [liked, setLiked] = useState(true);
    const busy = useRef(false);
    const heart = useRef<HTMLButtonElement>(null);
    const toggle = async () => {
        if (busy.current) {
            return;
        }
        busy.current = true;
        const next = !liked;
        setLiked(next);
        if (next && !prefersReducedMotion()) {
            heart.current?.querySelector('svg')?.animate([{ transform: 'scale(.7)' }, { transform: 'scale(1.18)' }, { transform: 'none' }], {
                duration: 420,
                easing: 'cubic-bezier(.34,1.45,.64,1)',
            });
        }
        const { status } = await json(next ? 'PUT' : 'DELETE', `/likes/${row.type}/${row.id}`);
        if (status !== 200) {
            setLiked(!next);
        }
        busy.current = false;
    };

    return (
        <div className={`pf-lrow${liked ? '' : ' unl'}`} style={i(n)}>
            <Th picture={row.picture} title={row.thumb} w={32} h={48} font={6} />
            <div className="pf-lm">
                <Link className="pf-ll" href={row.href}>
                    {row.label}
                </Link>
                <small className={row.quote ? 'pf-q' : undefined}>{row.small}</small>
            </div>
            <span className="pf-when">{row.when}</span>
            <button ref={heart} className="pf-heart" type="button" aria-pressed={liked} aria-label={liked ? 'Unlike' : 'Like'} onClick={toggle}>
                <Heart />
            </button>
        </div>
    );
}

/** Likes under "This week", then by month. `limit` shows only the first rows (the rest wait for "Show more"). */
export function LikeGroups({ groups, limit }: { groups: LikeGroup[]; limit?: number }) {
    let n = 0;

    return (
        <>
            {groups.map((g) => {
                const rows = g.rows.filter(() => limit === undefined || n < limit);
                if (!rows.length) {
                    return null;
                }

                return (
                    <section className="pf-lg" key={g.label}>
                        <div className="caps pf-gl">{g.label}</div>
                        {rows.map((row) => {
                            const k = n++;

                            return <LikeRow key={`${row.type}-${row.id}`} row={row} n={k % 12} />;
                        })}
                    </section>
                );
            })}
        </>
    );
}

/** One of your comments, its likes and replies, and the latest reply. */
export function CommentRow({ row, n }: { row: CommentRowT; n: number }) {
    return (
        <Link className="pf-crow" style={i(n)} href={row.href}>
            <div className="pf-cw">
                <Th picture={row.picture} title={row.thumb} w={24} h={36} font={6} />
                <span className="pf-ll">{row.where}</span>
                <span className="pf-when">{row.when}</span>
            </div>
            <p className="pf-ct">{row.text}</p>
            <div className="pf-cm">
                <span>
                    <Heart size={13} filled={false} />
                    {row.likes}
                </span>
                <span>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <path d="M9 14 4 9l5-5" />
                        <path d="M4 9h11a5 5 0 0 1 5 5v6" />
                    </svg>
                    {row.replies === 1 ? '1 reply' : `${row.replies} replies`}
                </span>
            </div>
            {row.reply && (
                <div className="pf-rep">
                    {row.reply.photo ? <img className="pf-me" src={row.reply.photo} alt="" /> : <Bloom seed={row.reply.seed} size={24} />}
                    <div>
                        <b>{row.reply.name}</b>
                        {row.reply.author && <span className="pf-auth">Author</span>}
                        <p>
                            {row.reply.tag && <span className="pf-tag">{row.reply.tag}</span>} {row.reply.text}
                        </p>
                    </div>
                </div>
            )}
        </Link>
    );
}

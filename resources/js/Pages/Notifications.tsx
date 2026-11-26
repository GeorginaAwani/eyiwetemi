import { Link, router } from '@inertiajs/react';
import { CSSProperties, MouseEvent, useRef, useState } from 'react';
import FollowButton from '@/Components/site/FollowButton';
import NotificationRow, { NotificationT, openNotification } from '@/Components/site/NotificationRow';
import { FollowRowT, Th } from '@/Components/site/ProfileRows';
import StatusIcon from '@/Components/site/StatusIcon';
import SiteLayout from '@/Layouts/SiteLayout';
import { json } from '@/lib/http';
import { prefersReducedMotion } from '@/lib/motion';

type Tab = 'all' | 'following';
type Props = {
    tab: Tab;
    total: number;
    unread: number;
    page: { rows: NotificationT[]; more: boolean };
    following: { count: number; rows: FollowRowT[] };
};

/** Rows under their headings (New, This week, then months), keeping the order they came in. */
function groups(rows: NotificationT[]) {
    const out: { label: string; rows: NotificationT[] }[] = [];
    rows.forEach((r) => {
        const last = out[out.length - 1];
        if (last && last.label === r.group) {
            last.rows.push(r);
        } else {
            out.push({ label: r.group, rows: [r] });
        }
    });

    return out;
}

/** A follow, given room: unfollowing fades the row but keeps it, so you can follow again. */
function FollowingRow({ row, i }: { row: FollowRowT; i: number }) {
    const [following, setFollowing] = useState(true);
    const toggle = async () => {
        const next = !following;
        setFollowing(next);
        const { status } = await json(next ? 'PUT' : 'DELETE', `/follows/${row.type}/${row.id}`);
        if (status !== 200) {
            setFollowing(!next);
        }
    };

    return (
        <div className={`frow${following ? '' : ' unf'}`} style={{ '--i': i } as CSSProperties}>
            <Link className="fth" href={row.href}>
                <Th picture={row.picture} title={row.title} w={64} h={96} font={10} />
            </Link>
            <div className="fm">
                <Link className="tl ftl" href={row.href}>
                    {row.title}
                </Link>
                <div className="st">
                    <StatusIcon name={row.status.icon} size={14} />
                    {row.status.text}
                </div>
                {row.extra && (
                    <Link className="fx" href={row.extra.href}>
                        {row.extra.label}
                        <span className="here">{row.extra.pill}</span>
                    </Link>
                )}
            </div>
            <FollowButton following={following} onToggle={toggle} workId={row.id} title={row.title} />
        </div>
    );
}

/** Notifications (T · Tabs): All, newest first under New / This week / months; Following, what you follow. */
export default function Notifications({ tab: firstTab, total, unread: firstUnread, page, following }: Props) {
    const [tab, setTab] = useState<Tab>(firstTab);
    const [swap, setSwap] = useState(false);
    const [rows, setRows] = useState(page.rows);
    const [more, setMore] = useState(page.more);
    const [unread, setUnread] = useState(firstUnread);
    const [loading, setLoading] = useState(false);
    const busy = useRef(false);

    const go = (k: Tab) => (e: MouseEvent) => {
        e.preventDefault();
        if (k === tab) {
            return;
        }
        history.replaceState(history.state, '', k === 'all' ? '/notifications' : '/notifications?tab=following');
        if (prefersReducedMotion()) {
            setTab(k);

            return;
        }
        setSwap(true);
        window.setTimeout(() => {
            setTab(k);
            requestAnimationFrame(() => setSwap(false));
        }, 180);
    };

    const markAll = async () => {
        setRows((all) => all.map((r) => ({ ...r, unread: false })));
        setUnread(0);
        await json('POST', '/notifications/read');
        router.reload({ only: ['auth'] });
    };

    const older = async () => {
        if (busy.current || !rows.length) {
            return;
        }
        busy.current = true;
        setLoading(true);
        const { status, data } = await json<{ rows: NotificationT[]; more: boolean }>('GET', `/notifications/older?before=${rows[rows.length - 1].id}`);
        if (status === 200) {
            setRows((all) => [...all, ...(data.rows ?? [])]);
            setMore(!!data.more);
        }
        setLoading(false);
        busy.current = false;
    };

    const open = (e: MouseEvent, n: NotificationT) => {
        openNotification(e, n);
        if (n.unread) {
            setRows((all) => all.map((r) => (r.id === n.id ? { ...r, unread: false } : r)));
            setUnread((u) => Math.max(0, u - 1));
        }
    };

    let i = 0;

    return (
        <SiteLayout title="Notifications">
            <div className="wrap pt npg">
                <div className="ptop t1">
                    <h1>Notifications</h1>
                    <div className="tabs" role="tablist">
                        <a className={`tab${tab === 'all' ? ' on' : ''}`} href="/notifications" role="tab" aria-selected={tab === 'all'} onClick={go('all')}>
                            All<small>{total}</small>
                        </a>
                        <a className={`tab${tab === 'following' ? ' on' : ''}`} href="/notifications?tab=following" role="tab" aria-selected={tab === 'following'} onClick={go('following')}>
                            Following<small>{following.count}</small>
                        </a>
                    </div>
                </div>
                <div className={`npanel${swap ? ' swap' : ''}`} role="tabpanel">
                    {tab === 'all' ? (
                        <>
                            <div className="tbar">
                                <span className="muted">{unread > 0 ? `${unread} new` : 'Nothing new'}</span>
                                {unread > 0 && (
                                    <button className="mark" type="button" onClick={markAll}>
                                        Mark all read
                                    </button>
                                )}
                            </div>
                            {rows.length === 0 ? (
                                <p className="fnone">No notifications yet.</p>
                            ) : (
                                groups(rows).map((g, gi) => (
                                    <section className="pg" key={`${g.label}-${gi}`}>
                                        <div className="gl caps">{g.label}</div>
                                        <div className="prs">
                                            {g.rows.map((n) => (
                                                <NotificationRow key={n.id} n={n} i={i++ % 20} onOpen={open} />
                                            ))}
                                        </div>
                                    </section>
                                ))
                            )}
                            {more && (
                                <div className="pmore">
                                    <button className="link" type="button" onClick={older} aria-busy={loading || undefined}>
                                        Older notifications
                                    </button>
                                </div>
                            )}
                        </>
                    ) : (
                        <>
                            {following.rows.length > 0 ? (
                                <div className="flist">
                                    {following.rows.map((row, k) => (
                                        <FollowingRow key={`${row.type}-${row.id}`} row={row} i={k} />
                                    ))}
                                </div>
                            ) : (
                                <p className="fnone">You’re not following anything yet.</p>
                            )}
                            <div className="fset">
                                <Link className="link" href="/settings#notifications">
                                    Notification settings
                                </Link>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

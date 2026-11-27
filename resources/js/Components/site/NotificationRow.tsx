import { router } from '@inertiajs/react';
import { CSSProperties, MouseEvent } from 'react';
import { json } from '@/lib/http';
import Bloom from './Bloom';

export type NotificationThumb =
    | { type: 'cover'; title: string; src: string | null; color: string; dark: boolean }
    | { type: 'poem'; title: string }
    | { type: 'avatar'; seed: string }
    | { type: 'photo'; src: string | null }
    | { type: 'note' }
    | { type: 'mark' };

export type NotificationT = {
    id: string;
    title: string;
    small: string;
    href: string;
    thumb: NotificationThumb;
    unread: boolean;
    when: string;
    group: string;
};

/** The picture beside a notification: a cover, a poem card, the mark (for her pages), or the person who replied. */
export function NotificationPicture({ thumb, size }: { thumb: NotificationThumb; size: 'page' | 'menu' }) {
    const [w, h] = size === 'page' ? [40, 60] : [32, 48];
    const round = size === 'page' ? 40 : 32;
    const cls = size === 'page' ? 'th' : '';

    switch (thumb.type) {
        case 'mark':
            return <img className="fpv-nt" src="/img/mark/icon-192.png" alt="" width={round} height={round} />;
        case 'avatar':
            return size === 'page' ? (
                <span className="th av">
                    <Bloom seed={thumb.seed} size={round} />
                </span>
            ) : (
                <Bloom seed={thumb.seed} size={round} />
            );
        case 'photo':
            return thumb.src ? (
                <img className={size === 'page' ? 'th av' : undefined} src={thumb.src} alt="" style={size === 'menu' ? { width: round, height: round, borderRadius: '50%' } : undefined} />
            ) : (
                <span className={size === 'page' ? 'th av' : undefined} />
            );
        case 'poem':
            return (
                <span className={size === 'page' ? 'th pt' : 'pthumb'} style={{ width: w, height: h }}>
                    {thumb.title}
                </span>
            );
        case 'note':
            return (
                <span className={size === 'page' ? 'th pt' : 'pthumb'} style={{ width: w, height: h }}>
                    Note
                </span>
            );
        default:
            return thumb.src ? (
                size === 'page' ? (
                    <span className={cls} style={{ width: w, height: h }}>
                        <img src={thumb.src} alt="" />
                    </span>
                ) : (
                    <img src={thumb.src} alt="" />
                )
            ) : (
                <span className={size === 'page' ? 'th fr' : 'pthumb'} style={{ width: w, height: h, background: thumb.color, color: thumb.dark ? '#3A2229' : '#fff' }}>
                    {size === 'page' ? <b style={{ fontSize: 6 }}>{thumb.title}</b> : thumb.title}
                </span>
            );
    }
}

/**
 * Opening a notification marks it read, then goes to what it's about. Inertia carries the page change.
 */
export function openNotification(e: MouseEvent, n: NotificationT, onRead?: (unread: number) => void) {
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return;
    }
    e.preventDefault();
    if (n.unread) {
        json<{ unread: number }>('POST', `/notifications/${n.id}/read`).then(({ data }) => onRead?.(data.unread ?? 0));
    }
    router.visit(n.href);
}

/** A row on the Notifications page: picture, what happened, a line from it, when, and the unread dot. */
export default function NotificationRow({ n, i, onOpen }: { n: NotificationT; i: number; onOpen?: (e: MouseEvent, n: NotificationT) => void }) {
    return (
        <a className={`pr${n.unread ? ' unread' : ''}`} href={n.href} style={{ '--i': i } as CSSProperties} onClick={(e) => (onOpen ? onOpen(e, n) : openNotification(e, n))}>
            <NotificationPicture thumb={n.thumb} size="page" />
            <span className="pt2">
                <span className="tt">{n.title}</span>
                <small>{n.small}</small>
            </span>
            <span className="pw">
                {n.when}
                <i />
            </span>
        </a>
    );
}

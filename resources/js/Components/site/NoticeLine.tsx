import { Link, usePage } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import { prefersReducedMotion } from '@/lib/motion';
import { NoticeDot } from './Icons';

const KEY = 'eyiNoticeX';

/**
 * The notice line sits under the header. Closing it is remembered per text, so a new notice shows
 * again. Where a page has its own line (Home's newest note), that line comes back once the notice is closed.
 */
export default function NoticeLine({ fallback }: { fallback?: ReactNode }) {
    const { notice } = usePage().props;
    const [closed, setClosed] = useState<string | null>(() => {
        try {
            return localStorage.getItem(KEY);
        } catch {
            return null;
        }
    });
    const [going, setGoing] = useState(false);

    if (!notice || closed === notice.text) {
        return <>{fallback}</>;
    }

    const close = () => {
        try {
            localStorage.setItem(KEY, notice.text);
        } catch {
            /* remembered for this visit only */
        }
        setGoing(true);
        window.setTimeout(() => setClosed(notice.text), prefersReducedMotion() ? 0 : 420);
    };

    const isLocal = (href: string) => href.startsWith('/');
    const linkTo = (href: string, className: string, children: ReactNode) =>
        isLocal(href) ? (
            <Link className={className} href={href}>
                {children}
            </Link>
        ) : (
            <a className={className} href={href}>
                {children}
            </a>
        );

    return (
        <div className={`wrap noteline notice in${going ? ' gone' : ''}`} id="noteline" role="region" aria-label="Notice">
            <span className="dt" aria-hidden="true">
                <NoticeDot />
            </span>
            {notice.href ? linkTo(notice.href, 't', notice.text) : <span className="t">{notice.text}</span>}
            {notice.href && notice.go && linkTo(notice.href, 'go', notice.go)}
            <button className="x" type="button" aria-label="Hide this notice" onClick={close}>
                ✕
            </button>
        </div>
    );
}

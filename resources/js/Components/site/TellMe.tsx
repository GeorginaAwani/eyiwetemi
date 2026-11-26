import { KeyboardEvent, MouseEvent } from 'react';
import { useFollow } from '@/lib/live';

const Tick = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);

/**
 * "Tell me when it's out" on a work that isn't out yet: follows it. Once on, it reads "You'll hear when it's out"
 * with a tick; tapping again stops it. Inside a row link it's a span, so the row stays one link.
 */
export default function TellMe({ id, title, following, inLink, size }: { id: number; title: string; following: boolean; inLink?: boolean; size?: number }) {
    const { following: on, toggle } = useFollow('work', id, following, { why: `Sign in to hear when ${title} is out` });
    const go = (e: MouseEvent | KeyboardEvent) => {
        e.preventDefault();
        e.stopPropagation();
        void toggle();
    };
    const inner = (
        <>
            <span className="tm-a">Tell me when it’s out</span>
            <span className="tm-b">
                <Tick />
                You’ll hear when it’s out
            </span>
        </>
    );
    const props = { className: `link tellme${on ? ' on' : ''}`, 'aria-pressed': on, style: size ? { fontSize: size } : undefined };

    return inLink ? (
        <span
            {...props}
            role="button"
            tabIndex={0}
            onClick={go}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    go(e);
                }
            }}
        >
            {inner}
        </span>
    ) : (
        <button {...props} type="button" onClick={go}>
            {inner}
        </button>
    );
}

import { ButtonHTMLAttributes, ReactNode, useLayoutEffect, useRef, useState } from 'react';
import { RetryIcon, SentTick } from './Icons';

export type SendState = 'idle' | 'sending' | 'sent' | 'failed';

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & {
    state: SendState;
    children: ReactNode;
    /** Words beside the tick once it's sent ("Subscribed"); a bare tick when empty. */
    sentLabel?: string;
    /** Words beside the retry arrow when it fails ("Try again"); a bare arrow when empty. */
    retryLabel?: string;
};

/**
 * Sending (R · Ring): a hairline runs round the button until the work is done; sent turns it rose with a
 * tick that draws; failed is a rose outline with a retry arrow and a little nudge.
 */
export default function SendButton({ state, children, sentLabel, retryLabel, className = '', ...rest }: Props) {
    const ref = useRef<HTMLButtonElement>(null);
    const [ring, setRing] = useState<{ w: number; h: number } | null>(null);

    useLayoutEffect(() => {
        if (state !== 'sending' || !ref.current) {
            setRing(null);

            return;
        }
        const b = ref.current.getBoundingClientRect();
        setRing({ w: b.width + 8, h: b.height + 8 });
    }, [state]);

    const stateClass = state === 'sending' ? 'is-sending' : state === 'sent' ? 'is-sent' : state === 'failed' ? 'is-failed' : '';

    let content: ReactNode = children;
    if (state === 'sent') {
        content = sentLabel ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <SentTick />
                {sentLabel}
            </span>
        ) : (
            <SentTick />
        );
    } else if (state === 'failed') {
        content = retryLabel ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <RetryIcon />
                {retryLabel}
            </span>
        ) : (
            <RetryIcon />
        );
    }

    return (
        <button ref={ref} className={`${className} ${stateClass}`.trim()} aria-busy={state === 'sending' || undefined} {...rest}>
            {state === 'idle' || state === 'sending' ? <span className="lbl">{children}</span> : content}
            {ring && (
                <svg className="sring" width={ring.w} height={ring.h} viewBox={`0 0 ${ring.w} ${ring.h}`} style={{ left: -4, top: -4, ['--sr' as string]: `${Math.round(900 + (ring.w + ring.h) * 2.4)}ms` }} aria-hidden="true">
                    <rect x="1" y="1" width={ring.w - 2} height={ring.h - 2} rx={(ring.h - 2) / 2} pathLength="100" />
                </svg>
            )}
        </button>
    );
}

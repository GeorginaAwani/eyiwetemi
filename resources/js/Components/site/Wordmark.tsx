import { Link } from '@inertiajs/react';
import { CSSProperties } from 'react';

/** The wordmark: "eyi" in rose, both i-dots are little spines that part on hover (and bob while loading). */
export default function Wordmark({ style }: { style?: CSSProperties }) {
    return (
        <Link className="wm" href="/" aria-label="eyiwetemi" style={style}>
            <span className="r" aria-hidden="true">ey</span>
            <span className="r i first" aria-hidden="true">ı</span>
            <span aria-hidden="true">wetem</span>
            <span className="i" aria-hidden="true">ı</span>
        </Link>
    );
}

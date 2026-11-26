import { askSignIn } from '@/lib/signin';

const Bell = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
);
const Check = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);
const Cross = () => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
        <path d="M18 6 6 18M6 6l12 12" />
    </svg>
);

/**
 * The "Follow a work" pill: Follow = outline + bell; Following = grey + tick; hovering Following offers Unfollow.
 * Signed-out readers are asked to sign in first.
 */
export default function FollowButton({ following, onToggle, workId, title }: { following: boolean; onToggle?: () => void; workId: number; title: string }) {
    return (
        <button
            type="button"
            className={`follow${following ? '' : ' off'}`}
            aria-pressed={following}
            onClick={() => (onToggle ? onToggle() : askSignIn({ follow: workId, why: `Sign in to follow ${title}` }))}
        >
            <span className="fi fi-b">
                <Bell />
            </span>
            <span className="fi fi-c">
                <Check />
            </span>
            <span className="fi fi-x">
                <Cross />
            </span>
            <span className="fl fl-f">Follow</span>
            <span className="fl fl-g">Following</span>
            <span className="fl fl-u">Unfollow</span>
        </button>
    );
}

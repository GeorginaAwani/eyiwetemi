/** The admin's icons, drawn as in the approved prototype. */
const line = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

export const NAV_ICONS: Record<string, JSX.Element> = {
    desk: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <path d="M3 10h18M5 10v10M19 10v10M8 10V6.5A2.5 2.5 0 0 1 10.5 4h3A2.5 2.5 0 0 1 16 6.5V10" />
        </svg>
    ),
    works: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
        </svg>
    ),
    notes: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <path d="M6 3h9l4 4v14H6z" />
            <path d="M14 3v5h5" />
        </svg>
    ),
    comments: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <path d="M5 18.5V6.5A1.5 1.5 0 0 1 6.5 5h11A1.5 1.5 0 0 1 19 6.5v8a1.5 1.5 0 0 1-1.5 1.5H9z" />
        </svg>
    ),
    reviewers: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <circle cx="9" cy="8" r="3.2" />
            <path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M15.5 5.2a3 3 0 0 1 0 5.6M17.5 14.3c1.7.6 2.7 2.2 3 4.7" />
        </svg>
    ),
    stats: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <path d="M4 17l5-5 4 3 7-8" />
            <path d="M4 20h16" />
        </svg>
    ),
    newsletter: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3.5 6.5 8.5 6.5 8.5-6.5" />
        </svg>
    ),
    site: (
        <svg width="19" height="19" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
        </svg>
    ),
};

export const Chevron = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="m6 9 6 6 6-6" />
    </svg>
);
export const External = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" {...line} strokeWidth="1.9">
        <path d="M7 17 17 7M8 7h9v9" />
    </svg>
);
export const ThemeIcon = () => (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" />
    </svg>
);
export const Tick = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" {...line} strokeWidth="3">
        <path pathLength={1} d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);
export const Next = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" {...line} strokeWidth="2">
        <path d="M5 12h14M13 6l6 6-6 6" />
    </svg>
);
export const Mail = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="1.8">
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m3.5 6.5 8.5 6.5 8.5-6.5" />
    </svg>
);
export const Clock = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
    </svg>
);
export const Back = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="1.9">
        <path d="m15 18-6-6 6-6" />
    </svg>
);
export const Alert = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7.5v5.5M12 16.4v.1" />
    </svg>
);
export const Eye = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" {...line} strokeWidth="1.7">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
        <circle cx="12" cy="12" r="3" />
    </svg>
);
export const EyeOff = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" {...line} strokeWidth="1.7">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
        <circle cx="12" cy="12" r="3" />
        <path d="M4 4l16 16" />
    </svg>
);
export const Google = () => (
    <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
        <path
            fill="#FFC107"
            d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"
        />
        <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
    </svg>
);
/** The sending ring that runs round a round button while it waits. */
export const Ring = () => (
    <svg className="ad-ring" aria-hidden="true">
        <rect pathLength={100} />
    </svg>
);

export const Pen = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="2" aria-hidden="true">
        <path d="M4 20h4L19 9l-4-4L4 16z" />
        <path d="m13.5 6.5 4 4" />
    </svg>
);
export const Plus = () => (
    <svg width="17" height="17" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M12 5v14M5 12h14" />
    </svg>
);
export const Heart = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z" />
    </svg>
);
export const Broken = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-2.4-6.8" />
        <path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 2.4 6.8" />
        <path d="M4 4l3 3M20 20l-3-3" />
    </svg>
);
export const Pin = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M9 4h6l-1 5 3.5 3.5h-11L10 9z" />
        <path d="M12 12.5V20" />
    </svg>
);
export const Dot = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="4" fill="currentColor" />
    </svg>
);

/* ---------- works (step 3) ---------- */
const fact = (paths: JSX.Element) => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        {paths}
    </svg>
);

/** The small icons before a work's status and who can see it. */
export const FACT_ICONS: Record<string, JSX.Element> = {
    lock: fact(
        <>
            <rect x="5" y="11" width="14" height="9" rx="2" />
            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
        </>,
    ),
    people: fact(
        <>
            <circle cx="9" cy="8" r="3.2" />
            <path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M15.5 5.2a3 3 0 0 1 0 5.6M17.5 14.3c1.7.6 2.7 2.2 3 4.7" />
        </>,
    ),
    globe: fact(
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
        </>,
    ),
    draft: fact(<path d="M4 20h4L19 9l-4-4L4 16z" strokeDasharray="3 2.4" />),
    pen: fact(
        <>
            <path d="M4 20h4L19 9l-4-4L4 16z" />
            <path d="m13.5 6.5 4 4" />
        </>,
    ),
    done: fact(
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12.5 2.8 2.8L16.5 9.5" />
        </>,
    ),
    book: fact(
        <>
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
        </>,
    ),
    clock: fact(
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </>,
    ),
};

export const Search = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
    </svg>
);
export const Close = ({ size = 20, width = 1.8 }: { size?: number; width?: number }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth={width} aria-hidden="true">
        <path d="M6 6l12 12M18 6 6 18" />
    </svg>
);
export const BackSmall = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="2" aria-hidden="true">
        <path d="m15 18-6-6 6-6" />
    </svg>
);
export const Globe = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line} strokeWidth="2" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" />
    </svg>
);
export const CloudOff = ({ size = 16 }: { size?: number }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M7 18h10.5a4 4 0 0 0 1.2-7.8A6.2 6.2 0 0 0 7.1 8.6 4.8 4.8 0 0 0 7 18z" />
        <path d="M3.5 3.5l17 17" />
    </svg>
);
export const Phone = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <rect x="7" y="2.5" width="10" height="19" rx="2.5" />
        <path d="M11 18.5h2" />
    </svg>
);
export const Laptop = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <rect x="4" y="5" width="16" height="11" rx="1.5" />
        <path d="M2 19h20" />
    </svg>
);
export const Saved = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" {...line} strokeWidth="2.4" aria-hidden="true">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);
export const BoxTick = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" {...line} strokeWidth="3">
        <path d="m5 12.5 4.5 4.5L19 7.5" pathLength={1} />
    </svg>
);
export const Book15 = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" {...line} strokeWidth="1.8" aria-hidden="true">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
    </svg>
);

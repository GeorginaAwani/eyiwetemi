import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

export const ChevronDown = (p: IconProps) => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...p}>
        <path d="m6 9 6 6 6-6" />
    </svg>
);

export const SearchIcon = (p: IconProps) => (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true" {...p}>
        <circle className="c" cx="11" cy="11" r="7" />
        <path className="h" d="m20 20-3.5-3.5" />
    </svg>
);

export const ThemeIcon = (p: IconProps) => (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true" {...p}>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" />
    </svg>
);

export const MenuIcon = (p: IconProps) => (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true" {...p}>
        <path className="l1" d="M4 7h16" />
        <path className="l2" d="M4 12h16" />
        <path className="l3" d="M4 17h16" />
    </svg>
);

export const CloseIcon = (p: IconProps) => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true" {...p}>
        <path d="M6 6l12 12M18 6 6 18" />
    </svg>
);

export const SentTick = (p: IconProps) => (
    <svg className="tk2" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...p}>
        <path pathLength="1" d="m5 12.5 4.5 4.5L19 7" />
    </svg>
);

export const RetryIcon = (p: IconProps) => (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...p}>
        <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
        <path d="M3 3v5h5" />
    </svg>
);

export const NoticeDot = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="5" fill="currentColor" />
    </svg>
);

/** Icons for the sites she links to (footer, About). */
const SOCIAL: Record<string, JSX.Element> = {
    X: (
        <>
            <path d="M4.5 4h4.2L19.5 20h-4.2z" />
            <path d="M19 4l-5.9 6.8M5 20l5.9-6.8" />
        </>
    ),
    Instagram: (
        <>
            <rect x="3.8" y="3.8" width="16.4" height="16.4" rx="4.8" />
            <circle cx="12" cy="12" r="3.9" />
            <circle cx="17.1" cy="6.9" r=".6" fill="currentColor" />
        </>
    ),
    Goodreads: (
        <>
            <circle cx="11.5" cy="10" r="4.6" />
            <path d="M16.1 4.5v9.8a5 5 0 0 1-9.4 2.4" />
        </>
    ),
    TikTok: (
        <>
            <path d="M13.5 3.8v11a3.6 3.6 0 1 1-3.6-3.6" />
            <path d="M13.5 3.8c.5 2.8 2.4 4.4 5.3 4.6" />
        </>
    ),
    Threads: (
        <>
            <path d="M16.5 11.2c-.6-2.6-2.4-3.7-4.6-3.7-2.6 0-4.4 1.9-4.4 4.5s1.8 4.5 4.6 4.5c2.3 0 4-1.3 4-3.3 0-1.7-1.3-2.7-3.2-2.7-1.6 0-2.7.8-2.7 2 0 1 .8 1.7 2 1.7 1.8 0 2.8-1.6 2.7-4.3" />
            <path d="M19.5 8.5C18.4 5.3 15.6 3.5 12 3.5 6.9 3.5 3.8 7 3.8 12s3.1 8.5 8.2 8.5c3.3 0 5.9-1.6 7-4.2" />
        </>
    ),
    YouTube: (
        <>
            <rect x="2.8" y="5.5" width="18.4" height="13" rx="4" />
            <path d="m10.2 9.3 4.6 2.7-4.6 2.7z" />
        </>
    ),
    Facebook: <path d="M14.5 21v-7.5h2.6l.4-3h-3V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2.3H9v3h2.5V21" />,
    Substack: <path d="M5.5 4.5h13M5.5 8h13M5.5 11.5h13v8.5l-6.5-3.6L5.5 20z" />,
    Website: (
        <>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17M12 3.5c2.4 2.5 3.4 5.3 3.4 8.5s-1 6-3.4 8.5c-2.4-2.5-3.4-5.3-3.4-8.5s1-6 3.4-8.5z" />
        </>
    ),
};

export const SocialIcon = ({ network, size = 19 }: { network: string; size?: number }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {SOCIAL[network] ?? SOCIAL.Website}
    </svg>
);

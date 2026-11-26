/** The writing screen's icons, drawn as in the prototype. */
const line = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 1.8, 'aria-hidden': true } as const;
const i18 = (children: JSX.Element) => (
    <svg width="18" height="18" viewBox="0 0 24 24" {...line}>
        {children}
    </svg>
);

export const LinkIcon = () => i18(<><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" /></>);
export const QuoteIcon = ({ size = 18 }: { size?: number }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line}>
        <path d="M5 11h4v6H5zM5 11c0-3.2 1.3-5 4-6M14 11h4v6h-4zM14 11c0-3.2 1.3-5 4-6" />
    </svg>
);
export const FootnoteIcon = () => i18(<><path d="M3.5 18h10M3.5 13h10M3.5 8h7" /><path d="M16.5 6l2.2-1.6v7.1" /></>);
export const ListIcon = () => i18(<><path d="M9 6.5h11M9 12h11M9 17.5h11" /><circle cx="4.6" cy="6.5" r="1" fill="currentColor" /><circle cx="4.6" cy="12" r="1" fill="currentColor" /><circle cx="4.6" cy="17.5" r="1" fill="currentColor" /></>);
export const ImageIcon = () => i18(<><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="10" r="1.6" /><path d="m21 16-5-5-9 8" /></>);
export const BreakIcon = () => i18(<><path d="M3 12h6.5M14.5 12H21" /><circle cx="12" cy="12" r="1.3" fill="currentColor" /></>);
export const FullIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" {...line}>
        <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
    </svg>
);
export const KeyboardDown = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" {...line}>
        <path d="m6 9 6 6 6-6" />
    </svg>
);
export const MusicIcon = () => (
    <svg width="15" height="15" viewBox="0 0 24 24" {...line}>
        <path d="M9 18V6l10-2v12" />
        <circle cx="6.5" cy="18" r="2.5" />
        <circle cx="16.5" cy="16" r="2.5" />
    </svg>
);

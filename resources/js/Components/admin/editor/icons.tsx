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

const sized = (size: number, width: number, children: JSX.Element) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...line} strokeWidth={width}>
        {children}
    </svg>
);
export const ChaptersIcon = () => sized(19, 1.8, <path d="M8 6.5h12M8 12h12M8 17.5h12M4 6.5h.5M4 12h.5M4 17.5h.5" />);
export const VersionsIcon = () => sized(19, 1.8, <><path d="M3.6 12a8.4 8.4 0 1 0 2.6-6.1" /><path d="M3.4 4.2v4.2h4.2" /><path d="M12 8v4.2l2.8 1.8" /></>);
export const GripIcon = () => sized(18, 1.8, <path d="M5 9h14M5 15h14" />);
export const FoldIcon = () => sized(16, 1.8, <path d="m6 9 6 6 6-6" />);
export const PovIcon = () => sized(15, 1.8, <><circle cx="12" cy="8" r="3.4" /><path d="M5 20c.8-3.6 3.6-5.6 7-5.6s6.2 2 7 5.6" /></>);
export const Link15 = () => sized(15, 1.8, <><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" /></>);
export const Clock15 = () => sized(15, 1.8, <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>);
export const Clock18 = () => sized(18, 2, <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>);
export const Done15 = () => sized(15, 1.8, <><circle cx="12" cy="12" r="9" /><path d="m8 12.5 2.8 2.8L16.5 9.5" /></>);
export const Pen15 = () => sized(15, 1.8, <><path d="M4 20h4L19 9l-4-4L4 16z" /><path d="m13.5 6.5 4 4" /></>);
export const Draft15 = () => sized(15, 1.8, <path d="M4 20h4L19 9l-4-4L4 16z" strokeDasharray="3 2.4" />);

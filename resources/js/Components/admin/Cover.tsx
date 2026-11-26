export type CoverData = { src: string | null; color: string; dark: boolean };

/** A work's picture in the admin: the uploaded cover, or its coloured front with the title (dark lettering on light fronts). */
export default function Cover({ cover, title, className = 'k-cov', style }: { cover: CoverData; title: string; className?: string; style?: React.CSSProperties }) {
    return (
        <span className={`${className}${!cover.src && cover.dark ? ' lt' : ''}`} style={{ ...(cover.src ? {} : { background: cover.color }), ...style }}>
            {cover.src ? <img src={cover.src} alt="" /> : <i>{title}</i>}
        </span>
    );
}

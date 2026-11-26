import { router } from '@inertiajs/react';
import { CSSProperties } from 'react';
import { WorkCard } from '@/types/works';

type Props = {
    work: Pick<WorkCard, 'title' | 'href' | 'cover' | 'kind' | 'kindLabel' | 'isCollection'>;
    width?: number;
    height?: number;
    /** Landscape pictures (stories, poems, essays) are 3:2; novels are 2:3. */
    landscape?: boolean;
    /** Sizes for the lettering on a coloured front. */
    type?: { k: number; t: number; w?: number; pad: string };
    /** Small novel fronts open a crack on hover and show the first line on the page beneath. */
    firstLine?: string | null;
    reveal?: number;
    style?: CSSProperties;
    className?: string;
};

const frontKind = (label: string) => (label === 'A short story' ? 'A story' : label);

/**
 * A work's picture. Only novels get book-like hovers (the cover turns on its spine; a small front opens a crack).
 * Every other picture shares one quick glint. Clicking the picture opens the work, like its title.
 */
export default function BookPicture({ work, width, height, landscape = false, type, firstLine, reveal, style, className = '' }: Props) {
    const isNovel = work.kind === 'novel';
    const hasImage = work.cover.src !== null;
    const opens = isNovel && !hasImage && firstLine !== undefined;
    const hover = isNovel ? 'turn' : 'mat';
    const classes = ['book', hasImage ? '' : 'front', opens ? 'open' : hover, work.isCollection ? 'coll' : '', className].filter(Boolean).join(' ');
    const size: CSSProperties = { ...(width ? { width } : {}), ...(height ? { height } : {}), ...(landscape && !height ? { aspectRatio: '3/2' } : {}) };
    const ink = work.cover.dark ? '#3A2229' : undefined;
    const reveals = reveal !== undefined ? { 'data-reveal': '', style: { ...size, ['--i' as string]: reveal, ...style } } : { style: { ...size, ...style } };

    const lettering = (
        <>
            <span className="k" style={{ fontSize: type?.k }}>
                {frontKind(work.kindLabel)}
            </span>
            <span className="t" style={{ fontSize: type?.t, color: ink }}>
                {work.title}
            </span>
            {type?.w ? (
                <span className="w" style={{ fontSize: type.w }}>
                    eyiwetemi
                </span>
            ) : (
                <span />
            )}
        </>
    );

    const open = () => router.visit(work.href);

    if (hasImage) {
        return (
            <div className={classes} {...reveals} onClick={open} aria-hidden="true">
                <span className="glint" />
                <img src={work.cover.src!} alt="" />
            </div>
        );
    }

    if (opens) {
        const w = width ?? 96;
        return (
            <div className={classes} {...reveals} onClick={open} aria-hidden="true">
                <div className="page" style={{ fontSize: Math.max(10, Math.round(w * 0.058)), ...(landscape ? { paddingLeft: '56%' } : {}) }}>
                    {firstLine}
                </div>
                <div className="lid" style={{ background: work.cover.color, padding: type?.pad, color: ink }}>
                    {lettering}
                </div>
            </div>
        );
    }

    return (
        <div className={classes} {...reveals} onClick={open} aria-hidden="true" style={{ ...reveals.style, background: work.cover.color, padding: type?.pad, color: ink }}>
            <span className="glint" />
            {lettering}
        </div>
    );
}

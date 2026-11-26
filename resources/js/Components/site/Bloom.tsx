import { CSSProperties } from 'react';

const SHADES = ['#A24F68', '#C24E77', '#DD6F94', '#7E3550', '#C46F8A', '#E3A3B6'];
const GROUNDS = ['#F7E4EA', '#F2D7DF', '#FFF6F1', '#F4E0E3', '#FBEFF2'];

/** The prototype's seeded generator: one seed, one picture. */
function hash(seed: string): () => number {
    let h = 2166136261;
    for (const c of seed) {
        h ^= c.charCodeAt(0);
        h = Math.imul(h, 16777619);
    }

    return () => {
        h ^= h << 13;
        h ^= h >>> 17;
        h ^= h << 5;

        return h >>> 0;
    };
}

/** Background for a Bloom profile photo: soft pink blooms on a light pink circle. */
export function bloomStyle(seed: string, size: number): CSSProperties {
    const r = hash(seed);
    const ground = GROUNDS[r() % GROUNDS.length];
    const layers = [0, 1, 2].map(() => {
        const c = SHADES[r() % SHADES.length];
        const x = 15 + (r() % 70);
        const y = 15 + (r() % 70);
        const rad = 38 + (r() % 30);

        return `radial-gradient(circle at ${x}% ${y}%, ${c} 0%, ${c}00 ${rad}%)`;
    });

    return {
        display: 'block',
        width: size,
        height: size,
        borderRadius: '50%',
        boxShadow: `inset 0 0 0 ${Math.max(1, Math.round(size / 64))}px rgba(162,79,104,.22)`,
        background: `${layers.join(',')},${ground}`,
    };
}

/** A reader's generated profile photo, or hers (a photo with a thin rose ring) on her own comments. */
export default function Bloom({ seed, size, photo, name }: { seed: string; size: number; photo?: string | null; name?: string }) {
    if (photo) {
        return (
            <span className="av me" style={{ width: size, height: size }}>
                <img src={photo} alt={name ?? ''} />
            </span>
        );
    }

    return (
        <span className="av" aria-hidden="true">
            <span style={bloomStyle(seed, size)} />
        </span>
    );
}

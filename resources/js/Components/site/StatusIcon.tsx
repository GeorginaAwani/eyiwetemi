/** Status icons used in rows and leads: pen = in progress, check = finished, clock = coming soon, layers = collection, book = series. */
export default function StatusIcon({ name, size = 15, stroke = 'currentColor' }: { name: string | null; size?: number; stroke?: string }) {
    if (!name) {
        return null;
    }

    const common = { width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke, strokeWidth: 2, 'aria-hidden': true } as const;

    switch (name) {
        case 'pen':
            return (
                <svg {...common} strokeLinecap="round">
                    <path d="M12 20h9" />
                    <path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L7.4 18.6l-3.8 1.3 1.3-3.8z" />
                </svg>
            );
        case 'check':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="10" />
                    <path d="m8.5 12 2.5 2.5 4.5-5" />
                </svg>
            );
        case 'clock':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 7v5l3 2" />
                </svg>
            );
        case 'layers':
            return (
                <svg {...common} strokeLinecap="round" strokeLinejoin="round">
                    <path d="m12 2 9 5-9 5-9-5z" />
                    <path d="m3 12 9 5 9-5" />
                    <path d="m3 17 9 5 9-5" />
                </svg>
            );
        case 'book':
            return (
                <svg {...common} strokeLinecap="round" strokeLinejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
                </svg>
            );
        default:
            return null;
    }
}

/** Public addresses of the reading site. Pages that aren't built yet keep their final address. */
export const paths = {
    home: '/',
    library: (tab?: LibraryTab) => (tab ? `/library?tab=${tab}` : '/library'),
    notes: '/notes',
    about: '/about',
    inPrint: '/in-print',
    rss: '/rss',
    privacy: '/privacy',
    terms: '/terms',
} as const;

export type LibraryTab = 'novel' | 'story' | 'poem' | 'essay';

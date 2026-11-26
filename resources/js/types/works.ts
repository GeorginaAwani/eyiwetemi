export type Cover = { src: string | null; color: string; dark: boolean };

export type WorkCard = {
    id: number;
    /** You follow it (coming soon: you'll hear when it's out). */
    following?: boolean;
    kind: 'novel' | 'story' | 'poem' | 'essay' | 'collection';
    kindLabel: string;
    tab: 'novel' | 'story' | 'poem' | 'essay';
    title: string;
    href: string;
    cover: Cover;
    isCollection: boolean;
    status: { icon: string | null; text: string };
    series: { name: string; href: string; position: number } | null;
    inPrint: string | null;
    minutes: number;
    date: string | null;
};

export type ChapterRow = { number: number; title: string | null; label: string; minutes: number; href: string; isNew: boolean };

export type Action = { label: string; href: string; style: 'solid' | 'line' | 'link' };

export type SiteChrome = {
    email: string;
    line: string;
    links: { network: string; url: string }[];
    library: { all: number; novel: number; story: number; poem: number; essay: number };
};

export type Notice = { id: number; text: string; href: string | null; go: string | null };

/** Which header item is the page you're on. */
export type Section = 'about' | 'library' | 'notes';

/** Which footer link is the page you're on. */
export type FooterPage = 'about' | 'notes' | 'in-print' | 'rss' | 'privacy';

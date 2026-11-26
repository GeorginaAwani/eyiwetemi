import { Clock15, Done15, Draft15, Pen15 } from './icons';

export type ChapterRow = {
    id: number;
    type: 'chapter' | 'part' | 'piece';
    number: number;
    title: string;
    description: string;
    pov: string | null;
    words: number;
    pages: number;
    state: 'draft' | 'sch' | 'pub' | 'chg' | 'unpub';
    scheduledAt: string | null;
    href: string;
    ref: string | null;
    now: boolean;
};

const WAT = { timeZone: 'Africa/Lagos' } as const;

/** "Sat 29 Nov" */
export const watDay = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { ...WAT, weekday: 'short', day: 'numeric', month: 'short' }).replace(',', '');
/** "6:00 pm" */
export const watTime = (iso: string) =>
    new Date(iso)
        .toLocaleTimeString('en-GB', { ...WAT, hour: 'numeric', minute: '2-digit', hour12: true })
        .replace(/\s?([ap])\.?m\.?/i, ' $1m');
/** "9 Oct, 6:00 pm" */
export const watWhen = (iso: string) => `${new Date(iso).toLocaleDateString('en-GB', { ...WAT, day: 'numeric', month: 'short' })}, ${watTime(iso)}`;

/** Where a chapter stands: Published, Changes, Sat 6 pm, Draft, Unpublished. */
export function StateFact({ row, short = false }: { row: Pick<ChapterRow, 'state' | 'scheduledAt'>; short?: boolean }) {
    switch (row.state) {
        case 'pub':
            return (
                <span className="k-fact">
                    <Done15 />
                    {short ? '' : 'Published'}
                </span>
            );
        case 'chg':
            return (
                <span className="k-fact w-rose">
                    <Pen15 />
                    Changes
                </span>
            );
        case 'sch':
            return (
                <span className="k-fact w-rose">
                    <Clock15 />
                    {row.scheduledAt ? `${watDay(row.scheduledAt).split(' ')[0]} ${watTime(row.scheduledAt).replace(':00', '')}` : 'Scheduled'}
                </span>
            );
        case 'unpub':
            return (
                <span className="k-fact">
                    <Draft15 />
                    Unpublished
                </span>
            );
        default:
            return (
                <span className="k-fact">
                    <Draft15 />
                    Draft
                </span>
            );
    }
}

import { Link } from "@inertiajs/react";
import { Fragment, ReactNode, useState } from "react";

export type Run = {
    text: string;
    em?: boolean;
    strong?: boolean;
    href?: string;
    /** a footnote: its number, and the note */
    fn?: number;
    note?: string;
};

/**
 * A footnote: its number in the text. On wide screens the note sits in the margin beside the line;
 * on narrower ones a tap unfolds it under the line.
 */
export function Footnote({ n, note }: { n: number; note: string }) {
    const [open, setOpen] = useState(false);

    return (
        <span className={`rfn${open ? " open" : ""}`}>
            <button type="button" className="rfn-n" aria-expanded={open} aria-label={`Note ${n}`} onClick={() => setOpen(!open)}>
                {n}
            </button>
            <span className="rfn-note" role="note">
                <b>{n}</b>
                {note}
            </span>
        </span>
    );
}

/** One run's text in its italics, bold and link (line breaks kept). */
export function styled(r: Run, text: string = r.text): ReactNode {
    let node: ReactNode = text.split("\n").map((part, k) => (
        <Fragment key={k}>
            {k > 0 && <br />}
            {part}
        </Fragment>
    ));
    if (r.em) node = <em>{node}</em>;
    if (r.strong) node = <strong>{node}</strong>;
    if (r.href) {
        node = r.href.startsWith("/") ? (
            <Link className="link" href={r.href}>
                {node}
            </Link>
        ) : (
            <a className="link" href={r.href} target="_blank" rel="noopener">
                {node}
            </a>
        );
    }

    return node;
}

/** Text runs with italics, bold and links. */
export default function Runs({ runs }: { runs: Run[] }) {
    return (
        <>
            {runs.map((r, i) => {
                if (r.fn !== undefined) {
                    return r.fn > 0 ? <Footnote key={i} n={r.fn} note={r.note ?? ""} /> : null;
                }

                return <Fragment key={i}>{styled(r)}</Fragment>;
            })}
        </>
    );
}

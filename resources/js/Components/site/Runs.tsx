import { Link } from "@inertiajs/react";
import { Fragment, ReactNode } from "react";

export type Run = {
    text: string;
    em?: boolean;
    strong?: boolean;
    href?: string;
};

/** Text runs with italics, bold and links. */
export default function Runs({ runs }: { runs: Run[] }) {
    return (
        <>
            {runs.map((r, i) => {
                let node: ReactNode = r.text.split("\n").map((part, k) => (
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
                        <a
                            className="link"
                            href={r.href}
                            target="_blank"
                            rel="noopener"
                        >
                            {node}
                        </a>
                    );
                }
                return <Fragment key={i}>{node}</Fragment>;
            })}
        </>
    );
}

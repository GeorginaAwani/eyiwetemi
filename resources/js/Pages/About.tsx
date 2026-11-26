import { usePage } from "@inertiajs/react";
import { CSSProperties, Fragment, ReactNode, useEffect, useRef } from "react";
import SiteLayout from "@/Layouts/SiteLayout";

type Paragraph = { text: string; note: string | null; quote: boolean };
type Section = { heading: string; paragraphs: Paragraph[] };
type Props = {
    portrait: { src: string; alt: string } | null;
    hello: { heading: string; paragraphs: string[] };
    sections: Section[];
    books: string | null;
};

const v = (vars: Record<string, string | number>) => vars as CSSProperties;

/** "I'm Georgina." breaks after its first word, and each word inks in. */
function Hello({ heading }: { heading: string }) {
    const space = heading.indexOf(" ");
    const lines =
        space > 0
            ? [heading.slice(0, space), heading.slice(space + 1)]
            : [heading];
    let w = 0;

    return (
        <h1>
            {lines.map((line, i) => (
                <Fragment key={i}>
                    {i > 0 && <br />}
                    {line.split(" ").map((word, k) => (
                        <Fragment key={k}>
                            {k > 0 && " "}
                            <span className="word" style={v({ "--w": w++ })}>
                                {word}
                            </span>
                        </Fragment>
                    ))}
                </Fragment>
            ))}
        </h1>
    );
}

/**
 * Her sections laid on one grid: each paragraph on its own row with its margin note beside it.
 * The books (and her links elsewhere) take the first free margin rows of the last section, as in the prototype.
 */
function Story({
    sections,
    books,
    links,
}: {
    sections: Section[];
    books: string | null;
    links: { network: string; url: string }[];
}) {
    const out: ReactNode[] = [];
    const noted = new Set<number>();
    let row = 1;
    let n = 0;
    let lastRows: number[] = [];

    sections.forEach((section, si) => {
        out.push(
            <h2
                key={`h${si}`}
                className={si ? "h2b" : undefined}
                data-reveal=""
                style={{ gridRow: row++ }}
            >
                {section.heading}
            </h2>,
        );
        const rows: number[] = [];
        section.paragraphs.forEach((p, pi) => {
            if (p.quote) {
                out.push(
                    <p
                        key={`q${si}-${pi}`}
                        className="q"
                        data-reveal=""
                        style={{ gridRow: row++ }}
                    >
                        {p.text}
                    </p>,
                );

                return;
            }
            rows.push(row);
            out.push(
                <p
                    key={`p${si}-${pi}`}
                    className="sp"
                    data-reveal=""
                    data-n={n++}
                    style={v({ gridRow: row, "--i": pi + 1 })}
                >
                    {p.text}
                </p>,
            );
            if (p.note) {
                noted.add(row);
                out.push(
                    <div
                        key={`n${si}-${pi}`}
                        className="note"
                        data-reveal=""
                        style={v({ gridRow: row, "--i": pi + 3 })}
                    >
                        <i />
                        <span>{p.note}</span>
                    </div>,
                );
            }
            row++;
        });
        if (si === sections.length - 1) lastRows = rows;
    });

    const free = lastRows.filter((r) => !noted.has(r));
    if (books) {
        out.push(
            <div
                key="books"
                className="mbook"
                data-reveal=""
                style={{ gridRow: free[0] ?? lastRows[0] ?? row }}
            >
                <span className="caps" style={{ fontSize: 10 }}>
                    Books that made me
                </span>
                <b>{books}</b>
            </div>,
        );
    }
    if (links.length) {
        out.push(
            <div
                key="links"
                className="mbook mels"
                data-reveal=""
                style={{
                    gridRow:
                        free[2] ??
                        free[1] ??
                        lastRows[lastRows.length - 1] ??
                        row,
                }}
            >
                <span className="caps" style={{ fontSize: 10 }}>
                    Elsewhere
                </span>
                <span className="ew">
                    {links.map((l) => (
                        <a
                            key={l.url}
                            href={l.url}
                            target="_blank"
                            rel="noopener me"
                        >
                            {l.network}
                        </a>
                    ))}
                </span>
            </div>,
        );
    }

    return <>{out}</>;
}

export default function About({ portrait, hello, sections, books }: Props) {
    const { site } = usePage().props;
    const photo = useRef<HTMLDivElement>(null);
    const story = useRef<HTMLElement>(null);

    /* the portrait draws in once the page has painted */
    useEffect(() => {
        let second = 0;
        const first = requestAnimationFrame(() => {
            second = requestAnimationFrame(() =>
                photo.current?.classList.add("in"),
            );
        });

        return () => {
            cancelAnimationFrame(first);
            cancelAnimationFrame(second);
        };
    }, []);

    /* reading along: the margin note beside the paragraph you're reading comes forward */
    useEffect(() => {
        const paragraphs = [
            ...(story.current?.querySelectorAll<HTMLElement>(".sp") ?? []),
        ];
        const io = new IntersectionObserver(
            (entries) =>
                entries.forEach((e) => {
                    const next = e.target.nextElementSibling;
                    if (next?.classList.contains("note"))
                        next.classList.toggle("on", e.isIntersecting);
                }),
            { rootMargin: "-42% 0px -42% 0px" },
        );
        paragraphs.forEach((p) => io.observe(p));

        return () => io.disconnect();
    }, []);

    return (
        <SiteLayout title="About me" section="about">
            <div className="abt">
                <section className="ahello wrap">
                    <div className="portrait" ref={photo}>
                        <svg
                            className="oring"
                            viewBox="0 0 100 100"
                            aria-hidden="true"
                        >
                            <circle cx="50" cy="50" r="46.5" pathLength="100" />
                        </svg>
                        <div className="photo">
                            {portrait && (
                                <img src={portrait.src} alt={portrait.alt} />
                            )}
                        </div>
                        <span className="hi" aria-hidden="true">
                            hi!
                        </span>
                    </div>
                    <div className="ahtext">
                        <Hello heading={hello.heading} />
                        {hello.paragraphs.map((p, i) => (
                            <p
                                key={i}
                                data-reveal=""
                                style={v({ "--i": 6 + i })}
                            >
                                {p}
                            </p>
                        ))}
                    </div>
                </section>
                <section className="margins wrap" ref={story}>
                    <Story
                        sections={sections}
                        books={books}
                        links={site.links}
                    />
                </section>
            </div>
        </SiteLayout>
    );
}

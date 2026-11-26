import { Link } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import Runs, { Run } from "@/Components/site/Runs";
import SiteLayout from "@/Layouts/SiteLayout";

type Note = {
    id: number;
    day: string;
    paragraphs: Run[][];
    picture: string | null;
    work: { title: string; href: string } | null;
    likes: number;
};
type Month = { key: string; name: string; year: number; notes: Note[] };
type Batch = { months: Month[]; olderAfter: number | null };

const heart = (
    <svg
        width="17"
        height="17"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
    >
        <path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7z" />
    </svg>
);

const signIn = () =>
    window.dispatchEvent(
        new CustomEvent("eyi:signin", {
            detail: { why: "Sign in to like this note" },
        }),
    );

/** Likes: the margin heart on wide screens, the one under the note on phones. */
function Like({ count, phone }: { count: number; phone?: boolean }) {
    return (
        <button
            className={`lk${phone ? " ph" : ""}`}
            type="button"
            aria-pressed="false"
            aria-label="Like this note"
            onClick={signIn}
        >
            {heart}
            <span>{count}</span>
        </button>
    );
}

function NoteRow({ note }: { note: Note }) {
    return (
        <article className="dn" id={`n-${note.id}`}>
            <div className="dc">
                <div className="nb">
                    {note.paragraphs.map((runs, i) => (
                        <p key={i}>
                            <Runs runs={runs} />
                        </p>
                    ))}
                </div>
                {note.picture && (
                    <div className="book mat nph">
                        <img src={note.picture} alt="" />
                    </div>
                )}
                <Like count={note.likes} phone />
            </div>
            <aside className="as">
                <i className="dash" />
                <span className="d">{note.day}</span>
                {note.work && (
                    <span className="tag">
                        About{" "}
                        <Link className="tl" href={note.work.href}>
                            {note.work.title}
                        </Link>
                    </span>
                )}
                <Like count={note.likes} />
            </aside>
        </article>
    );
}

function MonthSection({ month }: { month: Month }) {
    return (
        <section className="n2m">
            <h2>
                {month.name} <small>{month.year}</small>
            </h2>
            {month.notes.map((note) => (
                <NoteRow key={note.id} note={note} />
            ))}
        </section>
    );
}

async function fetchOlder(after: number): Promise<Batch> {
    const res = await fetch(`/notes-older?after=${after}`, {
        headers: { Accept: "application/json" },
    });

    return res.json();
}

export default function Notes({ months, olderAfter }: Batch) {
    const [older, setOlder] = useState<Month[][]>([]);
    const [after, setAfter] = useState(olderAfter);
    const [busy, setBusy] = useState(false);
    const [fading, setFading] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    /* each note settles in as it comes into view */
    useEffect(() => {
        const io = new IntersectionObserver(
            (entries) =>
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        e.target.classList.add("in");
                        io.unobserve(e.target);
                    }
                }),
            { rootMargin: "0px 0px -6% 0px" },
        );
        root.current
            ?.querySelectorAll(".dn:not(.in)")
            .forEach((el) => io.observe(el));

        return () => io.disconnect();
    }, [older]);

    /* reading along: the date beside the note in the middle of the screen turns ink */
    useEffect(() => {
        const along = () => {
            const items = [
                ...(root.current?.querySelectorAll<HTMLElement>(".dn") ?? []),
            ];
            const mid = innerHeight * 0.45;
            let best: HTMLElement | null = null;
            let bestDistance = 1e9;
            items.forEach((item) => {
                const r = item.getBoundingClientRect();
                if (r.bottom < 0 || r.top > innerHeight) return;
                const d =
                    r.top <= mid && r.bottom >= mid
                        ? 0
                        : Math.min(
                              Math.abs(r.top - mid),
                              Math.abs(r.bottom - mid),
                          );
                if (d < bestDistance) {
                    bestDistance = d;
                    best = item;
                }
            });
            items.forEach((item) =>
                item.classList.toggle("cur", item === best),
            );
        };
        addEventListener("scroll", along, { passive: true });
        addEventListener("resize", along);
        along();

        return () => {
            removeEventListener("scroll", along);
            removeEventListener("resize", along);
        };
    }, [older]);

    const loadOlder = async (): Promise<number | null> => {
        if (after === null || busy) return null;
        setBusy(true);
        const batch = await fetchOlder(after);
        setOlder((list) => [...list, batch.months]);
        setAfter(batch.olderAfter);
        setBusy(false);

        return batch.olderAfter;
    };

    /* arriving from a note link: open older months until the note is there, then mark it */
    useEffect(() => {
        const id = location.hash.match(/^#n-(\d+)$/)?.[1];
        if (!id) return;
        let cancelled = false;
        const find = async () => {
            let next = olderAfter;
            for (
                let tries = 0;
                !document.getElementById(`n-${id}`) &&
                next !== null &&
                tries < 20;
                tries++
            ) {
                const batch = await fetchOlder(next);
                if (cancelled) return;
                setOlder((list) => [...list, batch.months]);
                setAfter(batch.olderAfter);
                next = batch.olderAfter;
                await new Promise((r) => requestAnimationFrame(r));
            }
            const target = document.getElementById(`n-${id}`);
            if (!target || cancelled) return;
            target.classList.add("hit");
            setTimeout(() => {
                target.scrollIntoView({ block: "start", behavior: "instant" });
                document
                    .querySelectorAll(".n2 .dn")
                    .forEach((item) =>
                        item.classList.toggle("cur", item === target),
                    );
            }, 60);
        };
        find();

        return () => {
            cancelled = true;
        };
    }, [olderAfter]);

    const onOlder = async () => {
        const next = await loadOlder();
        if (next === null) setFading(true);
    };

    return (
        <SiteLayout title="Notes" section="notes">
            <div className="wrap n2" ref={root}>
                <div className="ntop">
                    <h1>Notes</h1>
                </div>
                {months.map((month) => (
                    <MonthSection key={month.key} month={month} />
                ))}
                {older.map((batch, i) => (
                    <div className="older" key={i}>
                        {batch.map((month) => (
                            <MonthSection key={month.key} month={month} />
                        ))}
                    </div>
                ))}
                {(after !== null || fading) && (
                    <div
                        className={`n2more${fading ? " gone" : ""}`}
                        onTransitionEnd={() => setFading(false)}
                    >
                        <button
                            className="link"
                            type="button"
                            onClick={onOlder}
                            disabled={busy}
                        >
                            Older notes
                        </button>
                    </div>
                )}
            </div>
        </SiteLayout>
    );
}

import { Link } from "@inertiajs/react";
import { CSSProperties, Fragment, useEffect, useState } from "react";
import SiteLayout from "@/Layouts/SiteLayout";
import { prefersReducedMotion } from "@/lib/motion";
import { askSignIn } from "@/lib/signin";

type Store = { store: string; price: string | null; url: string | null };
type Format = { format: string; from: string | null; stores: Store[] };
type BookBase = {
    id: number | null;
    title: string;
    kind: string;
    href: string;
    color: string;
    image: string | null;
};
type Book = BookBase & { formats: Format[] };
type Coming = BookBase & {
    when: string;
    preorder: { price: string; url: string } | null;
};
type Reading = {
    id: string;
    day: number;
    month: string;
    title: string;
    online: boolean;
    where: string;
    cancelled: boolean;
    link: { label: string; url: string } | null;
};
type Props = {
    columns: string[];
    books: Book[];
    coming: Coming[];
    upcoming: Reading[];
    past: Reading[];
};

const ext = { target: "_blank", rel: "noopener" } as const;
const slug = (t: string) => t.toLowerCase().replace(/[^a-z0-9]+/g, "-");

const I = {
    chevron: (
        <svg
            className="fp-ch"
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m6 9 6 6 6-6" />
        </svg>
    ),
    signed: (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M3 21c3-1 5-4 6-7" />
            <path d="M12 20h9" />
            <path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L9 17l-4 1 1-4z" />
        </svg>
    ),
    clock: (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="10" />
            <path d="M12 7v5l3 2" />
        </svg>
    ),
    bell: (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
    ),
    check: (
        <svg
            width="15"
            height="15"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
    ),
    pin: (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z" />
            <circle cx="12" cy="9.5" r="2.5" />
        </svg>
    ),
    globe: (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17M12 3.5c2.4 2.5 3.4 5.3 3.4 8.5s-1 6-3.4 8.5c-2.4-2.5-3.4-5.3-3.4-8.5s1-6 3.4-8.5z" />
        </svg>
    ),
};

function Cover({ book }: { book: BookBase }) {
    return (
        <Link href={book.href} tabIndex={-1} aria-hidden="true">
            {book.image ? (
                <span className="fp-cv" style={{ width: 44, height: 66 }}>
                    <img src={book.image} alt="" />
                </span>
            ) : (
                <span
                    className="fp-cv fp-fr"
                    style={{
                        width: 44,
                        height: 66,
                        background: book.color,
                        padding: 4,
                    }}
                >
                    <span />
                    <b style={{ fontSize: 7 }}>{book.title}</b>
                    <span />
                </span>
            )}
        </Link>
    );
}

function Title({ book }: { book: BookBase }) {
    return (
        <div className="fpc-tt">
            <Link className="tl fpc-t" href={book.href}>
                {book.title}
            </Link>
            <div className="fpc-k">{book.kind}</div>
        </div>
    );
}

/** One book: a cell per format on wide screens (several stores fold out under the row); a list of formats on phones. */
function BookRow({
    book,
    columns,
    wide,
}: {
    book: Book;
    columns: string[];
    wide: boolean;
}) {
    const [open, setOpen] = useState<string | null>(null);
    const [phoneOpen, setPhoneOpen] = useState<string[]>([]);
    const formats = Object.fromEntries(book.formats.map((f) => [f.format, f]));
    const id = (format: string) => `st-${slug(book.title)}-${slug(format)}`;

    return (
        <>
            <div className={`fpc-row${wide ? " wide" : ""}`}>
                <Cover book={book} />
                <Title book={book} />
                {columns.map((column) => {
                    const format = formats[column];
                    if (!format) {
                        return (
                            <div key={column} className="fpc-c">
                                <span
                                    className="fpc-no"
                                    aria-label={`Not in ${column.toLowerCase()}`}
                                >
                                    –
                                </span>
                            </div>
                        );
                    }
                    if (format.stores.length === 1) {
                        const s = format.stores[0];
                        return (
                            <div key={column} className="fpc-c">
                                <a
                                    className="fpc-cell fpc-one"
                                    href={s.url ?? "#"}
                                    {...ext}
                                >
                                    <span>{s.price} ↗</span>
                                    <small>{s.store}</small>
                                </a>
                            </div>
                        );
                    }
                    const on = open === column;
                    return (
                        <div key={column} className="fpc-c">
                            <button
                                className={`fpc-cell fpc-btn${on ? " open" : ""}`}
                                type="button"
                                aria-expanded={on}
                                aria-controls={id(column)}
                                onClick={() => setOpen(on ? null : column)}
                            >
                                from {format.from}
                                {I.chevron}
                            </button>
                        </div>
                    );
                })}
            </div>
            {columns
                .filter((column) => (formats[column]?.stores.length ?? 0) > 1)
                .map((column) => (
                    <div
                        key={column}
                        className={`fp-pst fpc-x${open === column ? " open" : ""}`}
                        id={id(column)}
                    >
                        <div>
                            <div className="fpc-open">
                                <div className="fpc-ol">{column}</div>
                                {formats[column].stores.map((s) => (
                                    <a
                                        key={s.store}
                                        className="fpc-store"
                                        href={s.url ?? "#"}
                                        {...ext}
                                    >
                                        <span>{s.store}</span>
                                        <small>{s.price} ↗</small>
                                    </a>
                                ))}
                            </div>
                        </div>
                    </div>
                ))}
            <div className="fpc-ph">
                <div className="fp-plist">
                    {book.formats.map((format) => {
                        if (format.stores.length === 1) {
                            const s = format.stores[0];
                            return (
                                <a
                                    key={format.format}
                                    className="fp-prow"
                                    href={s.url ?? "#"}
                                    {...ext}
                                >
                                    <span>
                                        {format.format === "Signed" && I.signed}
                                        {format.format}
                                    </span>
                                    <small>
                                        {s.store} · {s.price} ↗
                                    </small>
                                </a>
                            );
                        }
                        const on = phoneOpen.includes(format.format);
                        const toggle = () =>
                            setPhoneOpen((list) =>
                                on
                                    ? list.filter((f) => f !== format.format)
                                    : [...list, format.format],
                            );
                        return (
                            <Fragment key={format.format}>
                                <button
                                    className={`fp-prow fp-fmt${on ? " open" : ""}`}
                                    type="button"
                                    aria-expanded={on}
                                    onClick={toggle}
                                >
                                    <span>{format.format}</span>
                                    <small>
                                        from {format.from}
                                        {I.chevron}
                                    </small>
                                </button>
                                <div className={`fp-pst${on ? " open" : ""}`}>
                                    <div>
                                        {format.stores.map((s) => (
                                            <a
                                                key={s.store}
                                                className="fp-srow"
                                                href={s.url ?? "#"}
                                                {...ext}
                                            >
                                                <span>{s.store}</span>
                                                <small>{s.price} ↗</small>
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            </Fragment>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

function ComingRow({ book, wide }: { book: Coming; wide: boolean }) {
    return (
        <div className={`fpc-row fpc-soon${wide ? " wide" : ""}`}>
            <Cover book={book} />
            <Title book={book} />
            <div className="fpc-span">
                <div className="fp-st">
                    {I.clock}
                    <span>{book.when}</span>
                </div>
                {book.preorder ? (
                    <a className="fpc-cell" href={book.preorder.url} {...ext}>
                        Pre-order · {book.preorder.price} ↗
                    </a>
                ) : (
                    <button
                        className="fp-follow"
                        type="button"
                        aria-pressed="false"
                        onClick={() =>
                            askSignIn({
                                follow: book.id,
                                why: `Sign in to follow ${book.title}`,
                            })
                        }
                    >
                        <span className="a">{I.bell}Follow</span>
                        <span className="b">{I.check}Following</span>
                    </button>
                )}
            </div>
        </div>
    );
}

function ReadingRow({ reading, past }: { reading: Reading; past?: boolean }) {
    return (
        <div
            className={`fp-ev${past ? " past" : ""}${reading.cancelled ? " cancel" : ""}`}
        >
            <div className="fp-ed">
                <b>{reading.day}</b>
                <span>{reading.month}</span>
            </div>
            <div className="fp-em">
                <div className="fp-et">
                    {reading.title}
                    {reading.cancelled && (
                        <>
                            {" "}
                            <small className="fp-cx">Cancelled</small>
                        </>
                    )}
                </div>
                <div className="fp-ew">
                    {reading.online ? I.globe : I.pin}
                    <span>{reading.where}</span>
                </div>
            </div>
            {reading.link && (
                <a className="fp-elink" href={reading.link.url} {...ext}>
                    {reading.link.label} ↗
                </a>
            )}
        </div>
    );
}

export default function InPrint({
    columns,
    books,
    coming,
    upcoming,
    past,
}: Props) {
    const [tab, setTab] = useState<"books" | "readings">("books");
    const [shown, setShown] = useState<"books" | "readings">("books");
    const [fading, setFading] = useState(false);
    const [pastOpen, setPastOpen] = useState(false);
    const wide = columns.length > 4;
    const cols = { "--cols": columns.length } as CSSProperties;

    /* #readings opens that tab */
    useEffect(() => {
        if (location.hash === "#readings") {
            setTab("readings");
            setShown("readings");
        }
    }, []);

    /* tabs crossfade: the old pane fades, the new one settles in */
    const pick = (next: "books" | "readings") => {
        setTab(next);
        history.replaceState(
            history.state,
            "",
            next === "readings" ? "#readings" : location.pathname,
        );
        if (next === shown) return;
        setFading(true);
        window.setTimeout(
            () => {
                setShown(next);
                requestAnimationFrame(() =>
                    requestAnimationFrame(() => setFading(false)),
                );
            },
            prefersReducedMotion() ? 120 : 200,
        );
    };

    return (
        <SiteLayout title="In print" footer="in-print">
            <div className="wrap fp-w fpc" style={cols}>
                <div className="fp-top">
                    <h1>In print</h1>
                </div>
                <div className="fpc-tabs" role="tablist">
                    {(["books", "readings"] as const).map((t) => (
                        <button
                            key={t}
                            className={`fpc-tab${tab === t ? " on" : ""}`}
                            role="tab"
                            type="button"
                            aria-selected={tab === t}
                            aria-controls={`pane-${t}`}
                            id={`tab-${t}`}
                            onClick={() => pick(t)}
                        >
                            {t === "books" ? "Books" : "Readings"}
                        </button>
                    ))}
                </div>
                <div
                    className={`fpc-pane${fading && shown === "books" ? " out" : ""}`}
                    id="pane-books"
                    role="tabpanel"
                    aria-labelledby="tab-books"
                    hidden={shown !== "books"}
                >
                    <div className={`fpc-row fpc-head${wide ? " wide" : ""}`}>
                        <span />
                        {columns.map((c) => (
                            <span key={c}>{c}</span>
                        ))}
                    </div>
                    {books.map((book) => (
                        <BookRow
                            key={book.title}
                            book={book}
                            columns={columns}
                            wide={wide}
                        />
                    ))}
                    {coming.length > 0 && (
                        <>
                            <div className="fpc-sub">Coming</div>
                            {coming.map((book) => (
                                <ComingRow
                                    key={book.title}
                                    book={book}
                                    wide={wide}
                                />
                            ))}
                        </>
                    )}
                </div>
                <div
                    className={`fpc-pane${fading && shown === "readings" ? " out" : ""}`}
                    id="pane-readings"
                    role="tabpanel"
                    aria-labelledby="tab-readings"
                    hidden={shown !== "readings"}
                >
                    <div className="fpc-read">
                        {upcoming.length ? (
                            <div className="fp-evs">
                                {upcoming.map((r) => (
                                    <ReadingRow key={r.id} reading={r} />
                                ))}
                            </div>
                        ) : (
                            <p className="fp-none">No readings coming up.</p>
                        )}
                        {past.length > 0 && (
                            <>
                                <button
                                    className={`fp-fold${pastOpen ? " open" : ""}`}
                                    type="button"
                                    aria-expanded={pastOpen}
                                    onClick={() => setPastOpen(!pastOpen)}
                                >
                                    Past readings
                                    {I.chevron}
                                </button>
                                <div
                                    className={`fp-pst${pastOpen ? " open" : ""}`}
                                >
                                    <div className="fp-evs">
                                        {past.map((r) => (
                                            <ReadingRow
                                                key={r.id}
                                                reading={r}
                                                past
                                            />
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </SiteLayout>
    );
}

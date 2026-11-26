import { Link, useForm, usePage } from "@inertiajs/react";
import { FormEvent, useState, useSyncExternalStore } from "react";
import {
    currentTheme,
    setThemeFrom,
    subscribeTheme,
    THEMES,
} from "@/lib/motion";
import { paths } from "@/lib/paths";
import { FooterPage } from "@/types/site";
import { SocialIcon } from "./Icons";
import SendButton, { SendState } from "./SendButton";
import Wordmark from "./Wordmark";

export const KINDS: { key: string; label: string }[] = [
    { key: "novels", label: "Novels" },
    { key: "stories", label: "Short stories" },
    { key: "poems", label: "Poetry" },
    { key: "essays", label: "Essays" },
    { key: "notes", label: "Notes" },
];

const FOOTER_LINKS: { key: FooterPage; href: string; label: string }[] = [
    { key: "about", href: paths.about, label: "About Me" },
    { key: "notes", href: paths.notes, label: "Notes" },
    { key: "in-print", href: paths.inPrint, label: "In print" },
    { key: "rss", href: paths.rss, label: "RSS" },
    { key: "privacy", href: paths.privacy, label: "Privacy" },
    { key: "terms", href: paths.terms, label: "Terms" },
];

/** New work, by email: the five kinds (all on) and the email field with Subscribe inside it. */
function Newsletter() {
    const form = useForm<{ email: string; kinds: string[] }>({
        email: "",
        kinds: KINDS.map((x) => x.key),
    });
    const [state, setState] = useState<SendState>("idle");
    const [sentTo, setSentTo] = useState("");
    const [done, setDone] = useState(false);
    const [note, setNote] = useState<string | null>(null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (state === "sending") {
            return;
        }
        setNote(null);
        setState("sending");
        const started = Date.now();
        const after = (fn: () => void) =>
            window.setTimeout(fn, Math.max(0, 900 - (Date.now() - started)));
        form.post(route("newsletter.subscribe", undefined, false), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                after(() => {
                    setState("sent");
                    setSentTo(form.data.email);
                    window.setTimeout(() => setDone(true), 900);
                }),
            onError: (errors) =>
                after(() => {
                    setState("failed");
                    setNote(
                        errors.email ??
                            errors.kinds ??
                            "Couldn’t send. Your words are kept.",
                    );
                }),
        });
    };

    const toggleKind = (key: string) =>
        form.setData(
            "kinds",
            form.data.kinds.includes(key)
                ? form.data.kinds.filter((k) => k !== key)
                : [...form.data.kinds, key],
        );

    return (
        <div className={`swap${done ? " sent" : ""}`} id="nl">
            <form
                className="form"
                id="nlForm"
                onSubmit={submit}
                {...(done ? { inert: "" } : {})}
            >
                <div className="checks">
                    {KINDS.map((kind) => (
                        <label className="cb" key={kind.key}>
                            <input
                                type="checkbox"
                                checked={form.data.kinds.includes(kind.key)}
                                onChange={() => toggleKind(kind.key)}
                            />
                            {kind.label}
                        </label>
                    ))}
                </div>
                <div className="joined">
                    <input
                        type="email"
                        aria-label="Your email"
                        placeholder="Your email"
                        required
                        value={form.data.email}
                        onChange={(e) => {
                            form.setData("email", e.target.value);
                            if (state === "failed") {
                                setState("idle");
                            }
                        }}
                    />
                    <SendButton
                        type="submit"
                        state={state}
                        sentLabel="Subscribed"
                        retryLabel="Try again"
                        disabled={form.data.kinds.length === 0}
                    >
                        Subscribe
                    </SendButton>
                </div>
                {note && (
                    <span className="sendnote" role="alert">
                        {note}
                    </span>
                )}
            </form>
            <div className="done" aria-live="polite">
                <span
                    style={{
                        width: 36,
                        height: 36,
                        borderRadius: "50%",
                        background: "var(--accent)",
                        display: "grid",
                        placeItems: "center",
                        color: "#fff",
                    }}
                    aria-hidden="true"
                >
                    ✓
                </span>
                <div>
                    <div
                        style={{
                            fontFamily: "var(--A)",
                            fontWeight: 700,
                            fontSize: 16,
                            color: "var(--ink)",
                        }}
                    >
                        Check your inbox
                    </div>
                    <div
                        style={{
                            fontFamily: "var(--A)",
                            fontSize: 14,
                            marginTop: 4,
                        }}
                        id="sentTo"
                    >
                        {sentTo
                            ? `Confirm using the email sent to ${sentTo}.`
                            : "Confirm using the email we sent."}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** Footer A: write to me (+ links elsewhere), the newsletter, then the colophon with the theme dots. */
export default function Footer({
    current,
    newsletter = true,
}: {
    current?: FooterPage;
    newsletter?: boolean;
}) {
    const { site } = usePage().props;
    const theme = useSyncExternalStore(
        subscribeTheme,
        currentTheme,
        () => "white" as const,
    );

    return (
        <footer className={newsletter ? undefined : "no-nl"}>
            <div className="wrap">
                <div className="fgrid">
                    <div data-reveal="">
                        <h4>Write to me</h4>
                        <div style={{ marginTop: 14 }}>
                            <a className="link" href={`mailto:${site.email}`}>
                                {site.email}
                            </a>
                        </div>
                        {site.links.length > 0 && (
                            <div className="fsoc">
                                {site.links.map((l) => (
                                    <a
                                        key={l.url}
                                        className="soc"
                                        href={l.url}
                                        target="_blank"
                                        rel="noopener me"
                                        aria-label={l.network}
                                        data-tip={l.network}
                                    >
                                        <SocialIcon network={l.network} />
                                    </a>
                                ))}
                            </div>
                        )}
                    </div>
                    <div data-reveal="" style={{ ["--i" as string]: 1 }}>
                        <h4>New work, by email</h4>
                        <Newsletter />
                    </div>
                </div>
                <div className="colo">
                    <div className="l">
                        <Wordmark style={{ fontSize: 18 }} />
                        <span className="muted">
                            {site.line} © {new Date().getFullYear()}
                        </span>
                    </div>
                    <div className="r">
                        {FOOTER_LINKS.map((l) => (
                            <Link
                                key={l.key}
                                className="flink"
                                href={l.href}
                                aria-current={
                                    current === l.key ? "page" : undefined
                                }
                            >
                                {l.label}
                            </Link>
                        ))}
                        <div className="tdots">
                            {THEMES.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    style={{ background: t.bg }}
                                    aria-label={t.name}
                                    aria-pressed={theme === t.key}
                                    onClick={(e) =>
                                        setThemeFrom(t.key, e.currentTarget)
                                    }
                                />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    );
}

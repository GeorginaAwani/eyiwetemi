import { MouseEvent, useEffect, useState } from "react";
import SiteLayout from "@/Layouts/SiteLayout";
import { prefersReducedMotion } from "@/lib/motion";
import { FooterPage } from "@/types/site";

type Section = { id: string; heading: string; html: string };

export type ContentsPageProps = {
    updated: string | null;
    sections: Section[];
    change?: { line: string; ids: string[] } | null;
};

/**
 * Privacy and Terms: contents on the left follow the reading; on phones they fold under "On this page".
 */
export default function ContentsPage({
    title,
    footer,
    updated,
    sections,
    change,
}: ContentsPageProps & { title: string; footer: FooterPage }) {
    const [current, setCurrent] = useState(sections[0]?.id);
    const [open, setOpen] = useState(false);
    const changed = new Set(change?.ids ?? []);

    useEffect(() => {
        const spy = () => {
            const y = innerHeight * 0.3;
            let cur = sections[0]?.id;
            sections.forEach((s) => {
                const el = document.getElementById(s.id);
                if (el && el.getBoundingClientRect().top <= y) cur = s.id;
            });
            if (
                innerHeight + scrollY >=
                document.documentElement.scrollHeight - 4
            )
                cur = sections[sections.length - 1]?.id;
            setCurrent(cur);
        };
        addEventListener("scroll", spy, { passive: true });
        spy();

        return () => removeEventListener("scroll", spy);
    }, [sections]);

    const go = (e: MouseEvent<HTMLAnchorElement>, id: string) => {
        e.preventDefault();
        document
            .getElementById(id)
            ?.scrollIntoView({
                behavior: prefersReducedMotion() ? "instant" : "smooth",
                block: "start",
            });
        history.replaceState(history.state, "", `#${id}`);
        setOpen(false);
    };

    const links = (phone: boolean) =>
        sections.map((s) => (
            <a
                key={s.id}
                className={`fpvb-l${!phone && s.id === current ? " on" : ""}`}
                href={`#${s.id}`}
                onClick={(e) => go(e, s.id)}
            >
                {s.heading}
                {changed.has(s.id) && <i className="fpv-dot" aria-label="Changed"></i>}
            </a>
        ));

    return (
        <SiteLayout title={title} footer={footer}>
            <div className="wrap fpv fpvb">
                <aside className="fpvb-side">
                    <h1>{title}</h1>
                    {updated && <div className="fpv-up">Updated {updated}</div>}
                    {change && (
                        <p className="fpv-chg">
                            <i aria-hidden="true"></i>
                            <span>{change.line}</span>
                        </p>
                    )}
                    <nav className="fpvb-toc" aria-label="On this page">
                        {links(false)}
                    </nav>
                    <button
                        className={`fp-fold fpvb-ph${open ? " open" : ""}`}
                        type="button"
                        aria-expanded={open}
                        aria-controls="phToc"
                        onClick={() => setOpen(!open)}
                    >
                        On this page
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
                    </button>
                    <div
                        className={`fp-pst fpvb-pht${open ? " open" : ""}`}
                        id="phToc"
                    >
                        <div>
                            <nav className="fpvb-toc2">{links(true)}</nav>
                        </div>
                    </div>
                </aside>
                <div className="fpv-body">
                    {sections.map((s) => (
                        <section key={s.id} className="fpv-s" id={s.id}>
                            <h2>{s.heading}</h2>
                            {/* Her own text, cleaned on the server to paragraphs, emphasis, lists and links. */}
                            <div dangerouslySetInnerHTML={{ __html: s.html }} />
                        </section>
                    ))}
                </div>
            </div>
        </SiteLayout>
    );
}

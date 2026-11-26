import { useEffect, useRef, useState } from "react";
import SiteLayout from "@/Layouts/SiteLayout";
import { prefersReducedMotion } from "@/lib/motion";

type Feed = { label: string; url: string };

/** RSS: pick a feed, copy its address. */
export default function Rss({ feeds }: { feeds: Feed[] }) {
    const [picked, setPicked] = useState(feeds[0]);
    const [shown, setShown] = useState(feeds[0].url);
    const [swapping, setSwapping] = useState(false);
    const [copied, setCopied] = useState(false);
    const [said, setSaid] = useState("");
    const timer = useRef(0);

    useEffect(() => () => clearTimeout(timer.current), []);

    const pick = (feed: Feed) => {
        setPicked(feed);
        setCopied(false);
        clearTimeout(timer.current);
        setSwapping(true);
        timer.current = window.setTimeout(
            () => {
                setShown(feed.url);
                setSwapping(false);
            },
            prefersReducedMotion() ? 0 : 150,
        );
    };

    const copy = () => {
        const url = `${location.protocol}//${picked.url}`;
        navigator.clipboard?.writeText(url).catch(() => {});
        setCopied(true);
        setSaid(`Copied ${url}`);
        clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setCopied(false), 1800);
    };

    return (
        <SiteLayout title="RSS" footer="rss">
            <div className="wrap fp-w fr frb">
                <div className="fp-top">
                    <h1>RSS</h1>
                </div>
                <p className="fr-line">
                    Copy a feed into any feed reader to get new work as it comes
                    out.
                </p>
                <div className="frb-cs" role="radiogroup" aria-label="Feed">
                    {feeds.map((feed) => (
                        <label
                            key={feed.url}
                            className={`frb-c${feed.url === picked.url ? " on" : ""}`}
                        >
                            <input
                                type="radio"
                                name="feed"
                                value={feed.url}
                                checked={feed.url === picked.url}
                                onChange={() => pick(feed)}
                            />
                            {feed.label}
                        </label>
                    ))}
                </div>
                <div className={`frb-f${copied ? " is-cp" : ""}`}>
                    <span className="fra-i">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M4 11a9 9 0 0 1 9 9" />
                            <path d="M4 4a16 16 0 0 1 16 16" />
                            <circle
                                cx="5"
                                cy="19"
                                r="1.2"
                                fill="currentColor"
                            />
                        </svg>
                    </span>
                    <span className={`frb-u${swapping ? " swap" : ""}`}>
                        {shown}
                    </span>
                    <button className="frb-b" type="button" onClick={copy}>
                        <span className="a">Copy</span>
                        <span className="b">
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
                            Copied
                        </span>
                    </button>
                </div>
                <span className="fp-sr" aria-live="polite">
                    {said}
                </span>
            </div>
        </SiteLayout>
    );
}

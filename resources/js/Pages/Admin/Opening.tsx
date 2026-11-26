import { Head, Link } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Brand, ThemeMenu, ViewSite } from '../../Components/admin/AdminTop';
import { Alert, BackSmall, Close, Saved as Tick } from '../../Components/admin/Icons';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Props = { work: { slug: string; title: string; href: string }; quote: { q: string; by: string } };

/**
 * A novel's opening quote: its own page before chapter one, the quote and who said it, saving as she types.
 */
export default function Opening({ work, quote }: Props) {
    const q = useRef<HTMLSpanElement>(null);
    const by = useRef<HTMLSpanElement>(null);
    const page = useRef<HTMLDivElement>(null);
    const timer = useRef(0);
    const [save, setSave] = useState<'saved' | 'saving' | 'bad'>('saved');

    useLayoutEffect(() => {
        if (q.current && by.current) {
            q.current.textContent = quote.q;
            by.current.textContent = quote.by;
        }
        if (!quote.q) {
            window.setTimeout(() => q.current?.focus(), prefersReducedMotion() ? 0 : 400);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        document.body.classList.add('wa', 'wpage');
        page.current?.classList.add('settle');

        return () => document.body.classList.remove('wa', 'wpage');
    }, []);

    const store = (delay = 600) => {
        setSave('saving');
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(async () => {
            const { status } = await json('PATCH', `/works/${work.slug}/opening`, { q: q.current?.innerText.trim() ?? '', by: by.current?.innerText.trim() ?? '' });
            setSave(status === 200 ? 'saved' : 'bad');
        }, delay);
    };

    return (
        <>
            <Head title={`Opening quote · ${work.title}`} />
            <main className="ad-page" id="main">
                <header className="wa-top" id="wTop">
                    <Brand />
                    <Link className="wa-crumb" id="crumb" href={work.href}>
                        <BackSmall />
                        <span>{work.title}</span>
                    </Link>
                    <span className="wa-sp" />
                    <span className="k-saved" id="sv" aria-live="polite" data-s={save}>
                        <span className="i">{save === 'saving' ? <span className="w-rg" /> : save === 'bad' ? <Alert /> : <Tick />}</span>
                        <span className="t">{save === 'saving' ? 'Saving…' : save === 'bad' ? 'Not saved' : 'Saved'}</span>
                    </span>
                    <ViewSite />
                    <ThemeMenu />
                </header>
                <div className="wa-body">
                    <div className="w-page k-open" id="pg" ref={page}>
                        <div className="w-opn">
                            <span className="w-hn">Opening quote</span>
                            <div className="epi ed big" id="qE">
                                <span ref={q} className="q w-ce" id="qQ" contentEditable="plaintext-only" suppressContentEditableWarning data-ph="Quote" spellCheck onInput={() => store()} />
                                <span
                                    ref={by}
                                    className="by w-ce"
                                    id="qBy"
                                    contentEditable="plaintext-only"
                                    suppressContentEditableWarning
                                    data-ph="Who said it"
                                    spellCheck
                                    onInput={() => store()}
                                    onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
                                />
                                <button
                                    className="x ad-ic"
                                    type="button"
                                    id="qX"
                                    aria-label="Remove quote"
                                    onClick={() => {
                                        if (q.current && by.current) {
                                            q.current.textContent = '';
                                            by.current.textContent = '';
                                        }
                                        store(0);
                                        q.current?.focus();
                                    }}
                                >
                                    <Close size={16} />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <nav className="wa-bar" id="bar" aria-label="Writing">
                <span className="f" id="wc">
                    Before chapter one
                </span>
            </nav>
        </>
    );
}

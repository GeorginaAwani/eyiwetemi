import { Link } from '@inertiajs/react';
import { CSSProperties, Fragment, useEffect, useRef, useState } from 'react';
import BookPicture from '@/Components/site/BookPicture';
import StatusIcon from '@/Components/site/StatusIcon';
import SiteLayout from '@/Layouts/SiteLayout';
import { prefersReducedMotion } from '@/lib/motion';
import { paths } from '@/lib/paths';
import { Action, ChapterRow, WorkCard } from '@/types/works';

type Spine = { title: string; href: string; color: string; dark: boolean; sub: string | null; meta: string; height: number; width: number; left: number };
type NoteRef = { label: string; text: string; href: string };
type Hero = WorkCard & { label: string; actions: Action[] };
type More = WorkCard & { firstLine: string | null };
type NovelLead = WorkCard & {
    statusLine: string;
    count: number;
    chapters: ChapterRow[];
    startHref: string;
    newest: { label: string; href: string };
    note: NoteRef | null;
};
type PieceLead = WorkCard & { excerpt: string | null; stanza: { text: string; indent: number }[]; startHref: string };
type Section<L> = { lead: L; more: More[]; total: number } | null;

type Props = {
    hero: Hero | null;
    pile: Spine[];
    about: { quote: string | null; line: string | null };
    noteLine: NoteRef | null;
    novels: Section<NovelLead>;
    stories: Section<PieceLead>;
    poems: Section<PieceLead>;
    essays: Section<PieceLead>;
};

const v = (vars: Record<string, string | number>) => vars as CSSProperties;

/** Home's own line under the header: the newest note. Closing it fades it away. */
function NoteLine({ note }: { note: NoteRef }) {
    const [gone, setGone] = useState(false);

    return (
        <div className={`wrap noteline${gone ? ' gone' : ''}`} id="noteline">
            <span className="k">{note.label}</span>
            <Link href={note.href}>{note.text}</Link>
            <button className="x" type="button" aria-label="Hide this note" onClick={() => setGone(true)}>
                ✕
            </button>
        </div>
    );
}

/** A primary, outline or link action, as the prototype draws them. */
function ActionLink({ action }: { action: Action }) {
    if (action.style === 'link') {
        return (
            <Link className="link" href={action.href}>
                {action.label}
            </Link>
        );
    }

    return (
        <Link className={`btn ${action.style}`} href={action.href}>
            {action.label}
        </Link>
    );
}

/** Spines: type size follows thickness; a long title widens its spine, drops its kind, steps down, and only then is cut. */
function usePile() {
    const ref = useRef<HTMLDivElement>(null);
    const [ready, setReady] = useState(false);
    const [live, setLive] = useState(false);

    useEffect(() => {
        const pile = ref.current;
        if (!pile) {
            return;
        }

        const fit = () => {
            const W = pile.clientWidth;
            pile.querySelectorAll<HTMLElement>('.spine').forEach((sp) => {
                const b = sp.querySelector('b');
                const m = sp.querySelector<HTMLElement>(':scope>span:not(.peek)');
                if (!b || !m) {
                    return;
                }
                if (!sp.dataset.w) {
                    sp.dataset.w = sp.style.width;
                    sp.dataset.ml = sp.style.marginLeft;
                    m.dataset.full = m.textContent ?? '';
                }
                sp.style.width = sp.dataset.w;
                sp.style.marginLeft = sp.dataset.ml ?? '';
                m.textContent = m.dataset.full ?? '';
                sp.removeAttribute('title');
                const base = Math.max(15, Math.min(34, Math.round(sp.offsetHeight * 0.44)));
                sp.style.setProperty('--fs', `${base}px`);
                const over = () => b.scrollWidth - b.clientWidth;
                if (over() > 0) {
                    const w = Math.min(sp.offsetWidth + over() + 2, W);
                    sp.style.width = `${w}px`;
                    sp.style.marginLeft = `${Math.max(0, Math.min(parseFloat(getComputedStyle(sp).marginLeft), W - w))}px`;
                }
                m.style.display = '';
                if (over() > 0) {
                    m.textContent = (m.dataset.full ?? '').split(' · ').pop() ?? '';
                }
                if (over() > 0) {
                    m.style.display = 'none';
                }
                let fs = base;
                while (over() > 0 && fs > 15) {
                    fs -= 1;
                    sp.style.setProperty('--fs', `${fs}px`);
                }
                if (over() > 0) {
                    sp.title = b.textContent ?? '';
                }
            });
        };

        document.fonts.ready.then(fit);
        window.addEventListener('resize', fit);
        const t = window.setTimeout(
            () => {
                setReady(true);
                requestAnimationFrame(() => requestAnimationFrame(() => setLive(true)));
            },
            prefersReducedMotion() ? 0 : 1300,
        );

        return () => {
            window.removeEventListener('resize', fit);
            window.clearTimeout(t);
        };
    }, []);

    return { ref, className: `pile${ready ? ' ready' : ''}${live ? ' live' : ''}` };
}

function HeroBlock({ hero, pile }: { hero: Hero; pile: Spine[] }) {
    const p = usePile();
    const words = hero.title.split(' ');

    return (
        <div className="wrap hero">
            <div>
                <div className="cover" data-reveal="">
                    {hero.cover.src ? (
                        <img src={hero.cover.src} alt={`Cover of ${hero.title}`} />
                    ) : (
                        <span className="pin-cv" style={{ background: hero.cover.color }}>
                            <b>{hero.title}</b>
                        </span>
                    )}
                </div>
                <div className="label" data-reveal="" style={{ marginTop: 34, ...v({ '--i': 1 }) }}>
                    {hero.label}
                </div>
                <h1>
                    <Link href={hero.href} className="tl" style={{ color: 'inherit' }}>
                        {words.map((word, i) => (
                            <Fragment key={i}>
                                <span className="word" style={v({ '--w': i })}>
                                    {word}
                                </span>
                                {i < words.length - 1 ? ' ' : null}
                            </Fragment>
                        ))}
                    </Link>
                </h1>
                <div className="acts" data-reveal="" style={{ alignItems: 'center', gap: hero.actions.some((a) => a.style === 'link') ? 24 : 16, ...v({ '--i': 4 }) }}>
                    {hero.actions.map((a) => (
                        <ActionLink key={a.label} action={a} />
                    ))}
                </div>
            </div>
            <div className={p.className} id="pile" ref={p.ref}>
                {pile.map((s, i) => (
                    <Link
                        key={s.href}
                        className={`spine${s.dark ? ' dark' : ''}`}
                        href={s.href}
                        style={{ height: s.height, width: `${s.width}%`, marginLeft: `${s.left}%`, background: s.color, ...v({ '--n': i + 1 }) }}
                    >
                        {s.sub ? (
                            <div>
                                <b>{s.title}</b>
                                <div style={{ fontSize: 13, marginTop: 6 }}>{s.sub}</div>
                            </div>
                        ) : (
                            <b>{s.title}</b>
                        )}
                        <span>{s.meta}</span>
                        <span className="peek" aria-hidden="true">
                            Open →
                        </span>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function St({ icon, children }: { icon?: string | null; children: React.ReactNode }) {
    return (
        <div className="st">
            <StatusIcon name={icon ?? null} />
            <span>{children}</span>
        </div>
    );
}

function NovelCell({ work, i }: { work: More; i: number }) {
    const soon = work.status.icon === 'clock';

    return (
        <div className="cell work" data-reveal="" style={v({ '--i': i })}>
            {soon ? (
                <div
                    className="book turn"
                    aria-hidden="true"
                    style={{ width: 96, height: 144, flexShrink: 0, background: 'var(--tint)', border: '1.5px dashed var(--accent)', boxShadow: 'none', display: 'flex', flexDirection: 'column', justifyContent: 'space-between', padding: 10 }}
                >
                    <span className="caps" style={{ fontSize: 7, color: 'var(--accent)' }}>
                        Coming soon
                    </span>
                    <span style={{ fontFamily: 'var(--CD)', fontSize: 12, color: 'var(--accent)' }}>{work.title}</span>
                    <span />
                </div>
            ) : (
                <BookPicture work={work} width={96} height={144} type={{ k: 7, t: 12, w: 7, pad: '10px' }} firstLine={work.firstLine} style={{ flexShrink: 0 }} />
            )}
            <div>
                <div className="t">
                    <Link className="tl" href={work.href}>
                        {work.title}
                    </Link>
                </div>
                <St icon={work.status.icon}>{work.status.text}</St>
                {work.series && (
                    <St icon="book">
                        Book {work.series.position} · {work.series.name}
                    </St>
                )}
                {work.inPrint && (
                    <Link className="pill" href={paths.inPrint}>
                        In print · from {work.inPrint} ↗
                    </Link>
                )}
                {soon && (
                    <div style={{ marginTop: 8 }}>
                        <button type="button" className="link" onClick={() => window.dispatchEvent(new CustomEvent('eyi:signin', { detail: { follow: work.id } }))}>
                            Tell me when it’s out
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function Novels({ section }: { section: NonNullable<Section<NovelLead>> }) {
    const { lead } = section;

    return (
        <section className="kind wrap" id="novels">
            <h2 data-reveal="">Novels</h2>
            <div className="lead l work">
                <BookPicture work={lead} width={220} height={330} reveal={1} type={{ k: 9, t: 29, w: 11, pad: '22px' }} />
                <div data-reveal="" style={v({ '--i': 2 })}>
                    <div className="label">Updated last · {lead.date}</div>
                    <h3>
                        <Link className="tl" href={lead.href}>
                            {lead.title}
                        </Link>
                    </h3>
                    <div className="meta">
                        <StatusIcon name={lead.status.icon === 'check' ? 'check' : 'pen'} size={17} stroke="var(--accent)" />
                        {lead.statusLine}
                    </div>
                    {lead.series && (
                        <div className="meta" style={{ marginTop: 6 }}>
                            <StatusIcon name="book" size={17} stroke="var(--accent)" />
                            Book {lead.series.position} of{' '}
                            <Link className="link" href={lead.series.href} style={{ fontFamily: 'var(--NR)', fontWeight: 400, fontSize: 18 }}>
                                {lead.series.name}
                            </Link>
                        </div>
                    )}
                    {lead.note && (
                        <div className="wnote">
                            <span className="k">{lead.note.label}</span>
                            <Link href={lead.note.href}>{lead.note.text}</Link>
                        </div>
                    )}
                    <div className="chaps">
                        {lead.chapters.map((c) => (
                            <Link key={c.number} className="chap" href={c.href}>
                                <span>
                                    {c.label}
                                    {c.isNew && <span className="here">new</span>}
                                </span>
                                <small>{c.minutes} min</small>
                            </Link>
                        ))}
                    </div>
                    <div style={{ marginTop: 14 }}>
                        <Link className="link" href={lead.href}>
                            All {lead.count} chapters
                        </Link>
                    </div>
                    <div className="acts" style={{ display: 'flex', alignItems: 'center', gap: 24, marginTop: 28 }}>
                        <Link className="btn solid" href={lead.startHref}>
                            Start from chapter 1
                        </Link>
                        <Link className="link" href={lead.newest.href}>
                            {lead.newest.label}
                        </Link>
                    </div>
                </div>
            </div>
            {section.more.length > 0 && (
                <div className="row4">
                    <div className="caps" style={{ color: 'var(--ink)' }}>
                        More novels
                    </div>
                    <div className="grid4">
                        {section.more.map((w, i) => (
                            <NovelCell key={w.id} work={w} i={i * 2 + 1} />
                        ))}
                    </div>
                    <Link className="link see" href={paths.library('novel')}>
                        See all {section.total} novels →
                    </Link>
                </div>
            )}
        </section>
    );
}

const KIND = {
    story: { id: 'stories', title: 'Short stories', meta: 'A short story', more: 'More stories', all: 'stories' },
    poem: { id: 'poetry', title: 'Poetry', meta: 'A poem', more: 'More poems', all: 'poems' },
    essay: { id: 'essays', title: 'Essays', meta: 'An essay', more: 'More essays', all: 'essays' },
} as const;

/** Short stories, Poetry and Essays share one frame; the picture alternates sides (stories right, poetry left, essays right). */
function Pieces({ kind, section }: { kind: keyof typeof KIND; section: NonNullable<Section<PieceLead>> }) {
    const k = KIND[kind];
    const { lead } = section;
    const pictureLeft = kind === 'poem';
    const meta = lead.isCollection ? lead.status.text : `${k.meta} · ${lead.minutes} min`;

    const picture = (
        <BookPicture work={lead} width={520} height={347} landscape reveal={pictureLeft ? 1 : 2} type={{ k: 13, t: 50, pad: '40px 48px' }} />
    );

    const text = (
        <div data-reveal="" style={v({ '--i': pictureLeft ? 2 : 1 })}>
            <div className="label">Newest · {lead.date}</div>
            <h3>
                <Link className="tl" href={lead.href}>
                    {lead.title}
                </Link>
            </h3>
            <div className="meta">{meta}</div>
            {kind === 'poem' && lead.stanza.length > 0 ? (
                <div className="stanza" style={{ marginTop: 30 }}>
                    {lead.stanza.map((line, i) => (
                        <p key={i} style={{ ...v({ '--l': i }), paddingLeft: line.indent ? `${line.indent * 1.5}em` : undefined }}>
                            {line.text}
                        </p>
                    ))}
                </div>
            ) : lead.excerpt ? (
                kind === 'story' ? (
                    <p className="excerpt">{lead.excerpt}</p>
                ) : (
                    <p style={{ fontSize: 21, lineHeight: '32px', marginTop: 24, maxWidth: 600 }}>{lead.excerpt}</p>
                )
            ) : null}
            <div style={{ display: 'flex', gap: 20, marginTop: 28 }}>
                {kind === 'poem' ? (
                    <Link className="btn line" href={lead.href}>
                        Read the rest
                    </Link>
                ) : (
                    <Link className="btn solid" href={lead.href}>
                        Start reading
                    </Link>
                )}
            </div>
        </div>
    );

    return (
        <section className="kind wrap" id={k.id}>
            <h2 data-reveal="">{k.title}</h2>
            <div className="lead r work" style={pictureLeft ? { gridTemplateColumns: '520px 1fr', gap: 88 } : undefined}>
                {pictureLeft ? (
                    <>
                        {picture}
                        {text}
                    </>
                ) : (
                    <>
                        {text}
                        {picture}
                    </>
                )}
            </div>
            {section.more.length > 0 && (
                <div className="row4">
                    <div className="caps" style={{ color: 'var(--ink)' }}>
                        {k.more}
                    </div>
                    <div className="grid4">
                        {section.more.map((w, i) =>
                            kind === 'story' ? (
                                <div key={w.id} className="work" data-reveal="" style={v({ '--i': i })}>
                                    <BookPicture work={w} landscape type={{ k: 9, t: 26, pad: '16px 20px' }} />
                                    <div style={{ fontFamily: 'var(--CD)', fontSize: 21, color: 'var(--ink)', marginTop: 12 }}>
                                        <Link className="tl" href={w.href}>
                                            {w.title}
                                        </Link>
                                    </div>
                                    {w.isCollection ? (
                                        <St icon="layers">{w.status.text}</St>
                                    ) : (
                                        <St>
                                            {w.minutes} min · {w.date}
                                        </St>
                                    )}
                                </div>
                            ) : (
                                <div key={w.id} className="tcard" data-reveal="" style={v({ '--i': i })}>
                                    <div className="t">
                                        <Link className="tl" href={w.href}>
                                            {w.title}
                                        </Link>
                                    </div>
                                    {w.firstLine && <p style={kind === 'poem' ? { fontStyle: 'italic' } : undefined}>{w.firstLine}</p>}
                                    <small>
                                        {w.isCollection ? w.status.text : `${w.date} · ${w.minutes} min`}
                                    </small>
                                </div>
                            ),
                        )}
                    </div>
                    <Link className="link see" href={paths.library(kind)}>
                        See all {section.total} {k.all} →
                    </Link>
                </div>
            )}
        </section>
    );
}

/** Home: the reading room. Hero and pile, About me, then Novels, Short stories, Poetry and Essays. */
export default function Home({ hero, pile, about, noteLine, novels, stories, poems, essays }: Props) {
    return (
        <SiteLayout noteLine={noteLine ? <NoteLine note={noteLine} /> : undefined}>
            {hero && <HeroBlock hero={hero} pile={pile} />}

            {about.quote && (
                <section className="kind wrap" id="about">
                    <div className="about">
                        <h2 data-reveal="">About me</h2>
                        <div data-reveal="" style={v({ '--i': 1 })}>
                            <p className="pullq">{about.quote}</p>
                            {about.line && <p className="abp">{about.line}</p>}
                            <div style={{ marginTop: 26 }}>
                                <Link className="link" href={paths.about}>
                                    More about me
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
            )}

            {novels && <Novels section={novels} />}
            {stories && <Pieces kind="story" section={stories} />}
            {poems && <Pieces kind="poem" section={poems} />}
            {essays && <Pieces kind="essay" section={essays} />}
        </SiteLayout>
    );
}

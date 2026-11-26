import { Head, Link, router } from '@inertiajs/react';
import { EditorState } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Brand, ThemeMenu, ViewSite } from '../../Components/admin/AdminTop';
import { KSelect, Unfold } from '../../Components/admin/Controls';
import { BreakIcon, FootnoteIcon, FullIcon, ImageIcon, KeyboardDown, LinkIcon, ListIcon, MusicIcon, QuoteIcon } from '../../Components/admin/editor/icons';
import { addFootnote, asPoem, bulletList, closeFootnote, editorPlugins, inQuote, insertImage, Kind, sceneBreak, setLink, toggleBold, toggleItalic, toggleQuote, topBlock } from '../../Components/admin/editor/plugins';
import { ChapterRow } from '../../Components/admin/editor/chapters';
import { BarPanels, Flash, PublishControls, PublishState, VersionRow, VersionView } from '../../Components/admin/editor/publish';
import { fromStored, schema, toStored } from '../../Components/admin/editor/schema';
import { Alert, BackSmall, Close, Plus, Saved as Tick } from '../../Components/admin/Icons';
import { heading } from '../../lib/headings';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';

type Work = {
    slug: string;
    title: string;
    kind: Kind;
    multi: boolean;
    collection: boolean;
    href: string;
    headingStyle: string;
    showChapterTitles: boolean;
    partWord: string;
    partStyle: string;
    showPartNumbers: boolean;
    sceneBreak: string;
};
type Soundtrack = { url: string; label: string } | null;
type Quote = { q: string; by: string } | null;
type Item = {
    id: number;
    type: 'chapter' | 'part' | 'piece';
    number: number;
    title: string;
    description: string;
    body: Record<string, unknown>;
    status: string;
    pov: number | null;
    soundtrack: Soundtrack;
    quote: Quote;
};
type Props = { work: Work; item: Item; characters: { id: number; name: string }[]; images: Record<number, string>; publish: PublishState; chapters: ChapterRow[] };
type SaveState = 'saving' | 'saved' | 'bad';

/** The reader's marks between scenes, as the prototype names them. */
const BREAK_LOOK: Record<string, string> = { line_dot: 'dot', asterisks: 'ast', asterism: 'asterism', short_line: 'rule', space: 'space' };
const PHONE = () => window.matchMedia('(max-width:600px)').matches;
const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
const countWords = (t: string) => (t.match(/[\p{L}\p{N}’']+/gu) ?? []).length;
const fmt = (n: number) => n.toLocaleString('en-GB');
const plain = (el: HTMLElement | null) => (el?.innerText ?? '').replace(/ /g, ' ').trim();

/** Words in the page, its footnotes included (they are printed too). */
function wordsIn(view: EditorView | null): number {
    if (!view) {
        return 0;
    }
    const doc = view.state.doc;
    let n = countWords(doc.textBetween(0, doc.content.size, '\n', '\n'));
    doc.descendants((node) => {
        if (node.type === schema.nodes.footnote) {
            n += countWords(node.attrs.text);
        }
    });

    return n;
}

/**
 * The writing screen: the page as the reader will see it (the chapter's number in the novel's style, its title,
 * POV, soundtrack and heading quote), saving as she types. A selection bar formats on a computer, a button row
 * above the keyboard on a phone; Full screen hides everything but the page.
 */
export default function Write({ work, item, characters, images, publish, chapters }: Props) {
    const part = item.type === 'part';
    const kind = work.kind;
    const [save, setSave] = useState<SaveState>('saved');
    const [words, setWords] = useState(0);
    const [focus, setFocus] = useState(false);
    const [toast, setToast] = useState('');
    const [pub, setPub] = useState<PublishState>(publish);
    const [flashed, setFlashed] = useState<Flash>(null);
    const [open, setOpen] = useState<{ version: VersionRow; body: Record<string, unknown> } | null>(null);
    const [pov, setPov] = useState<number | null>(item.pov);
    const [quote, setQuote] = useState<Quote>(item.quote);
    const [tick, setTick] = useState(0);
    const view = useRef<EditorView | null>(null);
    const mount = useRef<HTMLDivElement>(null);
    const page = useRef<HTMLDivElement>(null);
    const titleEl = useRef<HTMLHeadingElement>(null);

    /* ---------- saving: changes gather for a moment, then one request; a failed save tries again ---------- */
    const pending = useRef<Record<string, unknown>>({});
    const bodyDirty = useRef(false);
    const timer = useRef(0);
    const flying = useRef(false);
    const url = `/works/${work.slug}/write/${item.id}`;

    const take = useCallback(() => {
        const body: Record<string, unknown> = { ...pending.current };
        if (bodyDirty.current && view.current) {
            body.body = toStored(view.current.state.doc);
        }
        pending.current = {};
        bodyDirty.current = false;

        return body;
    }, []);

    const flush = useCallback(async () => {
        window.clearTimeout(timer.current);
        if (flying.current) {
            timer.current = window.setTimeout(flush, 300);

            return;
        }
        const body = take();
        if (!Object.keys(body).length) {
            setSave('saved');

            return;
        }
        flying.current = true;
        const { status } = await json<{ soundtrack: Soundtrack }>('PATCH', url, body);
        flying.current = false;
        if (status !== 200) {
            pending.current = { ...body, ...pending.current };
            if ('body' in body) {
                delete pending.current.body;
                bodyDirty.current = true;
            }
            setSave('bad');
            timer.current = window.setTimeout(flush, 4000);

            return;
        }
        if (Object.keys(pending.current).length || bodyDirty.current) {
            timer.current = window.setTimeout(flush, 300);
        } else {
            window.setTimeout(() => !flying.current && !bodyDirty.current && !Object.keys(pending.current).length && setSave('saved'), prefersReducedMotion() ? 100 : 450);
        }
    }, [take, url]);

    const queue = useCallback(
        (patch: Record<string, unknown> | null, delay = 600) => {
            if (patch) {
                Object.assign(pending.current, patch);
            }
            setSave('saving');
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(flush, delay);
        },
        [flush],
    );

    /** Save everything waiting, now (before publishing or restoring). */
    const saveNow = useCallback(async () => {
        while (flying.current) {
            await new Promise((r) => window.setTimeout(r, 80));
        }
        await flush();
    }, [flush]);

    const flashTimer = useRef(0);
    const flash = (f: Flash) => {
        setFlashed(f);
        window.clearTimeout(flashTimer.current);
        flashTimer.current = window.setTimeout(() => setFlashed(null), 1800);
    };

    /* a version opens in place of the page */
    async function openVersion(v: VersionRow) {
        await saveNow();
        const { status, data } = await json<{ body: Record<string, unknown> }>('GET', `${url}/versions/${v.id}`);
        if (status === 200 && data.body) {
            setOpen({ version: v, body: data.body });
            window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        }
    }

    async function restore() {
        if (!open) {
            return;
        }
        await saveNow();
        const { status, data } = await json<PublishState & { body: Record<string, unknown> }>('POST', `${url}/versions/${open.version.id}/restore`);
        if (status !== 200 || !data.body || !view.current) {
            say('That didn’t restore. Try again.');

            return;
        }
        let doc = fromStored(data.body as never, images);
        if (kind === 'poem') {
            doc = asPoem(doc);
        }
        const v = view.current;
        v.updateState(EditorState.create({ doc, plugins: v.state.plugins }));
        setWords(wordsIn(v));
        setPub(data as PublishState);
        setOpen(null);
        say('Restored as your draft');
    }

    /* leaving the page saves what's left, even as the page goes */
    useEffect(() => {
        const leave = () => {
            const body = take();
            if (Object.keys(body).length) {
                fetch(url, {
                    method: 'PATCH',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                    body: JSON.stringify(body),
                });
            }
        };
        window.addEventListener('pagehide', leave);
        const off = router.on('before', leave);

        return () => {
            window.removeEventListener('pagehide', leave);
            off();
            leave();
        };
    }, [take, url]);

    /* ---------- the page's look: its own body class, the scene-break mark, settling in ---------- */
    useEffect(() => {
        const b = document.body.classList;
        b.add('wa', 'wpage');
        const look = BREAK_LOOK[work.sceneBreak] ?? 'dot';
        if (look !== 'dot') {
            document.documentElement.dataset.sb = look;
        }
        const el = page.current;
        if (el) {
            [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i)));
            el.classList.add('settle');
        }

        return () => {
            b.remove('wa', 'wpage');
            delete document.documentElement.dataset.sb;
            document.documentElement.classList.remove('focus', 'typing');
        };
    }, [work.sceneBreak]);

    /* ---------- the editor ---------- */
    const [linking, setLinking] = useState(false);
    const linkingRef = useRef(false);
    linkingRef.current = linking;

    useEffect(() => {
        if (part || !mount.current) {
            return;
        }
        let doc = fromStored(item.body as never, images);
        if (kind === 'poem') {
            doc = asPoem(doc);
        }
        const v = new EditorView(
            { mount: mount.current },
            {
                state: EditorState.create({
                    doc,
                    plugins: editorPlugins(kind, {
                        link: () => {
                            if (view.current && !view.current.state.selection.empty) {
                                setLinking(true);
                            }

                            return true;
                        },
                    }),
                }),
                attributes: {
                    id: 'ed',
                    role: 'textbox',
                    'aria-multiline': 'true',
                    'aria-label': work.multi ? label() : work.title,
                    spellcheck: 'true',
                },
                dispatchTransaction(tr) {
                    const next = v.state.apply(tr);
                    v.updateState(next);
                    if (tr.docChanged) {
                        /* a published chapter with edits waiting: Update */
                        setPub((p) => (p.state === 'pub' ? { ...p, state: 'chg' } : p));
                        bodyDirty.current = true;
                        queue(null);
                        setWords(wordsIn(v));
                    }
                    setTick((t) => t + 1);
                },
                handleDOMEvents: {
                    focus: () => {
                        typing(true);
                        setTick((t) => t + 1);

                        return false;
                    },
                    blur: () => {
                        window.setTimeout(() => {
                            if (!v.dom.contains(document.activeElement) && !linkingRef.current) {
                                typing(false);
                            }
                            setTick((t) => t + 1);
                        }, 120);

                        return false;
                    },
                },
            },
        );
        view.current = v;
        setWords(wordsIn(v));
        if (work.multi && !item.title && !v.state.doc.textContent.trim()) {
            window.setTimeout(() => titleEl.current?.focus(), prefersReducedMotion() ? 0 : 400);
        }

        return () => {
            v.destroy();
            view.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function label(): string {
        if (part) {
            return work.showPartNumbers ? heading(item.number, work.partWord, work.partStyle) : '';
        }
        if (work.collection) {
            return `${({ poem: 'Poem', essay: 'Essay' } as Record<string, string>)[kind] ?? 'Story'} ${item.number}`;
        }

        return heading(item.number, 'Chapter', work.headingStyle);
    }

    function run(cmd: (s: EditorState, d?: EditorView['dispatch'], v?: EditorView) => boolean) {
        const v = view.current;
        if (v) {
            cmd(v.state, v.dispatch, v);
            v.focus();
        }
    }

    /* phone: the button row rides on the keyboard; the header and bar step away while she types */
    const kbrow = useRef<HTMLDivElement>(null);
    function typing(on: boolean) {
        document.documentElement.classList.toggle('typing', on && PHONE());
        kbrow.current?.classList.toggle('on', on && PHONE());
    }
    /* the row follows the editor's focus (a tap that places the caret counts too) */
    useEffect(() => {
        const ed = view.current;
        if (ed && !linkingRef.current) {
            typing(ed.hasFocus());
        }
    });
    useEffect(() => {
        const bump = () => setTick((t) => t + 1);
        document.addEventListener('selectionchange', bump);

        return () => document.removeEventListener('selectionchange', bump);
    }, []);

    useEffect(() => {
        const vv = window.visualViewport;
        if (!vv) {
            return;
        }
        const place = () => {
            if (kbrow.current) {
                kbrow.current.style.transform = `translateY(${-(window.innerHeight - vv.height - vv.offsetTop)}px)`;
            }
        };
        vv.addEventListener('resize', place);
        vv.addEventListener('scroll', place);

        return () => {
            vv.removeEventListener('resize', place);
            vv.removeEventListener('scroll', place);
        };
    }, []);

    /* ---------- pictures (essays and stories) ---------- */
    const file = useRef<HTMLInputElement>(null);
    async function upload(f: File) {
        const body = new FormData();
        body.append('image', f);
        setSave('saving');
        try {
            const res = await fetch(`${url}/image`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                say(data.errors?.image?.[0] ?? 'That picture couldn’t be added. Try again.');
                setSave('saved');

                return;
            }
            run(insertImage(data.mediaId, data.src));
        } catch {
            say('No connection. Try again.');
            setSave('bad');
        }
    }

    const toastTimer = useRef(0);
    function say(t: string) {
        setToast(t);
        window.clearTimeout(toastTimer.current);
        toastTimer.current = window.setTimeout(() => setToast(''), 2600);
    }

    /* ---------- full screen ---------- */
    useEffect(() => {
        document.documentElement.classList.toggle('focus', focus);
        if (!focus) {
            return;
        }
        view.current?.focus();
        const esc = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !document.querySelector('.k-select.open') && !linkingRef.current) {
                setFocus(false);
                window.setTimeout(() => document.getElementById('foB')?.focus(), 0);
            }
        };
        document.addEventListener('keydown', esc);

        return () => document.removeEventListener('keydown', esc);
    }, [focus]);

    const kick = work.multi ? label() : '';
    const privateTitle = kind === 'novel' && !part && !work.showChapterTitles;
    const v = view.current;
    void tick;

    return (
        <>
            <Head title={`${item.title || work.title}`} />
            <main className="ad-page" id="main">
                <header className="wa-top" id="wTop">
                    <Brand />
                    <Link className="wa-crumb" id="crumb" href={work.href}>
                        <BackSmall />
                        <span>{work.title}</span>
                    </Link>
                    <span className="wa-sp" />
                    <SaveNote state={save} flash={flashed} id="sv" />
                    <ViewSite />
                    <button className="ad-ic fo" id="foB" type="button" aria-label="Full screen" aria-pressed={focus} onClick={() => setFocus(true)}>
                        <FullIcon />
                    </button>
                    <ThemeMenu />
                    <PublishControls
                        url={url}
                        part={part}
                        words={words}
                        pub={pub}
                        setPub={setPub}
                        flush={saveNow}
                        flash={flash}
                        say={say}
                        label={work.multi ? `${work.collection ? ({ poem: 'poem', essay: 'essay' } as Record<string, string>)[kind] ?? 'story' : 'chapter'} ${item.number}` : work.title}
                    />
                </header>
                <div className="wa-body">
                    <div className={`w-page k-${part ? 'part' : kind}${open ? ' vmode' : ''}`} id="pg" ref={page}>
                        {part ? (
                            <PartOpener work={work} item={item} kick={kick} quote={quote} setQuote={setQuote} queue={queue} titleEl={titleEl} />
                        ) : (
                            <>
                                <div className="wc-open">
                                    {kick && <span className="w-hn">{kick}</span>}
                                    {work.multi ? (
                                        privateTitle ? (
                                            <div className="wc-pt">
                                                <span className="k-fl" id="tiL">
                                                    Private title
                                                </span>
                                                <EditableTitle el={titleEl} initial={item.title} placeholder="Only you see this" className="w-tt w-ce w-pvt" labelledBy="tiL" onText={(t) => queue({ title: t })} onEnter={() => view.current?.focus()} />
                                            </div>
                                        ) : (
                                            <EditableTitle el={titleEl} initial={item.title} placeholder="Title" className="w-tt w-ce" onText={(t) => queue({ title: t })} onEnter={() => view.current?.focus()} />
                                        )
                                    ) : (
                                        <h1 className="w-tt" id="ti">
                                            {work.title}
                                        </h1>
                                    )}
                                    {kind === 'novel' && (
                                        <KSelect
                                            className="wc-povs"
                                            id="povB"
                                            buttonClassName="k-sbtn wc-povb"
                                            listClassName="w-lb2"
                                            label="POV"
                                            value={pov ? String(pov) : ''}
                                            options={[...characters.map((c) => ({ value: String(c.id), label: c.name })), { value: '', label: 'No POV' }]}
                                            display={<span className={`v${pov ? '' : ' w-phx'}`}>{characters.find((c) => c.id === pov)?.name ?? 'POV'}</span>}
                                            onPick={(val) => {
                                                const id = val ? Number(val) : null;
                                                setPov(id);
                                                queue({ pov: id }, 0);
                                            }}
                                        />
                                    )}
                                    <SoundtrackField initial={item.soundtrack} url={url} setSave={setSave} />
                                    {!quote && (
                                        <button className="wc-qb" type="button" id="qB" onClick={() => setQuote({ q: '', by: '' })}>
                                            <QuoteIcon size={15} />
                                            <span>Quote</span>
                                        </button>
                                    )}
                                </div>
                                {quote && <QuoteBlock quote={quote} onChange={(q) => queue({ quote: q })} onRemove={() => (setQuote(null), queue({ quote: null }, 0))} onDone={() => view.current?.focus()} />}
                                <div ref={mount} hidden={!!open} />
                                {open && <VersionView version={open.version} body={open.body as never} current={view.current ? toStored(view.current.state.doc) : null} onBack={() => setOpen(null)} onRestore={restore} />}
                                <PlusMenu view={v} kind={kind} onImage={() => file.current?.click()} run={run} />
                            </>
                        )}
                    </div>
                </div>
            </main>
            <BarPanels
                work={work}
                itemId={item.id}
                part={part}
                words={words}
                rows={chapters}
                pub={pub}
                current={() => (view.current ? toStored(view.current.state.doc) : null)}
                onOpenVersion={openVersion}
                countLabel={part ? 'Part page · 2 pages' : `${fmt(words)} ${words === 1 ? 'word' : 'words'}${words ? ` · ≈ ${Math.ceil(words / 280)} ${Math.ceil(words / 280) === 1 ? 'page' : 'pages'}` : ''}`}
            />
            {!part && <SelectionBar view={v} kind={kind} linking={linking} setLinking={setLinking} run={run} />}
            {!part && (
                <div className="w-kbrow" id="kbrow" role="toolbar" aria-label="Format" ref={kbrow} onMouseDown={(e) => e.preventDefault()}>
                    <button type="button" aria-label="Bold" onClick={() => run(toggleBold)}>
                        <b className="w-g">B</b>
                    </button>
                    <button type="button" aria-label="Italic" onClick={() => run(toggleItalic)}>
                        <i className="w-g it">I</i>
                    </button>
                    <button type="button" aria-label="Quote" onClick={() => run(toggleQuote(kind))}>
                        <QuoteIcon />
                    </button>
                    <button type="button" aria-label="Footnote" onClick={() => run(addFootnote)}>
                        <FootnoteIcon />
                    </button>
                    {kind === 'essay' && (
                        <>
                            <button type="button" aria-label="List" onClick={() => run(bulletList())}>
                                <ListIcon />
                            </button>
                            <button type="button" aria-label="Image" onClick={() => file.current?.click()}>
                                <ImageIcon />
                            </button>
                        </>
                    )}
                    {(kind === 'novel' || kind === 'story') && (
                        <button type="button" aria-label="Scene break" onClick={() => run(sceneBreak(false))}>
                            <BreakIcon />
                        </button>
                    )}
                    <button
                        type="button"
                        aria-label="Link"
                        onClick={() => {
                            const u = view.current && !view.current.state.selection.empty ? window.prompt('Link') : null;
                            if (u) {
                                run(setLink(u.trim()));
                            }
                        }}
                    >
                        <LinkIcon />
                    </button>
                    <span className="sp" />
                    <button
                        type="button"
                        className="dn"
                        aria-label="Hide keyboard"
                        onClick={() => {
                            view.current?.dom.blur();
                            getSelection()?.removeAllRanges();
                        }}
                    >
                        <KeyboardDown />
                    </button>
                </div>
            )}
            <div className="w-fx" id="fx">
                <SaveNote state={save} flash={flashed} id="sv2" />
                <button className="ad-ic" id="fxB" type="button" aria-label="Leave full screen" onClick={() => setFocus(false)}>
                    <FullIcon />
                </button>
            </div>
            <div className={`w-toast${toast ? ' on' : ''}`} id="toast" role="status" aria-live="polite">
                {toast}
            </div>
            <input type="file" ref={file} accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => (e.target.files?.[0] && upload(e.target.files[0]), (e.target.value = ''))} />
        </>
    );
}

/* ---------------------------------------------------------------- pieces of the page */
const FLASH = { pub: 'Published', upd: 'Updated', sch: 'Scheduled', unpub: 'Unpublished' } as const;

function SaveNote({ state, flash, id }: { state: SaveState; flash: Flash; id: string }) {
    const shown = flash && state !== 'bad' ? FLASH[flash] : null;

    return (
        <span className="k-saved" id={id} aria-live="polite" data-s={shown ? 'saved' : state}>
            <span className="i">{shown ? <Tick /> : state === 'saving' ? <span className="w-rg" /> : state === 'bad' ? <Alert /> : <Tick />}</span>
            <span className="t">{shown ?? (state === 'saving' ? 'Saving…' : state === 'bad' ? 'Not saved' : 'Saved')}</span>
        </span>
    );
}

/** A title written in place: plain text, one line; Enter goes on to the page. */
function EditableTitle({
    el,
    initial,
    placeholder,
    className,
    labelledBy,
    onText,
    onEnter,
}: {
    el: React.RefObject<HTMLHeadingElement>;
    initial: string;
    placeholder: string;
    className: string;
    labelledBy?: string;
    onText: (t: string) => void;
    onEnter: () => void;
}) {
    useLayoutEffect(() => {
        if (el.current) {
            el.current.textContent = initial;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <h1
            ref={el}
            className={className}
            id="ti"
            contentEditable
            suppressContentEditableWarning
            data-ph={placeholder}
            aria-labelledby={labelledBy}
            spellCheck
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    onEnter();
                }
            }}
            onInput={(e) => {
                const t = e.currentTarget;
                if (!t.textContent?.trim()) {
                    t.innerHTML = '';
                }
                onText(plain(t));
            }}
            onPaste={(e) => {
                e.preventDefault();
                document.execCommand('insertText', false, e.clipboardData.getData('text/plain').replace(/\s+/g, ' '));
            }}
        />
    );
}

/** A part's own page: its number in the novel's style, its title, a line about it, a heading quote. */
function PartOpener({
    work,
    item,
    kick,
    quote,
    setQuote,
    queue,
    titleEl,
}: {
    work: Work;
    item: Item;
    kick: string;
    quote: Quote;
    setQuote: (q: Quote) => void;
    queue: (p: Record<string, unknown> | null, delay?: number) => void;
    titleEl: React.RefObject<HTMLHeadingElement>;
}) {
    const desc = useRef<HTMLParagraphElement>(null);
    useLayoutEffect(() => {
        if (desc.current) {
            desc.current.textContent = item.description;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    void work;

    return (
        <div className="w-part">
            {kick && <span className="w-hn">{kick}</span>}
            <EditableTitle el={titleEl} initial={item.title} placeholder="Title" className="w-tt w-ce" onText={(t) => queue({ title: t })} onEnter={() => desc.current?.focus()} />
            <p
                ref={desc}
                className="w-pd w-ce"
                id="pd"
                contentEditable
                suppressContentEditableWarning
                data-ph="Description"
                onInput={(e) => {
                    if (!e.currentTarget.textContent?.trim()) {
                        e.currentTarget.innerHTML = '';
                    }
                    queue({ description: plain(e.currentTarget) });
                }}
                onPaste={(e) => {
                    e.preventDefault();
                    document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
                }}
            />
            {quote ? (
                <QuoteBlock quote={quote} onChange={(q) => queue({ quote: q })} onRemove={() => (setQuote(null), queue({ quote: null }, 0))} />
            ) : (
                <div className="q-row pt">
                    <button className="wc-qb" type="button" id="qB" onClick={() => setQuote({ q: '', by: '' })}>
                        <QuoteIcon size={15} />
                        <span>Quote</span>
                    </button>
                </div>
            )}
        </div>
    );
}

/** A heading quote: the words and who said it, written in place. */
function QuoteBlock({ quote, onChange, onRemove, onDone }: { quote: { q: string; by: string }; onChange: (q: { q: string; by: string }) => void; onRemove: () => void; onDone?: () => void }) {
    const q = useRef<HTMLSpanElement>(null);
    const by = useRef<HTMLSpanElement>(null);
    const box = useRef<HTMLDivElement>(null);
    useLayoutEffect(() => {
        if (q.current && by.current) {
            q.current.textContent = quote.q;
            by.current.textContent = quote.by;
        }
        if (!quote.q) {
            q.current?.focus();
        }
        if (!prefersReducedMotion()) {
            box.current?.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 320, easing: 'cubic-bezier(.22,1,.36,1)' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const changed = () => onChange({ q: q.current?.innerText.trim() ?? '', by: plain(by.current) });

    return (
        <div className="epi ed" id="qE" ref={box}>
            <span ref={q} className="q w-ce" id="qQ" contentEditable="plaintext-only" suppressContentEditableWarning data-ph="Quote" spellCheck onInput={changed} />
            <span
                ref={by}
                className="by w-ce"
                id="qBy"
                contentEditable="plaintext-only"
                suppressContentEditableWarning
                data-ph="Who said it"
                spellCheck
                onInput={changed}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onDone?.();
                    }
                }}
            />
            <button
                className="x ad-ic"
                type="button"
                id="qX"
                aria-label="Remove quote"
                onClick={() => {
                    if (prefersReducedMotion() || !box.current) {
                        onRemove();
                    } else {
                        box.current.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200 }).onfinish = onRemove;
                    }
                }}
            >
                <Close size={16} />
            </button>
        </div>
    );
}

/** Soundtrack: a YouTube or Spotify link for this chapter; its title shows once saved. */
function SoundtrackField({ initial, url, setSave }: { initial: Soundtrack; url: string; setSave: (s: SaveState) => void }) {
    const [track, setTrack] = useState<Soundtrack>(initial);
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState('');
    const [bad, setBad] = useState('');
    const pop = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const button = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        setValue(track?.url ?? '');
        setBad('');
        const t = window.setTimeout(() => input.current?.focus(), prefersReducedMotion() ? 0 : 120);
        const outside = () => setOpen(false);
        document.addEventListener('click', outside);

        return () => {
            window.clearTimeout(t);
            document.removeEventListener('click', outside);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    async function store(link: string) {
        const u = link.trim();
        if (u && !/^https:\/\/(www\.|m\.|music\.)?(youtube\.com|youtu\.be|open\.spotify\.com)\//i.test(u)) {
            setBad('Paste a YouTube or Spotify link.');
            if (!prefersReducedMotion()) {
                pop.current?.animate([{ translate: '0' }, { translate: '-6px' }, { translate: '5px' }, { translate: '-3px' }, { translate: '0' }], { duration: 360 });
            }

            return;
        }
        setOpen(false);
        setSave('saving');
        const { status, data } = await json<{ soundtrack: Soundtrack }>('PATCH', url, { soundtrack: u || null });
        if (status !== 200) {
            setSave('bad');

            return;
        }
        setTrack(data.soundtrack ?? null);
        setSave('saved');
        button.current?.focus();
    }

    return (
        <div className="wc-sndw">
            <button
                ref={button}
                type="button"
                className="wc-snd"
                id="sndB"
                aria-expanded={open}
                onClick={(e) => {
                    e.nativeEvent.stopImmediatePropagation();
                    setOpen(!open);
                }}
            >
                <MusicIcon />
                <span className={`wc-ed${track ? '' : ' w-phx'}`} id="sndL">
                    {track?.label ?? 'Soundtrack'}
                </span>
            </button>
            <div ref={pop} className={`w-sndp${open ? ' open' : ''}`} id="sndP" role="dialog" aria-label="Soundtrack" onClick={(e) => e.nativeEvent.stopImmediatePropagation()}>
                <div className="k-f">
                    <label className="k-fl" htmlFor="sndI">
                        Soundtrack
                    </label>
                    <span className={`k-in${bad ? ' bad' : ''}`} id="sndIn">
                        <input
                            ref={input}
                            id="sndI"
                            placeholder="Paste a YouTube or Spotify link"
                            autoComplete="off"
                            spellCheck={false}
                            value={value}
                            onChange={(e) => {
                                setValue(e.target.value);
                                setBad('');
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    store(value);
                                }
                                if (e.key === 'Escape') {
                                    setOpen(false);
                                    button.current?.focus();
                                }
                            }}
                        />
                    </span>
                </div>
                <Unfold show={!!bad} id="sndE">
                    <div className="k-err" style={{ marginTop: 10 }}>
                        <p>
                            <Alert />
                            <span>{bad || 'Paste a YouTube or Spotify link.'}</span>
                        </p>
                    </div>
                </Unfold>
                <div className="acts">
                    <button className="w-act" type="button" id="sndR" onClick={() => store('')}>
                        Remove
                    </button>
                    <button className="k-btn line sm" type="button" id="sndS" onClick={() => store(value)}>
                        <span>Save</span>
                    </button>
                </div>
            </div>
        </div>
    );
}

/** The + on an empty line (computer): Quote, Scene break, and a list or picture in essays. */
function PlusMenu({ view, kind, onImage, run }: { view: EditorView | null; kind: Kind; onImage: () => void; run: (c: never) => void }) {
    const [open, setOpen] = useState(false);
    let top: number | null = null;
    if (view && view.hasFocus() && view.state.selection.empty) {
        const b = topBlock(view.state);
        if (b && b.node.type === schema.nodes.paragraph && b.node.content.size === 0) {
            const dom = view.nodeDOM(b.pos) as HTMLElement | null;
            if (dom) {
                top = view.dom.offsetTop + dom.offsetTop + dom.offsetHeight / 2 - 18;
            }
        }
    }
    const shown = top !== null || open;
    const last = useRef(0);
    if (top !== null) {
        last.current = top;
    }
    useEffect(() => {
        if (!open) {
            return;
        }
        const close = (e: MouseEvent) => !(e.target as HTMLElement).closest('#plm,#plus') && setOpen(false);
        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);
    const items: [string, JSX.Element, string, () => void][] = [
        ['quote', <QuoteIcon />, 'Quote', () => run(toggleQuote(kind) as never)],
        ...(kind !== 'poem' ? [['brk', <BreakIcon />, 'Scene break', () => run(sceneBreak(true) as never)] as [string, JSX.Element, string, () => void]] : []),
        ...(kind === 'essay' ? [['ul', <ListIcon />, 'List', () => run(bulletList() as never)] as [string, JSX.Element, string, () => void]] : []),
        ...(kind === 'essay' || kind === 'story' ? [['img', <ImageIcon />, 'Image', onImage] as [string, JSX.Element, string, () => void]] : []),
    ];

    return (
        <>
            <button
                className={`w-plus${shown ? ' on' : ''}`}
                id="plus"
                type="button"
                aria-label="Insert"
                aria-expanded={open}
                style={{ top: last.current }}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => setOpen(!open)}
            >
                <Plus />
            </button>
            <div className={`w-plm${open ? ' open' : ''}`} id="plm" role="menu" style={{ top: last.current + 44 }} onMouseDown={(e) => e.preventDefault()}>
                {items.map(([k, icon, text, act]) => (
                    <button
                        key={k}
                        type="button"
                        role="menuitem"
                        onClick={() => {
                            setOpen(false);
                            act();
                        }}
                    >
                        {icon}
                        <span>{text}</span>
                    </button>
                ))}
            </div>
        </>
    );
}

/** The bar over selected words (computer): Bold, Italic, Link, Quote, Footnote. Link turns it into a link field. */
function SelectionBar({ view, kind, linking, setLinking, run }: { view: EditorView | null; kind: Kind; linking: boolean; setLinking: (on: boolean) => void; run: (c: never) => void }) {
    const bar = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number } | null>(null);
    const sel = view?.state.selection;
    const show = !!view && (linking || (view.hasFocus() && !!sel && !sel.empty && sel.$from.parent.inlineContent)) && !PHONE();

    useLayoutEffect(() => {
        if (!show || !view || !bar.current) {
            if (!show) {
                setPos(null);
            }

            return;
        }
        const place = () => {
            const s = view.state.selection;
            const a = view.coordsAtPos(s.from);
            const b = view.coordsAtPos(s.to);
            const w = bar.current!.offsetWidth;
            const h = bar.current!.offsetHeight;
            const mid = a.top === b.top ? (a.left + b.right) / 2 : (a.left + b.left) / 2;
            const left = Math.max(12, Math.min(window.innerWidth - w - 12, mid - w / 2));
            const y = Math.min(a.top, b.top) - h - 12;
            setPos({ left, top: y < 70 ? Math.max(a.bottom, b.bottom) + 12 : y });
        };
        place();
        window.addEventListener('scroll', place, { passive: true });

        return () => window.removeEventListener('scroll', place);
    }, [show, view, sel, linking]);

    const marks = view && sel ? sel.$from.marks().concat(view.state.storedMarks ?? []) : [];
    const has = (name: string) => !!view && !!sel && (view.state.doc.rangeHasMark(sel.from, sel.to, schema.marks[name]) || marks.some((m) => m.type.name === name));

    return (
        <div
            ref={bar}
            className={`w-tb${show && pos ? ' on' : ''}${linking ? ' lk' : ''}`}
            id="selbar"
            role="toolbar"
            aria-label="Format"
            style={pos ? { left: pos.left, top: pos.top } : undefined}
            onMouseDown={(e) => !(e.target as HTMLElement).closest('input') && e.preventDefault()}
        >
            {linking ? (
                <LinkField
                    onDone={(href) => {
                        setLinking(false);
                        if (href !== null) {
                            run(setLink(href) as never);
                        } else {
                            view?.focus();
                        }
                    }}
                />
            ) : (
                <>
                    <button type="button" aria-label="Bold" title="Bold" className={has('bold') ? 'act' : undefined} onClick={() => run(toggleBold as never)}>
                        <b className="w-g">B</b>
                    </button>
                    <button type="button" aria-label="Italic" title="Italic" className={has('italic') ? 'act' : undefined} onClick={() => run(toggleItalic as never)}>
                        <i className="w-g it">I</i>
                    </button>
                    <button type="button" aria-label="Link" title="Link · Ctrl K" onClick={() => setLinking(true)}>
                        <LinkIcon />
                    </button>
                    <button type="button" aria-label="Quote" title="Quote" className={view && inQuote(view.state) ? 'act' : undefined} onClick={() => run(toggleQuote(kind) as never)}>
                        <QuoteIcon />
                    </button>
                    <button
                        type="button"
                        aria-label="Footnote"
                        title="Footnote · Ctrl Alt F"
                        onClick={() => {
                            closeFootnote();
                            run(addFootnote as never);
                        }}
                    >
                        <FootnoteIcon />
                    </button>
                </>
            )}
        </div>
    );
}

function LinkField({ onDone }: { onDone: (href: string | null) => void }) {
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => input.current?.focus(), []);

    return (
        <>
            <input
                ref={input}
                type="url"
                placeholder="Paste a link, then Enter"
                aria-label="Link"
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onDone(e.currentTarget.value.trim());
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        onDone(null);
                    }
                }}
            />
            <button type="button" aria-label="Cancel" onClick={() => onDone(null)}>
                <Close size={14} width={2} />
            </button>
        </>
    );
}

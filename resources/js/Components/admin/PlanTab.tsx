import { Link, router } from '@inertiajs/react';
import { ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';
import { heading } from '../../lib/headings';
import { ACCENTS, plainName, tone } from '../../lib/tone';
import { KSelect, SaveState, Unfold } from './Controls';
import { ExtIcon, FileIcon19, FoldIcon, ImgIcon19, LinkIcon19, NoteIcon19, PovIcon } from './editor/icons';
import { BackSmall, Chevron, Close, Plus, Search } from './Icons';

export type Plan = {
    sections: string[];
    chars: { id: number; name: string; typedAs: string; aliases: string[]; role: string; notes: string; links: { id: number; to: number; label: string }[]; pov: number[]; in: number[] }[];
    groups: { id: number; name: string; entries: { id: number; name: string; typedAs: string; description: string; notes: string; in: number[] }[] }[];
    threads: { id: number; name: string; colour: number }[];
    points: { id: number; thread: number; item: number | null; text: string }[];
    research: { id: number; kind: 'link' | 'img' | 'note' | 'file'; title: string; url: string; note: string; src: string | null; size: number | null; ties: string[] }[];
    notes: string;
    chapters: { id: number; number: number; title: string; part: string | null; partNumber: number | null; written: boolean }[];
};
type Char = Plan['chars'][number];
type Entry = Plan['groups'][number]['entries'][number];
type Res = Plan['research'][number];

/** How the work names its parts ("Part One"), set by the work page. */
export type PartLook = { word: string; style: string; show: boolean };
let LOOK: PartLook = { word: 'Part', style: 'chapter_word', show: true };
const partLabel = (n: number | null, title: string | null) => (n && LOOK.show ? heading(n, LOOK.word || 'Part', LOOK.style) : title);

const NAMES: Record<string, string> = { ch: 'Characters', plot: 'Plot', world: 'World', res: 'Research', notes: 'Notes' };
const COLS = ['var(--accent)', 'var(--plum)', 'var(--wine)', 'var(--muted)', 'var(--deep)', 'var(--blush)'];
const RK: Record<Res['kind'], [() => JSX.Element, string]> = { link: [LinkIcon19, 'Link'], img: [ImgIcon19, 'Image'], note: [NoteIcon19, 'Note'], file: [FileIcon19, 'File'] };
const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
const fold = (s: string) => plainName(s).toLowerCase();
const host = (u: string) => {
    try {
        return new URL(u).host.replace(/^www\./, '');
    } catch {
        return u;
    }
};
const size = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);
const textOf = (html: string) => {
    const t = document.createElement('template');
    t.innerHTML = html;

    return t.content.textContent ?? '';
};

/* ---------------------------------------------------------------- notes: small rich text, markdown shortcuts as she types and on paste */
function inline(t: string) {
    return t
        .replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]!)
        .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
        .replace(/(^|[^*])\*([^*]+)\*/g, '$1<i>$2</i>');
}
function md2html(text: string) {
    let h = '';
    let ul = false;
    let para: string[] = [];
    const flush = () => {
        if (para.length) {
            h += '<p>' + para.map(inline).join('<br>') + '</p>';
            para = [];
        }
    };
    for (const line of text.replace(/\r/g, '').split('\n')) {
        const li = line.match(/^\s*[-*]\s+(.*)$/);
        if (li) {
            flush();
            if (!ul) {
                h += '<ul>';
                ul = true;
            }
            h += `<li>${inline(li[1])}</li>`;
            continue;
        }
        if (ul) {
            h += '</ul>';
            ul = false;
        }
        if (!line.trim()) {
            flush();
        } else {
            para.push(line);
        }
    }
    flush();

    return h + (ul ? '</ul>' : '');
}

function NotesField({ html, placeholder, big = false, label, labelledBy, onSave }: { html: string; placeholder: string; big?: boolean; label?: string; labelledBy?: string; onSave: (h: string) => void }) {
    const el = useRef<HTMLDivElement>(null);
    const timer = useRef(0);
    useLayoutEffect(() => {
        if (el.current) {
            el.current.innerHTML = html;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const save = () => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => onSave(el.current?.innerHTML ?? ''), 600);
    };

    return (
        <div
            ref={el}
            className={`pl-notes${big ? ' big' : ''} ed md`}
            contentEditable
            suppressContentEditableWarning
            role="textbox"
            aria-multiline="true"
            aria-label={label}
            aria-labelledby={labelledBy}
            data-ph={placeholder}
            spellCheck
            onInput={(e) => {
                const s = getSelection();
                const node = s?.anchorNode;
                if (node && node.nodeType === 3 && (e.nativeEvent as InputEvent).data === '*') {
                    const t = node.textContent ?? '';
                    const upto = t.slice(0, s!.anchorOffset);
                    const m = upto.match(/\*\*([^*]+)\*\*$/) ?? upto.match(/(?:^|[^*])\*([^*]+)\*$/);
                    if (m) {
                        const whole = m[0].startsWith('**') ? m[0] : m[0].replace(/^[^*]/, '');
                        const r = document.createRange();
                        r.setStart(node, upto.length - whole.length);
                        r.setEnd(node, upto.length);
                        s!.removeAllRanges();
                        s!.addRange(r);
                        document.execCommand('insertHTML', false, (whole.startsWith('**') ? '<b>' : '<i>') + inline(m[1]) + (whole.startsWith('**') ? '</b>' : '</i>') + '&#8203;');
                    }
                }
                if (node && node.nodeType === 3 && /^[-*]\s$/.test((node.textContent ?? '').slice(0, 2)) && (e.nativeEvent as InputEvent).data === ' ' && s!.anchorOffset === 2) {
                    const r = document.createRange();
                    r.setStart(node, 0);
                    r.setEnd(node, 2);
                    s!.removeAllRanges();
                    s!.addRange(r);
                    document.execCommand('delete');
                    document.execCommand('insertUnorderedList');
                }
                save();
            }}
            onKeyDown={(e) => {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'i')) {
                    e.preventDefault();
                    document.execCommand(e.key === 'b' ? 'bold' : 'italic');
                    save();
                }
            }}
            onPaste={(e) => {
                const t = e.clipboardData.getData('text/plain');
                e.preventDefault();
                document.execCommand('insertHTML', false, md2html(t));
                save();
            }}
        />
    );
}

/* ---------------------------------------------------------------- a name field: tone numbers become marks, and the mark row helps */
function NameInput({ id, value, onChange, placeholder, onEnter, field = 'name' }: { id: string; value: string; onChange: (v: string) => void; placeholder?: string; onEnter?: () => void; field?: string }) {
    const input = useRef<HTMLInputElement>(null);

    return (
        <span className="k-in">
            <input
                ref={input}
                id={id}
                data-f={field}
                value={value}
                placeholder={placeholder}
                autoComplete="off"
                spellCheck={false}
                onChange={(e) => {
                    const v = e.target.value;
                    if (/[A-Za-zÜüVv][1-5]/.test(v)) {
                        const pos = e.target.selectionStart ?? v.length;
                        const next = tone(v);
                        onChange(next);
                        const d = v.length - next.length;
                        window.requestAnimationFrame(() => input.current?.setSelectionRange(pos - d, pos - d));
                    } else {
                        onChange(v);
                    }
                }}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && onEnter) {
                        e.preventDefault();
                        onEnter();
                    }
                }}
            />
        </span>
    );
}

function Accents({ target }: { target: string }) {
    return (
        <div className="pl-acc" role="toolbar" aria-label="Tone marks">
            {ACCENTS.map((a) => (
                <button
                    key={a}
                    type="button"
                    tabIndex={-1}
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => {
                        const i = document.getElementById(target) as HTMLInputElement | null;
                        if (i) {
                            i.focus();
                            document.execCommand('insertText', false, a);
                        }
                    }}
                >
                    {a}
                </button>
            ))}
        </div>
    );
}

/* ---------------------------------------------------------------- the chapter strip: POV, in it, not written yet */
function Strip({ chapters, mark, slug }: { chapters: Plan['chapters']; mark: (id: number) => '' | 'p' | 'i'; slug: string }) {
    if (!chapters.length) {
        return <p className="pl-none">No chapters yet.</p>;
    }
    const parts: { name: string | null; number: number | null; list: Plan['chapters'] }[] = [];
    chapters.forEach((c) => {
        const last = parts[parts.length - 1];
        if (!last || last.number !== c.partNumber) {
            parts.push({ name: c.part, number: c.partNumber, list: [c] });
        } else {
            last.list.push(c);
        }
    });

    return (
        <>
            <div className="pl-strip">
                {parts.map((p, k) => (
                    <div className="pl-sp" key={k}>
                        {p.name !== null && <span className="pn">{partLabel(p.number, p.name)}</span>}
                        <div className="pl-sqs">
                            {p.list.map((c) => {
                                const m = mark(c.id);
                                const cls = m || (c.written ? '' : 'u');

                                return (
                                    <Link key={c.id} href={`/works/${slug}/write/${c.id}`} className={`sq ${cls}`} aria-label={`Chapter ${c.number}${m === 'p' ? ', POV' : m === 'i' ? ', in it' : ''}`} title={c.title}>
                                        {c.number}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
            <div className="pl-lg">
                <span>
                    <i className="sq p" />
                    POV
                </span>
                <span>
                    <i className="sq i" />
                    In it
                </span>
                <span>
                    <i className="sq u" />
                    Not written
                </span>
            </div>
        </>
    );
}

/** Delete, asking first. */
function DeleteRow({ label, question, onDelete }: { label: string; question: string; onDelete: () => void }) {
    const [ask, setAsk] = useState(false);

    return (
        <div className="pl-del">
            <button className="w-act" type="button" onClick={() => setAsk(true)}>
                {label}
            </button>
            <Unfold show={ask}>
                <div className="pl-delc">
                    <p>{question}</p>
                    <div className="pl-nb">
                        <button className="k-btn sm danger" type="button" onClick={onDelete}>
                            <span>Delete</span>
                        </button>
                        <button className="w-act" type="button" onClick={() => setAsk(false)}>
                            Cancel
                        </button>
                    </div>
                </div>
            </Unfold>
        </div>
    );
}

/**
 * A work's plan, as approved: novels get Characters, Plot (points on threads, placed in chapters), World and
 * Research; stories, essays and collections get Notes and Research. Everything saves as she types.
 */
export default function PlanTab({ slug, kind, initial, setSave, parts }: { slug: string; kind: string; initial: Plan; setSave: (s: SaveState) => void; parts: PartLook }) {
    LOOK = parts;
    const [plan, setPlan] = useState(initial);
    const q0 = new URLSearchParams(window.location.search);
    const [sec, setSec] = useState(() => (initial.sections.includes(q0.get('s') ?? '') ? q0.get('s')! : initial.sections[0]));
    const [open, setOpen] = useState<number | null>(() => (q0.get('e') ? Number(q0.get('e')) : null));
    const [query, setQuery] = useState('');
    const [tocOpen, setTocOpen] = useState(false);
    const [phoneSearch, setPhoneSearch] = useState(false);
    const main = useRef<HTMLDivElement>(null);
    const base = `/works/${slug}/plan`;

    async function api(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: Record<string, unknown>): Promise<Plan | null> {
        setSave('saving');
        const { status, data } = await json<{ plan: Plan; errors: Record<string, string[]> }>(method, `${base}/${path}`, body);
        if ((status !== 200 && status !== 201) || !data.plan) {
            setSave('bad');

            return null;
        }
        setPlan(data.plan);
        setSave('saved');

        return data.plan;
    }

    async function upload(kind: 'img' | 'file', file: File): Promise<Plan | null> {
        const form = new FormData();
        form.append('kind', kind);
        form.append('file', file);
        setSave('saving');
        const res = await fetch(`${base}/research`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: form }).catch(() => null);
        const data = res ? await res.json().catch(() => ({})) : {};
        if (!res?.ok || !data.plan) {
            setSave('bad');

            return null;
        }
        setPlan(data.plan);
        setSave('saved');

        return data.plan;
    }

    /* the address follows what's open; each change settles in */
    function go(s: string, e: number | null = null) {
        setSec(s);
        setOpen(e);
        setQuery('');
        setTocOpen(false);
        router.replace({ url: `/works/${slug}?tab=plan&s=${s}${e ? `&e=${e}` : ''}`, preserveScroll: true, preserveState: true });
    }
    useEffect(() => {
        const el = main.current;
        if (!el) {
            return;
        }
        [...el.children].forEach((c, i) => (c as HTMLElement).style.setProperty('--k', String(i)));
        el.classList.remove('settle');
        void el.offsetWidth;
        el.classList.add('settle');
    }, [sec, open, query === '']);

    const counts: Record<string, number | null> = {
        ch: plan.chars.length,
        plot: plan.points.length,
        world: plan.groups.reduce((a, g) => a + g.entries.length, 0),
        res: plan.research.length || null,
        notes: null,
    };
    const toc = plan.sections.map((s) => (
        <a
            key={s}
            href="#"
            className={sec === s && !query ? 'on' : undefined}
            aria-current={sec === s && !query ? 'true' : undefined}
            onClick={(e) => {
                e.preventDefault();
                go(s);
            }}
        >
            <span>{NAMES[s]}</span>
            {counts[s] ? <small>{counts[s]}</small> : null}
        </a>
    ));
    const searchBox = (id: string) => (
        <label className={`pl-q${query ? ' has' : ''}`}>
            <span className="sr">Search the plan</span>
            <Search />
            <input className="pl-qi" id={id} value={query} placeholder="Search the plan" autoComplete="off" onChange={(e) => setQuery(e.target.value)} />
            <button className="ad-ic pl-qx" type="button" aria-label="Clear search" hidden={!query} onClick={() => setQuery('')}>
                <Close size={16} />
            </button>
        </label>
    );

    let body: ReactNode;
    if (query.trim()) {
        body = <SearchView plan={plan} q={query.trim()} go={go} />;
    } else if (sec === 'notes') {
        body = <NotesField key="pnotes" html={plan.notes} big label="Notes" placeholder={`Notes for this ${kind === 'collection' ? 'collection' : kind}`} onSave={(h) => api('PATCH', 'notes', { html: h })} />;
    } else if (sec === 'ch') {
        const c = plan.chars.find((x) => x.id === open);
        body = c ? <CharView key={c.id} c={c} plan={plan} slug={slug} api={api} go={go} /> : <CharsView plan={plan} api={api} go={go} />;
    } else if (sec === 'world') {
        const g = plan.groups.find((x) => x.entries.some((e) => e.id === open));
        const e = g?.entries.find((x) => x.id === open);
        body = e && g ? <EntryView key={e.id} e={e} group={g.id} plan={plan} slug={slug} api={api} go={go} /> : <WorldView plan={plan} api={api} go={go} />;
    } else if (sec === 'plot') {
        body = <PlotView plan={plan} api={api} />;
    } else {
        const r = plan.research.find((x) => x.id === open);
        body = r ? <ResView key={r.id} r={r} plan={plan} api={api} go={go} /> : <ResList plan={plan} api={api} upload={upload} go={go} />;
    }

    return (
        <div className="pl" id="plRoot">
            <div className="pl-ph">
                <button className="b-toc-ph pl-tocph" type="button" aria-expanded={tocOpen} onClick={() => setTocOpen(!tocOpen)}>
                    <span>{NAMES[sec]}</span>
                    <Chevron />
                </button>
                <button className="ad-ic pl-qb" type="button" aria-label="Search the plan" onClick={() => setPhoneSearch(!phoneSearch)}>
                    <Search />
                </button>
            </div>
            <Unfold show={tocOpen} className="pl-tocf">
                <nav className="pl-tocm" aria-label="Plan">
                    {toc}
                </nav>
            </Unfold>
            <Unfold show={phoneSearch} className="pl-qf">
                {searchBox('plQ2')}
            </Unfold>
            <div className="pl-set">
                <div className="pl-side">
                    {searchBox('plQ')}
                    <nav className="b-toc pl-toc" aria-label="Plan">
                        {toc}
                    </nav>
                </div>
                <div className="pl-main" id="plMain" ref={main}>
                    {body}
                </div>
            </div>
        </div>
    );
}

type Api = (method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: Record<string, unknown>) => Promise<Plan | null>;
type Go = (s: string, e?: number | null) => void;

function Head({ meta, children }: { meta: string; children?: ReactNode }) {
    return (
        <div className="pl-hd">
            <span className="k-meta">{meta}</span>
            <div className="acts">{children}</div>
        </div>
    );
}

function Act({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button className="k-l pl-a" type="button" onClick={onClick}>
            <Plus />
            <span>{label}</span>
        </button>
    );
}

/** "Name" + the plain form Sweep will look for, and the tone-mark row. */
function NewName({ show, placeholder, groups, onCreate, onCancel }: { show: boolean; placeholder: string; groups?: Plan['groups']; onCreate: (name: string, group?: number) => void; onCancel: () => void }) {
    const [name, setName] = useState('');
    const [group, setGroup] = useState(groups?.[0]?.id ?? 0);
    useEffect(() => {
        if (show) {
            setName('');
            window.setTimeout(() => document.getElementById('plNI')?.focus(), prefersReducedMotion() ? 0 : 200);
        }
    }, [show]);
    const done = () => name.trim() && onCreate(name.trim(), group);
    const plain = plainName(name.trim());

    return (
        <Unfold show={show} className="pl-newu" id="plNew">
            <div className="pl-new">
                <div className="k-f">
                    <label className="k-fl" htmlFor="plNI">
                        Name
                    </label>
                    <NameInput id="plNI" value={name} onChange={setName} placeholder={placeholder} onEnter={done} />
                </div>
                <div className="pl-sw" id="plNS" aria-live="polite">
                    {plain && plain !== name.trim() ? (
                        <span>
                            Typed as <b>{plain}</b>
                        </span>
                    ) : null}
                </div>
                {groups && (
                    <div className="k-f">
                        <span className="k-fl" id="plNGL">
                            Group
                        </span>
                        <KSelect id="plNG" labelledBy="plNGL" value={String(group)} options={groups.map((g) => ({ value: String(g.id), label: g.name }))} onPick={(v) => setGroup(Number(v))} />
                    </div>
                )}
                <Accents target="plNI" />
                <div className="pl-nb">
                    <button className="k-btn sm" type="button" onClick={done}>
                        <span>Create</span>
                    </button>
                    <button className="w-act" type="button" onClick={onCancel}>
                        Cancel
                    </button>
                </div>
            </div>
        </Unfold>
    );
}

/* ---------------------------------------------------------------- characters */
function CharsView({ plan, api, go }: { plan: Plan; api: Api; go: Go }) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head meta={`${plan.chars.length} ${plan.chars.length === 1 ? 'character' : 'characters'}`}>
                <Act label="New character" onClick={() => setAdding(!adding)} />
            </Head>
            <NewName
                show={adding}
                placeholder="e.g. Xu2 Wei1"
                onCancel={() => setAdding(false)}
                onCreate={async (name) => {
                    const p = await api('POST', 'characters', { name });
                    const made = p?.chars[p.chars.length - 1];
                    setAdding(false);
                    if (made) {
                        go('ch', made.id);
                    }
                }}
            />
            {plan.chars.length ? (
                <div className="pl-rows" id="plRows">
                    {plan.chars.map((c) => (
                        <a
                            key={c.id}
                            href="#"
                            className="pl-row"
                            onClick={(e) => {
                                e.preventDefault();
                                go('ch', c.id);
                            }}
                        >
                            <span className="nm">{c.name}</span>
                            <span className="ty">{c.typedAs && c.typedAs !== c.name && plainName(c.name) !== c.typedAs ? c.typedAs : ''}</span>
                            <span className="rl">{c.role}</span>
                            <span className="k-fact pv">
                                {c.pov.length ? (
                                    <>
                                        <PovIcon />
                                        {c.pov.length}
                                    </>
                                ) : null}
                            </span>
                            <span className="k-fact in">{new Set([...c.pov, ...c.in]).size} ch.</span>
                        </a>
                    ))}
                </div>
            ) : (
                <p className="pl-empty">No characters yet.</p>
            )}
        </>
    );
}

function CharView({ c, plan, slug, api, go }: { c: Char; plan: Plan; slug: string; api: Api; go: Go }) {
    const [name, setName] = useState(c.name);
    const [typed, setTyped] = useState(c.typedAs || plainName(c.name));
    const [role, setRole] = useState(c.role);
    const [aka, setAka] = useState(c.aliases);
    const [akaOpen, setAkaOpen] = useState(false);
    const [akaText, setAkaText] = useState('');
    const [relOpen, setRelOpen] = useState(false);
    const others = plan.chars.filter((o) => o.id !== c.id && !c.links.some((l) => l.to === o.id));
    const [relWho, setRelWho] = useState(others[0]?.id ?? 0);
    const [relHow, setRelHow] = useState('');
    const timers = useRef<Record<string, number>>({});
    const later = (key: string, body: Record<string, unknown>) => {
        window.clearTimeout(timers.current[key]);
        timers.current[key] = window.setTimeout(() => api('PATCH', `characters/${c.id}`, body), 500);
    };
    const pov = new Set(c.pov);
    const inIt = new Set(c.in);

    return (
        <>
            <a
                className="k-back pl-back"
                href="#"
                onClick={(e) => {
                    e.preventDefault();
                    go('ch');
                }}
            >
                <BackSmall />
                Characters
            </a>
            <h2 className="pl-h" id="pfH">
                {name || 'New character'}
            </h2>
            <div className="pl-two">
                <div className="pl-l">
                    <div className="pl-fs">
                        <div className="k-f">
                            <label className="k-fl" htmlFor="pfN">
                                Name
                            </label>
                            <NameInput
                                id="pfN"
                                field="n"
                                value={name}
                                onChange={(v) => {
                                    setName(v);
                                    if (v.trim()) {
                                        later('name', { name: v.trim() });
                                    }
                                }}
                            />
                        </div>
                        <div className="k-f">
                            <label className="k-fl" htmlFor="pfT">
                                Typed as
                            </label>
                            <NameInput
                                id="pfT"
                                field="t"
                                value={typed}
                                onChange={(v) => {
                                    setTyped(v);
                                    later('typed', { typedAs: v.trim() || null });
                                }}
                            />
                        </div>
                        <div className="k-f wide">
                            <span className="k-fl">Also called</span>
                            <div className="pl-chips" id="pfA">
                                {aka.map((a, i) => (
                                    <span className="pl-chip" key={a + i}>
                                        {a}
                                        <button
                                            type="button"
                                            aria-label={`Remove ${a}`}
                                            onClick={() => {
                                                const next = aka.filter((_, k) => k !== i);
                                                setAka(next);
                                                api('PATCH', `characters/${c.id}`, { aliases: next });
                                            }}
                                        >
                                            <Close size={14} width={2} />
                                        </button>
                                    </span>
                                ))}
                                <span className="pl-akaw">
                                    {!akaOpen && (
                                        <button className="pl-chip lk pl-add" type="button" onClick={() => setAkaOpen(true)}>
                                            <Plus />
                                            Add
                                        </button>
                                    )}
                                    {akaOpen && (
                                        <span className="pl-akai">
                                            <input
                                                id="pfAI"
                                                autoFocus
                                                placeholder="e.g. A-Wei1"
                                                autoComplete="off"
                                                spellCheck={false}
                                                aria-label="Also called"
                                                value={akaText}
                                                onChange={(e) => setAkaText(/[A-Za-zÜüVv][1-5]/.test(e.target.value) ? tone(e.target.value) : e.target.value)}
                                                onBlur={() => !akaText.trim() && setAkaOpen(false)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Escape') {
                                                        setAkaOpen(false);
                                                    }
                                                    if (e.key === 'Enter' && akaText.trim()) {
                                                        e.preventDefault();
                                                        const next = [...aka, akaText.trim()];
                                                        setAka(next);
                                                        setAkaText('');
                                                        api('PATCH', `characters/${c.id}`, { aliases: next });
                                                    }
                                                }}
                                            />
                                        </span>
                                    )}
                                </span>
                            </div>
                        </div>
                        <div className="k-f wide">
                            <label className="k-fl" htmlFor="pfR">
                                Role
                            </label>
                            <span className="k-in">
                                <input
                                    id="pfR"
                                    value={role}
                                    autoComplete="off"
                                    onChange={(e) => {
                                        setRole(e.target.value);
                                        later('role', { role: e.target.value });
                                    }}
                                />
                            </span>
                        </div>
                    </div>
                    <div className="k-f pl-nf">
                        <span className="k-fl" id="pfNL">
                            Notes
                        </span>
                        <NotesField html={c.notes} labelledBy="pfNL" placeholder="Anything about them" onSave={(h) => api('PATCH', `characters/${c.id}`, { notes: h })} />
                    </div>
                </div>
                <div className="pl-r">
                    <section className="pl-blk">
                        <div className="pl-bh">
                            <h3>Chapters</h3>
                            <span className="k-meta">
                                POV {c.pov.length} · in {new Set([...c.pov, ...c.in]).size}
                            </span>
                        </div>
                        <Strip chapters={plan.chapters} slug={slug} mark={(id) => (pov.has(id) ? 'p' : inIt.has(id) ? 'i' : '')} />
                    </section>
                    <section className="pl-blk">
                        <div className="pl-bh">
                            <h3>Relationships</h3>
                        </div>
                        <div className="pl-rels" id="pfRels">
                            {c.links.map((l) => {
                                const o = plan.chars.find((x) => x.id === l.to);

                                return o ? (
                                    <div className="pl-rel" key={l.id}>
                                        <a
                                            href="#"
                                            className="nm"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                go('ch', o.id);
                                            }}
                                        >
                                            {o.name}
                                        </a>
                                        <RelLabel initial={l.label} onSave={(v) => api('PATCH', `links/${l.id}`, { label: v })} />
                                        <button className="ad-ic" type="button" aria-label={`Remove ${o.name}`} onClick={() => api('DELETE', `links/${l.id}`)}>
                                            <Close size={16} />
                                        </button>
                                    </div>
                                ) : null;
                            })}
                            {!c.links.length && <p className="pl-none">No relationships yet.</p>}
                        </div>
                        {others.length > 0 && (
                            <button className="w-act" type="button" onClick={() => setRelOpen(!relOpen)}>
                                <Plus />
                                Add a relationship
                            </button>
                        )}
                        <Unfold show={relOpen} id="plRelU">
                            <div className="pl-reladd">
                                <div className="k-f">
                                    <span className="k-fl" id="plRelL0">
                                        Who
                                    </span>
                                    <KSelect id="plRelS" labelledBy="plRelL0" value={String(relWho)} options={others.map((o) => ({ value: String(o.id), label: o.name }))} onPick={(v) => setRelWho(Number(v))} />
                                </div>
                                <div className="k-f">
                                    <label className="k-fl" htmlFor="plRelL">
                                        How
                                    </label>
                                    <span className="k-in">
                                        <input id="plRelL" placeholder="e.g. Older brother" autoComplete="off" value={relHow} onChange={(e) => setRelHow(e.target.value)} />
                                    </span>
                                </div>
                                <div className="pl-nb">
                                    <button
                                        className="k-btn sm"
                                        type="button"
                                        onClick={async () => {
                                            if (relWho && (await api('POST', 'links', { character: c.id, to: relWho, label: relHow.trim() || null }))) {
                                                setRelOpen(false);
                                                setRelHow('');
                                            }
                                        }}
                                    >
                                        <span>Add</span>
                                    </button>
                                    <button className="w-act" type="button" onClick={() => setRelOpen(false)}>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </Unfold>
                    </section>
                </div>
            </div>
            <DeleteRow label="Delete this character" question={`Delete ${c.name} from the plan?`} onDelete={async () => (await api('DELETE', `characters/${c.id}`)) && go('ch')} />
        </>
    );
}

function RelLabel({ initial, onSave }: { initial: string; onSave: (v: string) => void }) {
    const el = useRef<HTMLSpanElement>(null);
    const timer = useRef(0);
    useLayoutEffect(() => {
        if (el.current) {
            el.current.textContent = initial;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <span
            ref={el}
            className="lb"
            contentEditable="plaintext-only"
            suppressContentEditableWarning
            data-ph="How"
            spellCheck
            onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), el.current?.blur())}
            onInput={() => {
                window.clearTimeout(timer.current);
                timer.current = window.setTimeout(() => onSave(el.current?.textContent?.trim() ?? ''), 500);
            }}
        />
    );
}

/* ---------------------------------------------------------------- world */
function WorldView({ plan, api, go }: { plan: Plan; api: Api; go: Go }) {
    const [adding, setAdding] = useState(false);
    const [newGroup, setNewGroup] = useState(false);
    const [gName, setGName] = useState('');
    const [renaming, setRenaming] = useState<number | null>(null);
    const [rename, setRename] = useState('');
    const [deleting, setDeleting] = useState<number | null>(null);
    const n = plan.groups.reduce((a, g) => a + g.entries.length, 0);

    return (
        <>
            <Head meta={`${n} ${n === 1 ? 'entry' : 'entries'}`}>
                <Act label="New entry" onClick={() => (setAdding(!adding), setNewGroup(false))} />
                <Act label="New group" onClick={() => (setNewGroup(!newGroup), setAdding(false), setGName(''))} />
            </Head>
            <NewName
                show={adding}
                placeholder="e.g. Ping2jiang1"
                groups={plan.groups}
                onCancel={() => setAdding(false)}
                onCreate={async (name, group) => {
                    const p = await api('POST', 'entries', { name, group });
                    setAdding(false);
                    const made = p?.groups.find((g) => g.id === group)?.entries.slice(-1)[0];
                    if (made) {
                        go('world', made.id);
                    }
                }}
            />
            <Unfold show={newGroup} id="plGU">
                <div className="pl-new">
                    <div className="k-f">
                        <label className="k-fl" htmlFor="plGI">
                            Group name
                        </label>
                        <span className="k-in">
                            <input id="plGI" autoComplete="off" placeholder="e.g. Food" value={gName} onChange={(e) => setGName(e.target.value)} />
                        </span>
                    </div>
                    <div className="pl-nb">
                        <button className="k-btn sm" type="button" onClick={async () => gName.trim() && (await api('POST', 'groups', { name: gName.trim() })) && setNewGroup(false)}>
                            <span>Create</span>
                        </button>
                        <button className="w-act" type="button" onClick={() => setNewGroup(false)}>
                            Cancel
                        </button>
                    </div>
                </div>
            </Unfold>
            {plan.groups.map((g) => {
                const next = plan.groups.find((x) => x.id !== g.id);

                return (
                    <div key={g.id}>
                        <div className="pl-grp g">
                            <h3>{g.name}</h3>
                            <span className="k-meta">{g.entries.length || ''}</span>
                            <span className="pl-ga">
                                <button className="w-act" type="button" onClick={() => (setRenaming(renaming === g.id ? null : g.id), setRename(g.name))}>
                                    Rename
                                </button>
                                {plan.groups.length > 1 && (
                                    <button className="w-act" type="button" onClick={() => setDeleting(deleting === g.id ? null : g.id)}>
                                        Delete
                                    </button>
                                )}
                            </span>
                        </div>
                        <Unfold show={renaming === g.id}>
                            <div className="pl-new sm">
                                <div className="k-f">
                                    <label className="k-fl" htmlFor={`plGRI-${g.id}`}>
                                        New name
                                    </label>
                                    <span className="k-in">
                                        <input id={`plGRI-${g.id}`} value={rename} autoComplete="off" onChange={(e) => setRename(e.target.value)} />
                                    </span>
                                </div>
                                <div className="pl-nb">
                                    <button className="k-btn sm" type="button" onClick={async () => rename.trim() && (await api('PATCH', `groups/${g.id}`, { name: rename.trim() })) && setRenaming(null)}>
                                        <span>Save</span>
                                    </button>
                                    <button className="w-act" type="button" onClick={() => setRenaming(null)}>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </Unfold>
                        <Unfold show={deleting === g.id}>
                            <div className="pl-delc">
                                <p>
                                    Delete {g.name}?{g.entries.length && next ? ` ${g.entries.length === 1 ? 'Its entry moves' : 'Its entries move'} to ${next.name}.` : ''}
                                </p>
                                <div className="pl-nb">
                                    <button className="k-btn sm danger" type="button" onClick={async () => (await api('DELETE', `groups/${g.id}`, { move_to: next?.id ?? null })) && setDeleting(null)}>
                                        <span>Delete</span>
                                    </button>
                                    <button className="w-act" type="button" onClick={() => setDeleting(null)}>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </Unfold>
                        {g.entries.length ? (
                            <div className="pl-rows">
                                {g.entries.map((e) => (
                                    <a
                                        key={e.id}
                                        href="#"
                                        className="pl-row w"
                                        onClick={(ev) => {
                                            ev.preventDefault();
                                            go('world', e.id);
                                        }}
                                    >
                                        <span className="nm">{e.name}</span>
                                        <span className="ty">{e.typedAs && plainName(e.name) !== e.typedAs ? e.typedAs : ''}</span>
                                        <span className="rl">{e.description}</span>
                                        <span className="k-fact pv" />
                                        <span className="k-fact in">{e.in.length} ch.</span>
                                    </a>
                                ))}
                            </div>
                        ) : (
                            <p className="pl-empty sm">Nothing here yet.</p>
                        )}
                    </div>
                );
            })}
        </>
    );
}

function EntryView({ e, group, plan, slug, api, go }: { e: Entry; group: number; plan: Plan; slug: string; api: Api; go: Go }) {
    const [name, setName] = useState(e.name);
    const [typed, setTyped] = useState(e.typedAs || plainName(e.name));
    const [desc, setDesc] = useState(e.description);
    const timers = useRef<Record<string, number>>({});
    const later = (key: string, body: Record<string, unknown>) => {
        window.clearTimeout(timers.current[key]);
        timers.current[key] = window.setTimeout(() => api('PATCH', `entries/${e.id}`, body), 500);
    };
    const inIt = new Set(e.in);

    return (
        <>
            <a
                className="k-back pl-back"
                href="#"
                onClick={(ev) => {
                    ev.preventDefault();
                    go('world');
                }}
            >
                <BackSmall />
                World
            </a>
            <h2 className="pl-h" id="pfH">
                {name || 'New entry'}
            </h2>
            <div className="pl-two">
                <div className="pl-l">
                    <div className="pl-fs">
                        <div className="k-f">
                            <label className="k-fl" htmlFor="pfN">
                                Name
                            </label>
                            <NameInput id="pfN" field="n" value={name} onChange={(v) => (setName(v), v.trim() && later('name', { name: v.trim() }))} />
                        </div>
                        <div className="k-f">
                            <label className="k-fl" htmlFor="pfT">
                                Typed as
                            </label>
                            <NameInput id="pfT" field="t" value={typed} onChange={(v) => (setTyped(v), later('typed', { typedAs: v.trim() || null }))} />
                        </div>
                        <div className="k-f">
                            <span className="k-fl" id="pfGL">
                                Group
                            </span>
                            <KSelect id="pfG" labelledBy="pfGL" value={String(group)} options={plan.groups.map((g) => ({ value: String(g.id), label: g.name }))} onPick={(v) => api('PATCH', `entries/${e.id}`, { group: Number(v) })} />
                        </div>
                        <div className="k-f">
                            <label className="k-fl" htmlFor="pfD">
                                Short line
                            </label>
                            <span className="k-in">
                                <input id="pfD" value={desc} autoComplete="off" onChange={(ev) => (setDesc(ev.target.value), later('desc', { description: ev.target.value || null }))} />
                            </span>
                        </div>
                    </div>
                    <div className="k-f pl-nf">
                        <span className="k-fl" id="pfNL">
                            Notes
                        </span>
                        <NotesField html={e.notes} labelledBy="pfNL" placeholder="Anything about it" onSave={(h) => api('PATCH', `entries/${e.id}`, { notes: h })} />
                    </div>
                </div>
                <div className="pl-r">
                    <section className="pl-blk">
                        <div className="pl-bh">
                            <h3>Chapters</h3>
                            <span className="k-meta">in {e.in.length}</span>
                        </div>
                        <Strip chapters={plan.chapters} slug={slug} mark={(id) => (inIt.has(id) ? 'i' : '')} />
                    </section>
                </div>
            </div>
            <DeleteRow label="Delete this entry" question={`Delete ${e.name} from the plan?`} onDelete={async () => (await api('DELETE', `entries/${e.id}`)) && go('world')} />
        </>
    );
}

/* ---------------------------------------------------------------- plot: points on threads, placed in chapters */
function PointText({ initial, onSave, autoFocus }: { initial: string; onSave: (v: string) => void; autoFocus: boolean }) {
    const el = useRef<HTMLParagraphElement>(null);
    const timer = useRef(0);
    useLayoutEffect(() => {
        if (el.current) {
            el.current.textContent = initial;
            if (autoFocus) {
                el.current.focus();
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <p
            ref={el}
            className="x"
            contentEditable="plaintext-only"
            suppressContentEditableWarning
            data-ph="What happens"
            spellCheck
            onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), el.current?.blur())}
            onInput={() => {
                window.clearTimeout(timer.current);
                timer.current = window.setTimeout(() => onSave(el.current?.textContent?.trim() ?? ''), 500);
            }}
        />
    );
}

function PlotView({ plan, api }: { plan: Plan; api: Api }) {
    const [follow, setFollow] = useState<number | null>(null);
    const [threadForm, setThreadForm] = useState(false);
    const [tName, setTName] = useState('');
    const [folded, setFolded] = useState<Record<string, boolean>>({});
    const [fresh, setFresh] = useState<number | null>(null);
    const pts = plan.points.filter((p) => !follow || p.thread === follow);
    const order = new Map(plan.chapters.map((c, i) => [c.id, i]));
    const groups: { key: string; part: string | null; label: ReactNode; points: Plan['points'] }[] = [];
    const sorted = pts.slice().sort((a, b) => (a.item === null ? 1e9 : order.get(a.item) ?? 1e9) - (b.item === null ? 1e9 : order.get(b.item) ?? 1e9));
    sorted.forEach((p) => {
        const ch = plan.chapters.find((c) => c.id === p.item);
        const key = ch ? `p:${ch.partNumber ?? 0}` : 'none';
        let g = groups.find((x) => x.key === key);
        if (!g) {
            g = { key, part: ch?.part ?? null, label: ch?.partNumber && LOOK.show ? heading(ch.partNumber, LOOK.word || 'Part', LOOK.style) : null, points: [] };
            groups.push(g);
        }
        g.points.push(p);
    });
    const thread = (id: number) => plan.threads.find((t) => t.id === id) ?? plan.threads[0];
    const chOptions = [{ value: '', label: 'No chapter' }, ...plan.chapters.map((c) => ({ value: String(c.id), label: `${c.number} · ${c.title || `Chapter ${c.number}`}` }))];

    return (
        <>
            <Head meta={`${plan.points.length} ${plan.points.length === 1 ? 'point' : 'points'} · ${plan.threads.length} ${plan.threads.length === 1 ? 'thread' : 'threads'}`}>
                <Act
                    label="New point"
                    onClick={async () => {
                        const p = await api('POST', 'points', { thread: follow ?? plan.threads[0]?.id, item: null, text: '…' });
                        const made = p?.points[p.points.length - 1];
                        if (made) {
                            setFresh(made.id);
                        }
                    }}
                />
                <Act label="New thread" onClick={() => (setThreadForm(!threadForm), setTName(''))} />
            </Head>
            <div className="k-pills pl-pills pl-ths pl-strip1" role="radiogroup" aria-label="Threads">
                <label className="k-pill">
                    <input type="radio" name="plth" checked={follow === null} onChange={() => setFollow(null)} />
                    <span>All</span>
                </label>
                {plan.threads.map((t) => (
                    <label className="k-pill" key={t.id}>
                        <input type="radio" name="plth" checked={follow === t.id} onChange={() => setFollow(t.id)} />
                        <span>
                            <i className="pl-td" style={{ '--tc': COLS[t.colour % 6] } as React.CSSProperties} />
                            {t.name}
                            <small>{plan.points.filter((p) => p.thread === t.id).length}</small>
                        </span>
                    </label>
                ))}
            </div>
            <Unfold show={threadForm} id="plTU">
                <div className="pl-new">
                    <div className="k-f">
                        <label className="k-fl" htmlFor="plTI">
                            Thread name
                        </label>
                        <span className="k-in">
                            <input id="plTI" autoComplete="off" placeholder="e.g. The letters" value={tName} onChange={(e) => setTName(e.target.value)} />
                        </span>
                    </div>
                    <div className="pl-nb">
                        <button className="k-btn sm" type="button" onClick={async () => tName.trim() && (await api('POST', 'threads', { name: tName.trim() })) && setThreadForm(false)}>
                            <span>Create</span>
                        </button>
                        <button className="w-act" type="button" onClick={() => setThreadForm(false)}>
                            Cancel
                        </button>
                    </div>
                </div>
            </Unfold>
            {!pts.length && <p className="pl-empty">No plot points yet.</p>}
            {groups.map((g) => (
                <div key={g.key}>
                    <div className={`pl-part${folded[g.key] ? ' fold' : ''}`}>
                        <button className="fb" type="button" aria-expanded={!folded[g.key]} aria-label="Fold" onClick={() => setFolded({ ...folded, [g.key]: !folded[g.key] })}>
                            <FoldIcon />
                        </button>
                        {g.label && <span className="n">{g.label}</span>}
                        {g.key === 'none' ? <h3 className="un">Not in a chapter yet</h3> : g.part ? <h3>{g.part}</h3> : <h3 className="un">Chapters</h3>}
                        <span className="k-meta">
                            {g.points.length} {g.points.length === 1 ? 'point' : 'points'}
                        </span>
                    </div>
                    <Unfold show={!folded[g.key]} className="pl-gfu">
                        <div className="pl-bts">
                            {g.points.map((p) => {
                                const t = thread(p.thread);

                                return (
                                    <div className="pl-bt" key={p.id} style={{ '--tc': COLS[(t?.colour ?? 0) % 6] } as React.CSSProperties} tabIndex={-1}>
                                        <KSelect
                                            className="pl-ths1"
                                            buttonClassName="k-sbtn bar"
                                            label={`Thread: ${t?.name ?? ''}`}
                                            value={String(p.thread)}
                                            display={<span className="v sr">{t?.name}</span>}
                                            options={plan.threads.map((x) => ({ value: String(x.id), label: x.name }))}
                                            onPick={(v) => api('PATCH', `points/${p.id}`, { thread: Number(v) })}
                                        />
                                        <PointText initial={p.text === '…' ? '' : p.text} autoFocus={fresh === p.id} onSave={(v) => api('PATCH', `points/${p.id}`, { text: v || '…' })} />
                                        <KSelect
                                            className="pl-chs"
                                            buttonClassName="k-sbtn"
                                            label="Chapter"
                                            value={p.item ? String(p.item) : ''}
                                            display={
                                                <>
                                                    <span className="v">{p.item ? `Ch. ${plan.chapters.find((c) => c.id === p.item)?.number ?? ''}` : 'No chapter'}</span>
                                                    <Chevron />
                                                </>
                                            }
                                            options={chOptions}
                                            onPick={(v) => api('PATCH', `points/${p.id}`, { item: v ? Number(v) : null })}
                                        />
                                        <button className="ad-ic pl-bx" type="button" aria-label="Delete point" onClick={() => api('DELETE', `points/${p.id}`)}>
                                            <Close size={16} />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    </Unfold>
                </div>
            ))}
            {follow && plan.threads.length > 1 && (
                <ThreadTools key={follow} thread={thread(follow)!} api={api} onGone={() => setFollow(null)} />
            )}
        </>
    );
}

/** The thread being followed: rename, colour, delete (its points move to another thread). */
function ThreadTools({ thread, api, onGone }: { thread: Plan['threads'][number]; api: Api; onGone: () => void }) {
    const [name, setName] = useState(thread.name);
    const timer = useRef(0);

    return (
        <div className="pl-new sm">
            <div className="k-f">
                <label className="k-fl" htmlFor="plTN">
                    Thread name
                </label>
                <span className="k-in">
                    <input
                        id="plTN"
                        value={name}
                        autoComplete="off"
                        onChange={(e) => {
                            setName(e.target.value);
                            window.clearTimeout(timer.current);
                            const v = e.target.value.trim();
                            timer.current = window.setTimeout(() => v && api('PATCH', `threads/${thread.id}`, { name: v }), 500);
                        }}
                    />
                </span>
            </div>
            <DeleteRow label="Delete this thread" question={`Delete ${thread.name}? Its points move to another thread.`} onDelete={async () => (await api('DELETE', `threads/${thread.id}`)) && onGone()} />
        </div>
    );
}

/* ---------------------------------------------------------------- research */
function ResList({ plan, api, upload, go }: { plan: Plan; api: Api; upload: (k: 'img' | 'file', f: File) => Promise<Plan | null>; go: Go }) {
    const [menu, setMenu] = useState(false);
    const [filter, setFilter] = useState<'all' | Res['kind']>('all');
    const [linkForm, setLinkForm] = useState(false);
    const [url, setUrl] = useState('');
    const [busy, setBusy] = useState(false);
    const file = useRef<HTMLInputElement>(null);
    const pending = useRef<'img' | 'file'>('img');
    const shown = plan.research.filter((r) => filter === 'all' || r.kind === filter);
    const opened = (p: Plan | null) => {
        const made = p?.research[p.research.length - 1];
        if (made) {
            go('res', made.id);
        }
    };
    const tieName = (t: string) => {
        const [k, id] = t.split(':');
        const n = Number(id);

        return k === 'c' ? plan.chars.find((c) => c.id === n)?.name : k === 'e' ? plan.groups.flatMap((g) => g.entries).find((e) => e.id === n)?.name : k === 't' ? plan.threads.find((x) => x.id === n)?.name : plan.chapters.find((c) => c.id === n) ? `Ch. ${plan.chapters.find((c) => c.id === n)!.number}` : undefined;
    };

    return (
        <>
            <div className="pl-hd">
                <span className="k-meta">
                    {plan.research.length} {plan.research.length === 1 ? 'item' : 'items'}
                </span>
                <div className="pl-addw">
                    <button className="k-l pl-a" type="button" aria-haspopup="menu" aria-expanded={menu} id="plAdd" onClick={() => setMenu(!menu)}>
                        <Plus />
                        <span>Add</span>
                    </button>
                    <div className={`pl-menu${menu ? ' open' : ''}`} role="menu" id="plMenu" aria-labelledby="plAdd">
                        {(['link', 'img', 'note', 'file'] as const).map((k) => {
                            const [Icon, label] = RK[k];

                            return (
                                <button
                                    key={k}
                                    type="button"
                                    role="menuitem"
                                    tabIndex={menu ? 0 : -1}
                                    onClick={async () => {
                                        setMenu(false);
                                        if (k === 'link') {
                                            setLinkForm(true);
                                            setUrl('');
                                        } else if (k === 'note') {
                                            opened(await api('POST', 'research', { kind: 'note', title: 'New note' }));
                                        } else {
                                            pending.current = k;
                                            if (file.current) {
                                                file.current.accept = k === 'img' ? 'image/jpeg,image/png,image/webp' : 'application/pdf';
                                                file.current.click();
                                            }
                                        }
                                    }}
                                >
                                    <Icon />
                                    <span>{label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
            <input type="file" ref={file} hidden onChange={async (e) => (e.target.files?.[0] && opened(await upload(pending.current, e.target.files[0])), (e.target.value = ''))} />
            <Unfold show={linkForm}>
                <div className="pl-new">
                    <div className="k-f">
                        <label className="k-fl" htmlFor="plLU">
                            Link
                        </label>
                        <span className="k-in">
                            <input id="plLU" placeholder="Paste a link" autoComplete="off" spellCheck={false} inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)} />
                        </span>
                    </div>
                    <div className="pl-nb">
                        <button
                            className={`k-btn sm${busy ? ' busy' : ''}`}
                            type="button"
                            onClick={async () => {
                                const u = url.trim();
                                if (!u) {
                                    return;
                                }
                                setBusy(true);
                                const p = await api('POST', 'research', { kind: 'link', url: /^https?:\/\//i.test(u) ? u : `https://${u}` });
                                setBusy(false);
                                if (p) {
                                    setLinkForm(false);
                                    opened(p);
                                }
                            }}
                        >
                            <span>Add</span>
                        </button>
                        <button className="w-act" type="button" onClick={() => setLinkForm(false)}>
                            Cancel
                        </button>
                    </div>
                </div>
            </Unfold>
            <div className="k-pills pl-pills pl-strip1" role="radiogroup" aria-label="Show">
                {(
                    [
                        ['all', 'All'],
                        ['link', 'Links'],
                        ['img', 'Images'],
                        ['note', 'Notes'],
                        ['file', 'Files'],
                    ] as const
                ).map(([v, l]) => (
                    <label className="k-pill" key={v}>
                        <input type="radio" name="plrf" checked={filter === v} onChange={() => setFilter(v)} />
                        <span>{l}</span>
                    </label>
                ))}
            </div>
            {shown.length ? (
                <div className="pl-ris">
                    {shown.map((r) => {
                        const Icon = RK[r.kind][0];
                        const tie = r.ties.map(tieName).filter(Boolean)[0];

                        return (
                            <a
                                key={r.id}
                                href="#"
                                className="pl-ri"
                                onClick={(e) => {
                                    e.preventDefault();
                                    go('res', r.id);
                                }}
                            >
                                <span className="lead">
                                    {r.kind === 'img' && r.src ? (
                                        <span className="th">
                                            <img src={r.src} alt="" loading="lazy" />
                                        </span>
                                    ) : (
                                        <span className="th ic">
                                            <Icon />
                                        </span>
                                    )}
                                </span>
                                <span className="t">{r.title || 'Untitled'}</span>
                                {r.kind === 'link' ? (
                                    <span className="k-fact">
                                        <ExtIcon />
                                        {host(r.url)}
                                    </span>
                                ) : (
                                    <span className="k-meta">{r.kind === 'file' ? (r.size ? size(r.size) : 'File') : RK[r.kind][1]}</span>
                                )}
                                {tie ? <span className="pl-tie">{tie}</span> : <span />}
                            </a>
                        );
                    })}
                </div>
            ) : (
                <p className="pl-empty">Nothing saved yet.</p>
            )}
        </>
    );
}

function ResView({ r, plan, api, go }: { r: Res; plan: Plan; api: Api; go: Go }) {
    const [title, setTitle] = useState(r.title);
    const [url, setUrl] = useState(r.url);
    const timers = useRef<Record<string, number>>({});
    const later = (key: string, body: Record<string, unknown>) => {
        window.clearTimeout(timers.current[key]);
        timers.current[key] = window.setTimeout(() => api('PATCH', `research/${r.id}`, body), 500);
    };
    const options: { value: string; label: string; kind: string }[] = [
        ...plan.chars.map((c) => ({ value: `c:${c.id}`, label: c.name, kind: 'Character' })),
        ...plan.groups.flatMap((g) => g.entries.map((e) => ({ value: `e:${e.id}`, label: e.name, kind: g.name }))),
        ...plan.threads.map((t) => ({ value: `t:${t.id}`, label: t.name, kind: 'Thread' })),
        ...plan.chapters.map((c) => ({ value: `i:${c.id}`, label: c.title || `Chapter ${c.number}`, kind: `Ch. ${c.number}` })),
    ];
    const name = (t: string) => options.find((o) => o.value === t)?.label;
    const free = options.filter((o) => !r.ties.includes(o.value));
    const Icon = RK[r.kind][0];

    return (
        <>
            <a
                className="k-back pl-back"
                href="#"
                onClick={(e) => {
                    e.preventDefault();
                    go('res');
                }}
            >
                <BackSmall />
                Research
            </a>
            <h2 className="pl-h sm" id="pfH">
                {title || `New ${RK[r.kind][1].toLowerCase()}`}
            </h2>
            <div className="pl-fs one">
                {r.kind === 'link' && (
                    <div className="k-f">
                        <label className="k-fl" htmlFor="pfU">
                            Link
                        </label>
                        <span className="k-in">
                            <input id="pfU" value={url} placeholder="Paste a link" autoComplete="off" spellCheck={false} inputMode="url" onChange={(e) => (setUrl(e.target.value), /^https?:\/\/\S+$/i.test(e.target.value.trim()) && later('url', { url: e.target.value.trim() }))} />
                        </span>
                        {url && (
                            <a className="k-l pl-open" href={url} target="_blank" rel="noopener noreferrer">
                                <span>Open</span>
                                <ExtIcon />
                            </a>
                        )}
                    </div>
                )}
                {r.kind === 'img' && r.src && (
                    <div className="k-f">
                        <img className="pl-img" src={r.src} alt="" />
                    </div>
                )}
                {r.kind === 'file' && r.src && (
                    <div className="k-f">
                        <a className="k-l pl-open" href={r.src} target="_blank" rel="noopener noreferrer">
                            <Icon />
                            <span>Open {r.size ? `(${size(r.size)})` : ''}</span>
                        </a>
                    </div>
                )}
                <div className="k-f">
                    <label className="k-fl" htmlFor="pfTt">
                        Title
                    </label>
                    <span className="k-in">
                        <input id="pfTt" value={title} autoComplete="off" onChange={(e) => (setTitle(e.target.value), later('title', { title: e.target.value || null }))} />
                    </span>
                </div>
                <div className="k-f">
                    <span className="k-fl">Goes with</span>
                    <div className="pl-chips">
                        {r.ties.map((t) =>
                            name(t) ? (
                                <span className="pl-chip" key={t}>
                                    {name(t)}
                                    <button type="button" aria-label={`Remove ${name(t)}`} onClick={() => api('PATCH', `research/${r.id}`, { ties: r.ties.filter((x) => x !== t) })}>
                                        <Close size={14} width={2} />
                                    </button>
                                </span>
                            ) : null,
                        )}
                        {free.length > 0 && (
                            <KSelect
                                className="pl-tiesel"
                                buttonClassName="k-sbtn"
                                label="Goes with"
                                value="__"
                                display={
                                    <>
                                        <span className="v">
                                            <Plus />
                                            Add
                                        </span>
                                        <Chevron />
                                    </>
                                }
                                options={[{ value: '__', label: 'Add', add: true }, ...free.map((o) => ({ value: o.value, label: `${o.label} · ${o.kind}` }))]}
                                onPick={(v) => v !== '__' && api('PATCH', `research/${r.id}`, { ties: [...r.ties, v] })}
                            />
                        )}
                    </div>
                </div>
            </div>
            <div className="k-f pl-nf">
                <span className="k-fl" id="pfNL">
                    Note
                </span>
                <NotesField html={r.note} labelledBy="pfNL" placeholder="Write it down" onSave={(h) => api('PATCH', `research/${r.id}`, { note: h })} />
            </div>
            <DeleteRow label="Delete" question={`Delete ${title || 'this'} from Research?`} onDelete={async () => (await api('DELETE', `research/${r.id}`)) && go('res')} />
        </>
    );
}

/* ---------------------------------------------------------------- search the whole plan */
function SearchView({ plan, q, go }: { plan: Plan; q: string; go: Go }) {
    const k = fold(q);
    const hit = (...s: string[]) => s.some((x) => x && fold(x).includes(k));
    const chars = plan.chars.filter((c) => hit(c.name, c.typedAs, c.role, textOf(c.notes), ...c.aliases));
    const entries = plan.groups.flatMap((g) => g.entries.map((e) => ({ ...e, group: g.name }))).filter((e) => hit(e.name, e.typedAs, e.description, textOf(e.notes)));
    const points = plan.points.filter((p) => hit(p.text));
    const research = plan.research.filter((r) => hit(r.title, r.url, textOf(r.note)));
    const total = chars.length + entries.length + points.length + research.length;
    const row = (key: string, name: string, sub: string, onClick: () => void) => (
        <a
            key={key}
            href="#"
            className="pl-row"
            onClick={(e) => {
                e.preventDefault();
                onClick();
            }}
        >
            <span className="nm">{name}</span>
            <span className="ty" />
            <span className="rl">{sub}</span>
            <span />
            <span />
        </a>
    );

    return (
        <>
            <div className="pl-hd">
                <span className="k-meta">
                    {total} {total === 1 ? 'result' : 'results'}
                </span>
            </div>
            {!total && (
                <p className="pl-empty">
                    Nothing called <b>{q}</b>.
                </p>
            )}
            {chars.length > 0 && (
                <>
                    <div className="pl-grp g">
                        <h3>Characters</h3>
                    </div>
                    <div className="pl-rows">{chars.map((c) => row(`c${c.id}`, c.name, c.role, () => go('ch', c.id)))}</div>
                </>
            )}
            {entries.length > 0 && (
                <>
                    <div className="pl-grp g">
                        <h3>World</h3>
                    </div>
                    <div className="pl-rows">{entries.map((e) => row(`e${e.id}`, e.name, e.group, () => go('world', e.id)))}</div>
                </>
            )}
            {points.length > 0 && (
                <>
                    <div className="pl-grp g">
                        <h3>Plot</h3>
                    </div>
                    <div className="pl-rows">{points.map((p) => row(`p${p.id}`, p.text, plan.threads.find((t) => t.id === p.thread)?.name ?? '', () => go('plot')))}</div>
                </>
            )}
            {research.length > 0 && (
                <>
                    <div className="pl-grp g">
                        <h3>Research</h3>
                    </div>
                    <div className="pl-rows">{research.map((r) => row(`r${r.id}`, r.title || 'Untitled', RK[r.kind][1], () => go('res', r.id)))}</div>
                </>
            )}
        </>
    );
}


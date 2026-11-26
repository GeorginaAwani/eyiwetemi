import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { inertWhen } from '../../../lib/dom';
import { heading } from '../../../lib/headings';
import { json } from '../../../lib/http';
import { prefersReducedMotion } from '../../../lib/motion';
import { KSelect, loading, Unfold } from '../Controls';
import { Globe, Plus, Ring } from '../Icons';
import { ChapterRow, StateFact, watDay, watTime, watWhen } from './chapters';
import { ChaptersIcon, Clock15, Clock18, VersionsIcon } from './icons';

export type VersionRow = { id: number; label: string; at: string; words: number; added: number; cut: number };
export type PublishState = { state: 'draft' | 'sch' | 'pub' | 'chg' | 'unpub'; scheduledAt: string | null; liveAt: string | null; audience: string; versions: VersionRow[] };
export type Flash = 'pub' | 'upd' | 'sch' | 'unpub' | null;
type Json = { type: string; attrs?: Record<string, unknown>; content?: Json[]; text?: string };

/* ---------------------------------------------------------------- changes between two pages, word by word (as the prototype) */
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

function textOf(n: Json): string {
    if (n.type === 'text') {
        return n.text ?? '';
    }
    if (n.type === 'footnote' || n.type === 'image') {
        return '';
    }
    if (n.type === 'hardBreak') {
        return ' ';
    }

    return (n.content ?? []).map(textOf).join(n.type === 'bulletList' ? ' ' : '');
}

/** A page as its blocks' text; a scene break is ⁂. */
export function blocksOf(doc: Json | null): string[] {
    return (doc?.content ?? []).map((b) => (b.type === 'sceneBreak' ? '⁂' : textOf(b).trim())).filter(Boolean);
}

function lcs<T>(a: T[], b: T[]): [string, T][] | null {
    const n = a.length;
    const m = b.length;
    if (n * m > 2.5e6) {
        return null;
    }
    const d = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            d[i][j] = a[i] === b[j] ? d[i + 1][j + 1] + 1 : Math.max(d[i + 1][j], d[i][j + 1]);
        }
    }
    const ops: [string, T][] = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) {
            ops.push(['=', a[i]]);
            i++;
            j++;
        } else if (d[i + 1][j] >= d[i][j + 1]) {
            ops.push(['-', a[i++]]);
        } else {
            ops.push(['+', b[j++]]);
        }
    }
    while (i < n) {
        ops.push(['-', a[i++]]);
    }
    while (j < m) {
        ops.push(['+', b[j++]]);
    }

    return ops;
}

function wordDiff(a: string, b: string) {
    const A = a.split(/\s+/).filter(Boolean);
    const B = b.split(/\s+/).filter(Boolean);
    const ops = lcs(A, B) ?? [...A.map((w) => ['-', w] as [string, string]), ...B.map((w) => ['+', w] as [string, string])];
    let h = '';
    let cur: string | null = null;
    let buf: string[] = [];
    let add = 0;
    let del = 0;
    const flush = () => {
        if (!buf.length) {
            return;
        }
        const t = esc(buf.join(' '));
        h += (cur === '-' ? `<del>${t}</del>` : cur === '+' ? `<ins>${t}</ins>` : t) + ' ';
        buf = [];
    };
    ops.forEach(([o, w]) => {
        if (o === '+') {
            add++;
        }
        if (o === '-') {
            del++;
        }
        if (o !== cur) {
            flush();
            cur = o;
        }
        buf.push(w);
    });
    flush();

    return { h: h.trim(), add, del };
}

/** The newer page with what changed since the older one marked: cuts struck through, additions in rose. */
export function diff(older: string[], newer: string[]) {
    const ops = lcs(older, newer) ?? [];
    const out: string[] = [];
    let add = 0;
    let del = 0;
    let k = 0;
    while (k < ops.length) {
        if (ops[k][0] === '=') {
            out.push(ops[k][1] === '⁂' ? '<div class="brk r2b"><i></i><b></b><i></i></div>' : `<p>${esc(ops[k][1])}</p>`);
            k++;
            continue;
        }
        const ds: string[] = [];
        const is: string[] = [];
        while (k < ops.length && ops[k][0] !== '=') {
            (ops[k][0] === '-' ? ds : is).push(ops[k][1]);
            k++;
        }
        for (let i = 0; i < Math.max(ds.length, is.length); i++) {
            const r = wordDiff(ds[i] ?? '', is[i] ?? '');
            add += r.add;
            del += r.del;
            out.push(`<p>${r.h}</p>`);
        }
    }

    return { h: out.join(''), add, del };
}

/* ---------------------------------------------------------------- the header's Schedule and Publish */
const DAYS = (): [string, string][] =>
    [...Array(21)].map((_, i) => {
        const d = new Date(Date.now() + (i + 1) * 864e5);
        const iso = d.toLocaleDateString('en-CA', { timeZone: 'Africa/Lagos' });

        return [iso, i === 0 ? 'Tomorrow' : watDay(d.toISOString())];
    });
const TIMES: [string, string][] = [...Array(36)].map((_, i) => {
    const h = 6 + Math.floor(i / 2);
    const m = i % 2 ? '30' : '00';

    return [`${String(h).padStart(2, '0')}:${m}`, `${h % 12 || 12}:${m} ${h < 12 ? 'am' : 'pm'}`];
});

export function PublishControls({
    url,
    part,
    words,
    pub,
    setPub,
    flush,
    flash,
    say,
    label,
}: {
    url: string;
    part: boolean;
    words: number;
    pub: PublishState;
    setPub: (p: PublishState) => void;
    flush: () => Promise<void>;
    flash: (f: Flash) => void;
    say: (t: string) => void;
    label: string;
}) {
    const [kind, setKind] = useState<'' | 'pub' | 'sch'>('');
    const [busy, setBusy] = useState('');
    const [unpubAsk, setUnpubAsk] = useState(false);
    const [day, setDay] = useState('');
    const [time, setTime] = useState('18:00');
    const [err, setErr] = useState('');
    const pop = useRef<HTMLDivElement>(null);
    const s = pub.state;
    const days = DAYS();

    useEffect(() => {
        if (!kind) {
            return;
        }
        setUnpubAsk(false);
        setErr('');
        if (kind === 'sch') {
            const at = s === 'sch' && pub.scheduledAt ? new Date(pub.scheduledAt) : null;
            setDay(at ? at.toLocaleDateString('en-CA', { timeZone: 'Africa/Lagos' }) : days[2][0]);
            setTime(at ? at.toLocaleTimeString('en-GB', { timeZone: 'Africa/Lagos', hour: '2-digit', minute: '2-digit', hour12: false }) : '18:00');
        }
        window.setTimeout(() => pop.current?.focus({ preventScroll: true }), prefersReducedMotion() ? 0 : 60);
        const out = (e: MouseEvent) => {
            const t = e.target as HTMLElement;
            if (!pop.current?.contains(t) && !t.closest('.k-lb') && !t.closest('#pubB,#schB')) {
                setKind('');
            }
        };
        const esc = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !document.querySelector('.k-select.open')) {
                setKind('');
                document.getElementById('pubB')?.focus();
            }
        };
        document.addEventListener('click', out);
        document.addEventListener('keydown', esc);

        return () => {
            document.removeEventListener('click', out);
            document.removeEventListener('keydown', esc);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind]);

    async function act(key: string, method: 'POST' | 'DELETE', path: string, body?: Record<string, unknown>) {
        setBusy(key);
        loading(true);
        await flush();
        const { status, data } = await json<PublishState & { errors: Record<string, string[]> }>(method, `${url}/${path}`, body);
        loading(false);
        setBusy('');
        if (status !== 200) {
            const message = data.errors ? Object.values(data.errors)[0][0] : status === 0 ? 'No connection. Try again.' : 'That didn’t work. Try again.';
            setErr(message);
            if (!kind) {
                say(message);
            }

            return null;
        }
        setPub(data as PublishState);
        setKind('');

        return data as PublishState;
    }

    async function publish() {
        const was = s;
        const done = await act('pub', 'POST', 'publish');
        if (done) {
            flash(was === 'chg' ? 'upd' : 'pub');
            if (!prefersReducedMotion()) {
                document.getElementById('pubB')?.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.04)' }, { transform: 'scale(1)' }], { duration: 420 });
            }
        }
    }

    const pubLabel = part ? (s === 'pub' ? 'Update' : 'Publish') : ({ draft: 'Publish', unpub: 'Publish', sch: 'Publish now', pub: 'Published', chg: 'Update' } as const)[s];
    const showSch = !part && (s === 'draft' || s === 'sch' || s === 'unpub');
    const schText = s === 'sch' && pub.scheduledAt ? `${watDay(pub.scheduledAt)}, ${watTime(pub.scheduledAt)}` : 'Schedule';

    return (
        <>
            {showSch && (
                <button
                    className={`w-act w-schb${s === 'sch' ? ' on' : ''}`}
                    id="schB"
                    type="button"
                    aria-haspopup="dialog"
                    aria-expanded={kind === 'sch'}
                    aria-label={s === 'sch' ? `Scheduled for ${schText} WAT` : 'Schedule'}
                    onClick={() => setKind(kind === 'sch' ? '' : 'sch')}
                >
                    <Clock15 />
                    <span>{schText}</span>
                </button>
            )}
            <button
                className={`k-btn sm${s === 'pub' && !part || kind === 'sch' ? ' line' : ''}${busy === 'pub' ? ' busy' : ''}`}
                id="pubB"
                type="button"
                aria-expanded={kind === 'pub'}
                aria-busy={busy === 'pub'}
                disabled={!part && (s === 'draft' || s === 'unpub') && !words}
                onClick={() => (s === 'pub' && !part ? setKind(kind === 'pub' ? '' : 'pub') : publish())}
            >
                <span>{pubLabel}</span>
                <Ring />
            </button>
            <div className={`w-scrim clear${kind ? ' on' : ''}`} />
            <div ref={pop} className={`w-pan wa-pop${kind ? ' open' : ''}`} id="pop" role="dialog" tabIndex={-1} aria-label={kind === 'sch' ? 'Schedule' : 'Published'} {...inertWhen(!kind)}>
                {kind === 'sch' && (
                    <>
                        <div className="w-ph">
                            <h2>{s === 'sch' ? 'Scheduled' : 'Schedule'}</h2>
                        </div>
                        <div className="w-when">
                            <div className="k-f">
                                <span className="k-fl" id="dSL">
                                    Day
                                </span>
                                <KSelect id="dS" labelledBy="dSL" value={day} options={days.map(([v, l]) => ({ value: v, label: l }))} onPick={setDay} />
                            </div>
                            <div className="k-f">
                                <span className="k-fl" id="tSL">
                                    Time, WAT
                                </span>
                                <KSelect id="tS" labelledBy="tSL" value={time} options={TIMES.map(([v, l]) => ({ value: v, label: l }))} onPick={setTime} />
                            </div>
                        </div>
                        {err && <p className="w-pp">{err}</p>}
                        <div className="w-pf">
                            {s === 'sch' ? (
                                <button
                                    className="w-act"
                                    type="button"
                                    onClick={async () => {
                                        if (await act('unsch', 'DELETE', 'schedule')) {
                                            say('Unscheduled. It’s a draft again.');
                                        }
                                    }}
                                >
                                    Unschedule
                                </button>
                            ) : (
                                <span />
                            )}
                            <button
                                className={`k-btn sm${busy === 'sch' ? ' busy' : ''}`}
                                type="button"
                                aria-busy={busy === 'sch'}
                                onClick={async () => {
                                    const was = s;
                                    const done = await act('sch', 'POST', 'schedule', { day, time });
                                    if (done?.scheduledAt) {
                                        if (was !== 'sch') {
                                            flash('sch');
                                        }
                                        say(`Scheduled for ${watDay(done.scheduledAt)}, ${watTime(done.scheduledAt)} WAT`);
                                    }
                                }}
                            >
                                {s !== 'sch' && <Clock18 />}
                                <span>{s === 'sch' ? 'Save' : 'Schedule'}</span>
                                <Ring />
                            </button>
                        </div>
                    </>
                )}
                {kind === 'pub' && (
                    <>
                        <div className="w-ph">
                            <h2>Published</h2>
                        </div>
                        <p className="w-pp w-when1">
                            <Globe />
                            <span>{pub.liveAt ? watWhen(pub.liveAt) : ''}</span>
                        </p>
                        <p className="w-pp">Edits stay in your draft until you update.</p>
                        <Unfold show={unpubAsk} id="unU">
                            <div className="w-conf">
                                <p>Readers won’t see {label}. It goes back to being a draft. Its comments and likes come back if you publish it again.</p>
                                <div className="acts">
                                    <button
                                        className={`k-btn sm danger${busy === 'unpub' ? ' busy' : ''}`}
                                        type="button"
                                        aria-busy={busy === 'unpub'}
                                        onClick={async () => {
                                            if (await act('unpub', 'POST', 'unpublish')) {
                                                flash('unpub');
                                                say('Unpublished. Readers can’t see it now.');
                                            }
                                        }}
                                    >
                                        <span>Unpublish</span>
                                        <Ring />
                                    </button>
                                    <button className="w-act" type="button" onClick={() => setUnpubAsk(false)}>
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </Unfold>
                        <div className="w-pf">
                            <span className="k-meta">{pub.audience}</span>
                            <button className="w-act" type="button" onClick={() => setUnpubAsk(true)}>
                                Unpublish
                            </button>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

/* ---------------------------------------------------------------- the bar's panels: Chapters and Versions */
type Look = { slug: string; collection: boolean; kind: string; headingStyle: string; partWord: string; partStyle: string; showPartNumbers: boolean; multi: boolean };

export function BarPanels({
    work,
    itemId,
    part,
    words,
    rows,
    pub,
    current,
    onOpenVersion,
    countLabel,
}: {
    work: Look;
    itemId: number;
    part: boolean;
    words: number;
    rows: ChapterRow[];
    pub: PublishState;
    current: () => Json | null;
    onOpenVersion: (v: VersionRow, index: number) => void;
    countLabel: string;
}) {
    const [panel, setPanel] = useState<'' | 'chap' | 'ver'>('');
    const [draftDiff, setDraftDiff] = useState<{ add: number; del: number } | null>(null);
    const pan = useRef<HTMLDivElement>(null);
    const unitWord = work.collection ? ({ poem: 'poem', essay: 'essay' } as Record<string, string>)[work.kind] ?? 'story' : 'chapter';
    const Units = work.collection ? ({ poem: 'Poems', essay: 'Essays' } as Record<string, string>)[work.kind] ?? 'Stories' : 'Chapters';
    const kick = (r: ChapterRow) => (work.collection ? `${unitWord.charAt(0).toUpperCase() + unitWord.slice(1)} ${r.number}` : heading(r.number, 'Chapter', work.headingStyle));

    useEffect(() => {
        if (!panel) {
            return;
        }
        window.setTimeout(() => pan.current?.focus({ preventScroll: true }), prefersReducedMotion() ? 0 : 60);
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && setPanel('');
        document.addEventListener('keydown', esc);

        return () => document.removeEventListener('keydown', esc);
    }, [panel]);

    /* the draft against the last version kept: +added −cut */
    useEffect(() => {
        if (panel !== 'ver' || !pub.versions.length) {
            setDraftDiff(null);

            return;
        }
        let gone = false;
        json<{ body: Json }>('GET', `/works/${work.slug}/write/${itemId}/versions/${pub.versions[0].id}`).then(({ data }) => {
            if (!gone && data.body) {
                const r = diff(blocksOf(data.body), blocksOf(current()));
                setDraftDiff({ add: r.add, del: r.del });
            }
        });

        return () => {
            gone = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [panel, pub.versions]);

    const buttons: ['chap' | 'ver', JSX.Element, string][] = [
        ...(work.multi ? [['chap', <ChaptersIcon />, Units] as ['chap', JSX.Element, string]] : []),
        ...(part ? [] : [['ver', <VersionsIcon />, 'Versions'] as ['ver', JSX.Element, string]]),
    ];

    const newItem = (type: 'chapter' | 'part') => {
        loading(true);
        router.post(`/works/${work.slug}/items`, { type, after: itemId }, { onFinish: () => loading(false) });
    };

    return (
        <>
            <nav className="wa-bar" id="bar" aria-label="Writing">
                {buttons.map(([k, icon, text]) => (
                    <button key={k} type="button" data-p={k} aria-label={text} aria-expanded={panel === k} className={panel === k ? 'on' : ''} onClick={() => setPanel(panel === k ? '' : k)}>
                        {icon}
                        <span className="l">{text}</span>
                    </button>
                ))}
                <span className="f" id="wc">
                    {countLabel}
                </span>
            </nav>
            <div className={`w-scrim${panel ? ' on' : ''}`} id="scrim" onClick={() => setPanel('')} />
            <div ref={pan} className={`w-pan wa-pan${panel ? ' open' : ''}`} id="pan" role="dialog" aria-modal="false" tabIndex={-1} data-k={panel} {...inertWhen(!panel)}>
                {panel === 'chap' && (
                    <>
                        <div className="w-ph">
                            <h2>{Units}</h2>
                            <Link className="k-l" href={`/works/${work.slug}?tab=chapters`}>
                                <span>All</span>
                            </Link>
                        </div>
                        <div className="w-cl">
                            {rows.map((r) => (r.id === itemId ? { ...r, state: pub.state, scheduledAt: pub.scheduledAt } : r)).map((r) =>
                                r.type === 'part' ? (
                                    <Link key={r.id} className={`w-cp${r.id === itemId ? ' here' : ''}`} href={r.href}>
                                        {work.showPartNumbers && <span className="n">{heading(r.number, work.partWord || 'Part', work.partStyle)}</span>}
                                        {r.title}
                                    </Link>
                                ) : (
                                    <Link key={r.id} className={`w-crow${r.id === itemId ? ' here' : ''}`} href={r.href} aria-current={r.id === itemId ? 'page' : undefined}>
                                        <span className="no">{r.number}</span>
                                        <span className="t">{r.title ? r.title : <i className="w-phx">{kick(r)}</i>}</span>
                                        <StateFact row={r} short />
                                    </Link>
                                ),
                            )}
                        </div>
                        <div className="w-pf">
                            <button className="k-l" type="button" onClick={() => newItem('chapter')}>
                                <Plus />
                                <span>New {unitWord}</span>
                            </button>
                            {work.kind === 'novel' && (
                                <button className="k-l" type="button" onClick={() => newItem('part')}>
                                    <Plus />
                                    <span>New {(work.partWord || 'part').toLowerCase()}</span>
                                </button>
                            )}
                        </div>
                    </>
                )}
                {panel === 'ver' && (
                    <>
                        <div className="w-ph">
                            <h2>Versions</h2>
                        </div>
                        <div className="w-vs">
                            <button type="button" className="w-v cur on" onClick={() => setPanel('')}>
                                <span className="a">
                                    <b>Draft</b>
                                    <span>Now</span>
                                </span>
                                <span className="k-meta">{draftDiff ? `+${draftDiff.add} −${draftDiff.del}` : `${words.toLocaleString('en-GB')} words`}</span>
                            </button>
                            {pub.versions.map((v, i) => (
                                <button
                                    key={v.id}
                                    type="button"
                                    className="w-v"
                                    onClick={() => {
                                        setPanel('');
                                        onOpenVersion(v, i);
                                    }}
                                >
                                    <span className="a">
                                        <b>{v.label}</b>
                                        <span>{watWhen(v.at)}</span>
                                    </span>
                                    <span className="k-meta">{i === pub.versions.length - 1 ? `${v.words.toLocaleString('en-GB')} words` : `+${v.added} −${v.cut}`}</span>
                                </button>
                            ))}
                            {!pub.versions.length && <p className="w-empty">Each time you publish or update, a version is kept here.</p>}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

/** A version open in place of the page: what changed since then marked, Back to draft, Restore as draft. */
export function VersionView({ version, body, current, onBack, onRestore }: { version: VersionRow; body: Json; current: Json | null; onBack: () => void; onRestore: () => Promise<void> }) {
    const [busy, setBusy] = useState(false);
    const r = diff(blocksOf(body), blocksOf(current));

    return (
        <div id="vview" className="w-vview settle">
            <div className="w-vbar">
                <span className="a">
                    <b>
                        {version.label} {watWhen(version.at)}
                    </b>
                    <span>
                        +{r.add} −{r.del} since then
                    </span>
                </span>
                <span className="r">
                    <button className="w-act" type="button" id="vBack" onClick={onBack}>
                        Back to draft
                    </button>
                    <button
                        className={`k-btn line sm${busy ? ' busy' : ''}`}
                        type="button"
                        id="vRes"
                        aria-busy={busy}
                        onClick={async () => {
                            setBusy(true);
                            await onRestore();
                            setBusy(false);
                        }}
                    >
                        <span>Restore as draft</span>
                        <Ring />
                    </button>
                </span>
            </div>
            <div className="w-vtx" dangerouslySetInnerHTML={{ __html: r.h }} />
        </div>
    );
}

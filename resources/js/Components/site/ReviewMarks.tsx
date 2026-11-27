import { FormEvent, Fragment, KeyboardEvent, ReactNode, RefObject, useCallback, useEffect, useRef, useState } from 'react';
import Bloom from '@/Components/site/Bloom';
import { Footnote, Run, styled } from '@/Components/site/Runs';
import { json } from '@/lib/http';

export type MarkReply = { id: number; mine: boolean; author: boolean; name: string; seed: string; photo: string | null; at: string; text: string };
export type Mark = { id: number; kind: 'line' | 'edit'; block: string; start: number; end: number; ins: string; text: string; at: string; replies: MarkReply[] };
export type Review = { me: { name: string; seed: string }; marks: Mark[] };
type Draft = { kind: 'line' | 'edit'; block: string; start: number; end: number; quote: string };
type Bar = { left: number; top: number; below: boolean; sel: Omit<Draft, 'kind'> } | null;

const SEND = (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M12 19V5M5 12l7-7 7 7" />
    </svg>
);
const COMMENT = (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z" />
    </svg>
);
const EDIT = (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M12 20h9" />
        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
    </svg>
);

const plain = (runs: Run[]) => runs.map((r) => r.text).join('');
const first = (name: string) => name.split(' ')[0];

/** Where a point in a paragraph sits in its text: footnotes, comment numbers and suggested words don't count. */
function offsetIn(p: HTMLElement, node: Node, offset: number): number {
    const r = document.createRange();
    r.setStart(p, 0);
    r.setEnd(node, offset);
    const f = r.cloneContents();
    f.querySelectorAll('.rfn-note,.rfn-n,.fn,.ed ins').forEach((e) => e.remove());
    f.querySelectorAll('br').forEach((b) => b.replaceWith('\n'));

    return (f.textContent ?? '').length;
}

/** A note's card under its paragraph: it unfolds the frame after it is drawn, so it opens rather than appears. */
function Unfolding({ open, children, id }: { open: boolean; children: ReactNode; id: string }) {
    const [on, setOn] = useState(false);

    useEffect(() => {
        if (!open) {
            setOn(false);

            return;
        }
        const f = requestAnimationFrame(() => requestAnimationFrame(() => setOn(true)));

        return () => cancelAnimationFrame(f);
    }, [open]);

    return (
        <div className={`cw${on ? ' open' : ''}`} data-for={id}>
            <div>{children}</div>
        </div>
    );
}

function Composer({ placeholder, className, seed, size, onSend, autoFocus = true }: { placeholder: string; className: string; seed: string; size: number; onSend: (text: string) => Promise<boolean>; autoFocus?: boolean }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (autoFocus) {
            window.setTimeout(() => input.current?.focus({ preventScroll: true }), 60);
        }
    }, [autoFocus]);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!text.trim() || busy) {
            return;
        }
        setBusy(true);
        if (await onSend(text.trim())) {
            setText('');
        }
        setBusy(false);
    }

    return (
        <form className={`cnew ${className}${text ? ' typed' : ''}`} onSubmit={submit}>
            <Bloom seed={seed} size={size} />
            <label className="cin">
                <input ref={input} type="text" placeholder={placeholder} aria-label={placeholder} value={text} onChange={(e) => setText(e.target.value)} />
                <button className="send" type="submit" aria-label="Send" disabled={!text.trim() || busy}>
                    {SEND}
                </button>
            </label>
        </form>
    );
}

/**
 * A reviewer's marks in the text (Book page reader, approved): select words to Comment or Suggest an edit; marked
 * words carry a number that unfolds the note under the paragraph. Reviewers see only their own notes and her replies.
 */
export function useReviewMarks(review: Review | null, item: number, root: RefObject<HTMLElement | null>) {
    const [marks, setMarks] = useState<Mark[]>(review?.marks ?? []);
    const [open, setOpen] = useState<Set<number>>(new Set());
    const [draft, setDraft] = useState<Draft | null>(null);
    const [bar, setBar] = useState<Bar>(null);
    const [shake, setShake] = useState(0);
    const [replying, setReplying] = useState<number | null>(null);
    const insRef = useRef<HTMLElement | null>(null);
    const barRef = useRef<HTMLDivElement>(null);
    const on = review !== null;

    useEffect(() => setMarks(review?.marks ?? []), [review]);

    const toggle = (id: number) =>
        setOpen((s) => {
            const n = new Set(s);
            if (n.has(id)) {
                n.delete(id);
            } else {
                n.add(id);
            }

            return n;
        });

    /* the bar comes up over a selection inside a paragraph, away from other marks */
    const check = useCallback(() => {
        const s = window.getSelection();
        if (!on || draft || !s || !s.rangeCount || s.isCollapsed || !root.current) {
            setBar(null);

            return;
        }
        const r = s.getRangeAt(0);
        const c = r.commonAncestorContainer;
        const el = c.nodeType === 1 ? (c as Element) : c.parentElement;
        const p = el?.closest<HTMLElement>('p.pp[data-id]');
        if (!p || !root.current.contains(p) || el?.closest('.cw,.ed,mark,.rfn') || [...p.querySelectorAll('mark.hl,.ed,.rfn')].some((m) => r.intersectsNode(m)) || !s.toString().trim()) {
            setBar(null);

            return;
        }
        let start = offsetIn(p, r.startContainer, r.startOffset);
        let end = offsetIn(p, r.endContainer, r.endOffset);
        const text = p.dataset.text ?? '';
        while (start < end && /\s/.test(text[start])) {
            start++;
        }
        while (end > start && /\s/.test(text[end - 1])) {
            end--;
        }
        if (end <= start) {
            setBar(null);

            return;
        }
        const b = r.getBoundingClientRect();
        const w = barRef.current?.offsetWidth || 250;
        let top = b.top - 54;
        let below = false;
        if (top < 70) {
            top = b.bottom + 12;
            below = true;
        }
        setBar({ left: Math.max(8, Math.min(window.innerWidth - w - 8, b.left + b.width / 2 - w / 2)), top, below, sel: { block: p.dataset.id!, start, end, quote: text.slice(start, end) } });
    }, [on, draft, root]);

    useEffect(() => {
        if (!on) {
            return;
        }
        const later = (e: Event) => {
            if (!barRef.current?.contains(e.target as Node)) {
                window.setTimeout(check, 0);
            }
        };
        const touch = () => window.setTimeout(check, 350);
        const down = (e: MouseEvent) => {
            if (!barRef.current?.contains(e.target as Node)) {
                setBar(null);
            }
        };
        document.addEventListener('mouseup', later);
        document.addEventListener('keyup', later);
        document.addEventListener('touchend', touch);
        document.addEventListener('mousedown', down);

        return () => {
            document.removeEventListener('mouseup', later);
            document.removeEventListener('keyup', later);
            document.removeEventListener('touchend', touch);
            document.removeEventListener('mousedown', down);
        };
    }, [on, check]);

    function begin(kind: Draft['kind']) {
        if (!bar) {
            return;
        }
        setDraft({ kind, ...bar.sel });
        setBar(null);
        window.getSelection()?.removeAllRanges();
        if (kind === 'edit') {
            window.setTimeout(() => insRef.current?.focus(), 30);
        }
    }

    async function create(d: Draft, body: string | null, inserted: string | null): Promise<boolean> {
        const { status, data } = await json<{ mark: Mark }>('POST', '/review/marks', { item, block: d.block, start: d.start, end: d.end, quote: d.quote, kind: d.kind, body, inserted });
        if (status !== 201 || !data.mark) {
            setShake((k) => k + 1);

            return false;
        }
        const m = data.mark;
        setMarks((ms) => [...ms, m]);
        setOpen((s) => new Set(s).add(m.id));
        setDraft(null);

        return true;
    }

    async function reply(m: Mark, text: string): Promise<boolean> {
        const { status, data } = await json<{ reply: MarkReply }>('POST', `/review/marks/${m.id}/reply`, { body: text });
        if (status !== 201 || !data.reply) {
            return false;
        }
        setMarks((ms) => ms.map((x) => (x.id === m.id ? { ...x, replies: [...x.replies, data.reply!] } : x)));
        setReplying(null);

        return true;
    }

    function suggest() {
        if (!draft) {
            return;
        }
        void create(draft, null, (insRef.current?.textContent ?? '').trim());
    }

    function cancel() {
        setDraft(null);
    }

    /** One paragraph's words with the marks in them, then the notes' cards under it. */
    function paragraph(id: string | null, runs: Run[], className: string, key: number): ReactNode {
        const text = plain(runs);
        const here = id === null ? [] : marks.filter((m) => m.block === id).sort((a, b) => a.start - b.start);
        const shown: { key: string; id: number | null; kind: Draft['kind']; start: number; end: number; ins: string; count: number; typing: boolean }[] = here.map((m) => ({
            key: String(m.id),
            id: m.id,
            kind: m.kind,
            start: m.start,
            end: m.end,
            ins: m.ins,
            count: 1 + m.replies.length,
            typing: false,
        }));
        if (draft && draft.block === id) {
            shown.push({ key: 'new', id: null, kind: draft.kind, start: draft.start, end: draft.end, ins: '', count: 0, typing: draft.kind === 'edit' });
            shown.sort((a, b) => a.start - b.start);
        }
        if (!on || shown.length === 0) {
            return (
                <p className={className} key={key} data-id={id ?? undefined} data-text={on ? text : undefined}>
                    {runs.map((r, i) => (r.fn !== undefined ? r.fn > 0 ? <Footnote key={i} n={r.fn} note={r.note ?? ''} /> : null : <Fragment key={i}>{styled(r)}</Fragment>))}
                </p>
            );
        }

        const out: ReactNode[] = [];
        let pos = 0;
        runs.forEach((r, ri) => {
            if (r.fn !== undefined) {
                if (r.fn > 0) {
                    out.push(<Footnote key={`f${ri}`} n={r.fn} note={r.note ?? ''} />);
                }

                return;
            }
            const len = r.text.length;
            let i = 0;
            while (i < len) {
                const a = pos + i;
                const m = shown.find((x) => x.start <= a && a < x.end);
                if (!m) {
                    const next = Math.min(...shown.filter((x) => x.start > a).map((x) => x.start), pos + len);
                    out.push(<Fragment key={`t${ri}-${i}`}>{styled(r, r.text.slice(i, next - pos))}</Fragment>);
                    i = next - pos;
                    continue;
                }
                const until = Math.min(m.end - pos, len);
                const lit = m.id === null || open.has(m.id);
                const click = () => m.id !== null && window.getSelection()?.isCollapsed !== false && toggle(m.id);
                const raw = r.text.slice(i, until);
                const wrap = (t: string, k: string) =>
                    m.kind === 'line' ? (
                        <mark className={`hl${lit ? ' on' : ''}`} data-m={m.key} key={k} onClick={click}>
                            {styled(r, t)}
                        </mark>
                    ) : (
                        <span className={`ed${lit ? ' on' : ''}`} data-m={m.key} key={k} onClick={click}>
                            <del>{styled(r, t)}</del>
                        </span>
                    );
                const ends = pos + until === m.end;
                const count =
                    m.id !== null ? (
                        <button className={`fn${open.has(m.id) ? ' on' : ''}`} type="button" data-m={m.key} key="n" aria-expanded={open.has(m.id)} aria-label={`${m.count} ${m.count === 1 ? 'comment' : 'comments'}`} onClick={() => toggle(m.id!)}>
                            {m.count}
                        </button>
                    ) : null;
                /* the number stays on the line with the mark's last word */
                const glued = (head: ReactNode, word: ReactNode, k: string) => (
                    <Fragment key={k}>
                        {head}
                        <span className="nw2">
                            {word}
                            {count}
                        </span>
                    </Fragment>
                );
                const cut = (t: string) => {
                    const at = t.trimEnd().lastIndexOf(' ') + 1;

                    return [t.slice(0, at), t.slice(at)];
                };
                if (!ends) {
                    out.push(wrap(raw, `m${ri}-${i}`));
                } else if (m.typing) {
                    out.push(wrap(raw, `m${ri}-${i}`));
                    out.push(
                        <span className="ed typing on" data-m="new" key="typing">
                            <ins
                                contentEditable
                                suppressContentEditableWarning
                                ref={(el) => {
                                    insRef.current = el;
                                }}
                                onKeyDown={(e: KeyboardEvent) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        suggest();
                                    }
                                    if (e.key === 'Escape') {
                                        cancel();
                                    }
                                }}
                            />
                        </span>,
                    );
                } else if (m.kind === 'edit' && m.ins) {
                    const [head, word] = cut(m.ins);
                    const ins = (t: string, k: string) =>
                        t ? (
                            <span className={`ed${lit ? ' on' : ''}`} data-m={m.key} key={k} onClick={click}>
                                <ins>{t}</ins>
                            </span>
                        ) : null;
                    out.push(wrap(raw, `m${ri}-${i}`));
                    out.push(<Fragment key={`s${m.key}`}> </Fragment>);
                    out.push(glued(ins(head, 'h'), ins(word, 'w'), `g${m.key}`));
                } else {
                    const [head, word] = cut(raw);
                    out.push(glued(head ? wrap(head, 'h') : null, wrap(word, 'w'), `g${m.key}`));
                }
                i = until;
            }
            pos += len;
        });

        const me = review!.me;

        return (
            <Fragment key={key}>
                <p className={className} data-id={id ?? undefined} data-text={text}>
                    {out}
                </p>
                {shown.map((s) => {
                    if (s.id === null) {
                        return (
                            <Unfolding open id="new" key="new">
                                {draft!.kind === 'line' ? (
                                    <div className="card2">
                                        <div className="mh">
                                            <Bloom seed={me.seed} size={22} />
                                            <b>{first(me.name)}</b>
                                            <span>now</span>
                                        </div>
                                        <Composer placeholder="Add a comment" className="mform" seed={me.seed} size={26} onSend={(t) => create(draft!, t, null)} />
                                        <div className="macts">
                                            <button className="mbtn plain mcancel" type="button" onClick={cancel}>
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="sugbox">
                                        <Bloom seed={me.seed} size={26} />
                                        <span>Suggesting an edit</span>
                                        <button className="mbtn plain mcancel" type="button" onClick={cancel}>
                                            Cancel
                                        </button>
                                        <button className="mbtn solid msug2" type="button" onClick={suggest}>
                                            Suggest
                                        </button>
                                    </div>
                                )}
                            </Unfolding>
                        );
                    }
                    const m = marks.find((x) => x.id === s.id)!;

                    return (
                        <Unfolding open={open.has(m.id)} id={String(m.id)} key={m.id}>
                            <div className="card2">
                                <div className="mh">
                                    <Bloom seed={me.seed} size={22} />
                                    <b>{first(me.name)}</b>
                                    <span>{m.at}</span>
                                </div>
                                {m.kind === 'line' ? (
                                    <p className="mt">{m.text}</p>
                                ) : (
                                    <>
                                        <div className="msug">
                                            <span className="slbl">Suggested</span>
                                            <del>{text.slice(m.start, m.end)}</del>
                                            {m.ins && <ins>{m.ins}</ins>}
                                        </div>
                                        {m.text && <p className="mt">{m.text}</p>}
                                    </>
                                )}
                                {m.replies.length > 0 && (
                                    <div className="mreps">
                                        {m.replies.map((r) => (
                                            <div className="mr" key={r.id}>
                                                <Bloom seed={r.seed} size={22} photo={r.photo} name={r.name} />
                                                <div>
                                                    <div className="mh">
                                                        <b>{r.name}</b>
                                                        {r.author && <span className="atag">Author</span>}
                                                        <span>{r.at}</span>
                                                    </div>
                                                    <p>{r.text}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {replying === m.id && <Composer placeholder="Reply" className="mrform" seed={me.seed} size={26} onSend={(t) => reply(m, t)} />}
                                <div className="macts">
                                    <button className="mbtn plain mrep2" type="button" onClick={() => setReplying(replying === m.id ? null : m.id)}>
                                        Reply
                                    </button>
                                </div>
                            </div>
                        </Unfolding>
                    );
                })}
            </Fragment>
        );
    }

    const toolbar = on ? (
        <div
            ref={barRef}
            className={`tbar live${bar ? ' show' : ''}${bar?.below ? ' below' : ''}${shake ? ' shake' : ''}`}
            role="toolbar"
            aria-label="Review"
            style={bar ? { left: bar.left, top: bar.top } : undefined}
            onMouseDown={(e) => e.preventDefault()}
            onAnimationEnd={() => setShake(0)}
        >
            <button type="button" onClick={() => begin('line')}>
                {COMMENT}
                Comment
            </button>
            <i />
            <button type="button" onClick={() => begin('edit')}>
                {EDIT}
                Suggest edit
            </button>
        </div>
    ) : null;

    return { on, paragraph, toolbar };
}

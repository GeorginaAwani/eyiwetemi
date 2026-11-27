import { FormEvent, useEffect, useRef, useState } from 'react';
import { Node as PMNode } from 'prosemirror-model';
import { EditorState, Plugin, PluginKey } from 'prosemirror-state';
import { Decoration, DecorationSet, EditorView } from 'prosemirror-view';
import { bloomStyle } from '../../site/Bloom';
import { PI } from '../People';

export type NoteReply = { id: number; who: string; seed: string; me: boolean; at: string; text: string };
/** A reviewer's open note on this chapter or piece, as the inbox draws it, with where its words start. */
export type Note = {
    id: number;
    who: string;
    seed: string;
    at: string;
    kind: 'line' | 'edit' | 'comment' | 'book';
    quote: string;
    del: string;
    ins: string;
    text: string;
    new: boolean;
    replies: NoteReply[];
    block: string | null;
    start: number;
};
type MarksState = { notes: Note[]; open: number | null; hide: boolean };

export const marksKey = new PluginKey<MarksState>('eyiMarks');

/** Where a note's words are in the page now: found where the note says, else the first place they appear. */
export function locate(doc: PMNode, note: Note): { from: number; to: number; after: number } | null {
    const words = note.kind === 'edit' ? note.del || note.quote : note.quote;
    if (!note.block || !words) {
        return null;
    }
    let found: { from: number; to: number; after: number } | null = null;
    doc.forEach((block, offset) => {
        if (found || block.attrs.id !== note.block) {
            return;
        }
        /* each character of the block's text and where it sits; a line break is one character, a footnote none */
        const at: number[] = [];
        let text = '';
        block.descendants((n, pos) => {
            if (n.isText) {
                for (let i = 0; i < n.text!.length; i++) {
                    at.push(offset + 1 + pos + i);
                }
                text += n.text;
            } else if (n.type.name === 'hardBreak') {
                at.push(offset + 1 + pos);
                text += '\n';
            }

            return true;
        });
        const i = text.substr(note.start, words.length) === words ? note.start : text.indexOf(words);
        if (i >= 0) {
            found = { from: at[i], to: at[i + words.length - 1] + 1, after: offset + block.nodeSize };
        }
    });

    return found;
}

/**
 * Reviewers' marks in the draft: their words lit (an edit's words struck, the suggestion beside them), and the open
 * note's card under its paragraph. Nothing in the text changes; Hide marks shows the page as it would read.
 */
export function marksPlugin(notes: Note[], host: () => HTMLElement, onMark: (id: number) => void): Plugin<MarksState> {
    return new Plugin<MarksState>({
        key: marksKey,
        state: {
            init: () => ({ notes, open: null, hide: localStorage.getItem('eyiAdmHideMarks') === '1' }),
            apply: (tr, s) => {
                const m = tr.getMeta(marksKey) as Partial<MarksState> | undefined;

                return m ? { ...s, ...m } : s;
            },
        },
        props: {
            attributes: (state: EditorState): Record<string, string> => (marksKey.getState(state)?.hide ? { class: 'nomarks' } : {}),
            decorations: (state: EditorState) => {
                const s = marksKey.getState(state);
                if (!s || !s.notes.length) {
                    return null;
                }
                const out: Decoration[] = [];
                s.notes.forEach((n) => {
                    const at = locate(state.doc, n);
                    if (!at) {
                        return;
                    }
                    const on = s.open === n.id;
                    if (n.kind === 'edit') {
                        out.push(Decoration.inline(at.from, at.to, { nodeName: 'del', class: 'w-sgd', 'data-c': String(n.id) }));
                        out.push(
                            Decoration.widget(
                                at.to,
                                () => {
                                    const w = document.createElement('span');
                                    w.className = 'w-sg';
                                    w.dataset.c = String(n.id);
                                    w.contentEditable = 'false';
                                    const ins = document.createElement('ins');
                                    ins.textContent = n.ins;
                                    w.append(' ', ins);

                                    return w;
                                },
                                { side: 1, key: `sg${n.id}:${n.ins}`, ignoreSelection: true },
                            ),
                        );
                    } else {
                        out.push(Decoration.inline(at.from, at.to, { class: `w-hl${on ? ' on' : ''}`, 'data-c': String(n.id) }));
                    }
                    if (on) {
                        out.push(Decoration.widget(at.after, host, { side: -1, key: `rc${n.id}`, ignoreSelection: true, stopEvent: () => true }));
                    }
                });

                return DecorationSet.create(state.doc, out);
            },
            handleDOMEvents: {
                click: (view: EditorView, e: MouseEvent) => {
                    const t = (e.target as Element).closest?.('[data-c]');
                    if (!t || t.closest('.w-rc') || !view.state.selection.empty) {
                        return false;
                    }
                    onMark(Number((t as HTMLElement).dataset.c));

                    return false;
                },
            },
        },
    });
}

/** Changes the marks' state: the notes, the one open, whether they show. */
export const setMarks = (view: EditorView | null, m: Partial<MarksState>) => view && view.dispatch(view.state.tr.setMeta(marksKey, m).setMeta('addToHistory', false));

const firstName = (n: string) => n.split(' ')[0];

/**
 * The open note under its paragraph: who, when, the note and her replies, a reply field, then Resolve, or Accept and
 * Reject for an edit.
 */
export function MarkCard({
    note,
    me,
    onClose,
    onReply,
    onDone,
}: {
    note: Note;
    me: { name: string; photo: string | null };
    onClose: () => void;
    onReply: (text: string) => Promise<boolean>;
    onDone: (status: 'accepted' | 'rejected' | 'resolved') => void;
}) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!text.trim() || busy) {
            return;
        }
        setBusy(true);
        if (await onReply(text.trim())) {
            setText('');
        }
        setBusy(false);
    }

    const av = (seed: string, mine: boolean) => (
        <span className={`w-av${mine ? ' me' : ''}`} style={mine ? (me.photo ? { backgroundImage: `url(${me.photo})` } : bloomStyle('me', 22)) : bloomStyle(seed, 22)} aria-hidden="true" />
    );

    return (
        <>
            <div className="w-rch">
                {av(note.seed, false)}
                <b>{firstName(note.who)}</b>
                <span>{note.at}</span>
                {note.kind === 'edit' && <span className="w-tag">Suggested edit</span>}
                <button className="ad-ic w-rcx" type="button" aria-label="Close" onClick={onClose}>
                    {PI.x(18)}
                </button>
            </div>
            {note.text && <p>{note.text}</p>}
            {note.replies.map((r) => (
                <div className="w-rp" key={r.id}>
                    <div className="w-rch">
                        {av(r.seed, r.me)}
                        <b>{r.me ? firstName(me.name) : firstName(r.who)}</b>
                        <span>{r.at}</span>
                    </div>
                    <p>{r.text}</p>
                </div>
            ))}
            <form className="w-rf" onSubmit={submit}>
                <span className="k-in sm">
                    <input
                        ref={input}
                        placeholder="Reply"
                        aria-label={`Reply to ${firstName(note.who)}`}
                        autoComplete="off"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                                e.stopPropagation();
                                onClose();
                            }
                        }}
                    />
                </span>
                <button className="ad-ic" type="submit" aria-label="Send" disabled={!text.trim() || busy}>
                    {PI.send}
                </button>
            </form>
            <div className="w-rca">
                {note.kind === 'edit' ? (
                    <>
                        <button className="w-act" type="button" data-r="acc" onClick={() => onDone('accepted')}>
                            {PI.check()}
                            <span>Accept</span>
                        </button>
                        <button className="w-act" type="button" data-r="rej" onClick={() => onDone('rejected')}>
                            {PI.x()}
                            <span>Reject</span>
                        </button>
                    </>
                ) : (
                    <button className="w-act" type="button" data-r="res" onClick={() => onDone('resolved')}>
                        Resolve
                    </button>
                )}
            </div>
        </>
    );
}

/** The bar's Comments panel: every open note on this page; a note with words opens on them. */
export function CommentsPanel({ notes, unit, slug, hide, setHide, onOpen }: { notes: Note[]; unit: string; slug: string; hide: boolean; setHide: (on: boolean) => void; onOpen: (n: Note) => void }) {
    useEffect(() => localStorage.setItem('eyiAdmHideMarks', hide ? '1' : '0'), [hide]);

    return (
        <>
            <div className="w-ph">
                <h2>Comments</h2>
                <a className="k-l w-allc" href={`/comments?tab=rev&w=${slug}`}>
                    All comments
                </a>
                <label className="ad-cb w-hide">
                    <input type="checkbox" id="hideM" checked={hide} onChange={(e) => setHide(e.target.checked)} />
                    <span className="ad-box">{PI.tick()}</span>
                    <span>Hide marks</span>
                </label>
            </div>
            {!notes.length && <p className="w-empty">No open comments on this {unit}.</p>}
            <div className="w-cms">
                {notes.map((n) => (
                    <button className="w-cm" type="button" key={n.id} onClick={() => onOpen(n)}>
                        <span className="w-rch">
                            <span className="w-av" style={bloomStyle(n.seed, 22)} aria-hidden="true" />
                            <b>{firstName(n.who)}</b>
                            <span>{n.at}</span>
                            {n.kind === 'edit' && <span className="w-tag">Suggested edit</span>}
                        </span>
                        {(n.kind === 'edit' ? n.del || n.quote : n.quote) && <q>{n.kind === 'edit' ? n.del || n.quote : n.quote}</q>}
                        {n.text && <span className="tx">{n.text}</span>}
                    </button>
                ))}
            </div>
        </>
    );
}

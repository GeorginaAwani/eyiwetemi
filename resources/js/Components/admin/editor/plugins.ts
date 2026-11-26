import { baseKeymap, chainCommands, setBlockType, toggleMark } from 'prosemirror-commands';
import { history, redo, undo } from 'prosemirror-history';
import { InputRule, inputRules, wrappingInputRule } from 'prosemirror-inputrules';
import { keymap } from 'prosemirror-keymap';
import { Fragment, Node as PMNode, Slice } from 'prosemirror-model';
import { liftListItem, sinkListItem, splitListItem } from 'prosemirror-schema-list';
import { Command, EditorState, NodeSelection, Plugin, PluginKey, TextSelection, Transaction } from 'prosemirror-state';
import { Decoration, DecorationSet, EditorView, NodeView } from 'prosemirror-view';
import { prefersReducedMotion } from '../../../lib/motion';
import { newId, schema } from './schema';

export type Kind = 'novel' | 'story' | 'poem' | 'essay';
const N = schema.nodes;
const M = schema.marks;
const WITH_ID = new Set(['paragraph', 'poemLine', 'blockquote', 'sceneBreak', 'bulletList', 'image', 'footnote']);

/* ---------------------------------------------------------------- block ids: every block keeps a unique one */
const ids = new Plugin({
    appendTransaction(trs, _old, state) {
        if (!trs.some((t) => t.docChanged)) {
            return null;
        }
        const seen = new Set<string>();
        let tr: Transaction | null = null;
        state.doc.descendants((node, pos) => {
            if (!WITH_ID.has(node.type.name)) {
                return true;
            }
            const id = node.attrs.id as string | null;
            if (!id || seen.has(id)) {
                tr = (tr ?? state.tr).setNodeMarkup(pos, undefined, { ...node.attrs, id: newId() });
            } else {
                seen.add(id);
            }

            return node.isBlock;
        });
        if (tr) {
            (tr as Transaction).setMeta('addToHistory', false);
        }

        return tr;
    },
});

/* ---------------------------------------------------------------- how the page reads: first paragraphs, footnote numbers, the empty page */
export const marksKey = new PluginKey('look');
const look = new Plugin({
    key: marksKey,
    props: {
        decorations(state) {
            const decos: Decoration[] = [];
            let prev: string | null = null;
            state.doc.forEach((node, pos) => {
                if (node.type === N.paragraph && prev !== 'paragraph') {
                    decos.push(Decoration.node(pos, pos + node.nodeSize, { class: 'first' }));
                }
                prev = node.type.name;
            });
            let n = 0;
            state.doc.descendants((node, pos) => {
                if (node.type === N.footnote) {
                    decos.push(Decoration.node(pos, pos + 1, {}, { n: ++n }));
                }
            });

            return DecorationSet.create(state.doc, decos);
        },
        attributes(state) {
            const d = state.doc;
            const empty = d.childCount === 1 && d.firstChild!.isTextblock && d.firstChild!.content.size === 0;

            return { class: `w-ed${empty ? ' empty' : ''}` };
        },
    },
});

/* ---------------------------------------------------------------- commands */
export const toggleBold = toggleMark(M.bold);
export const toggleItalic = toggleMark(M.italic);

/** The block the cursor is in (top level). */
export function topBlock(state: EditorState): { node: PMNode; pos: number } | null {
    const $from = state.selection.$from;
    if ($from.depth < 1) {
        return state.selection instanceof NodeSelection ? { node: state.selection.node, pos: state.selection.from } : null;
    }

    return { node: $from.node(1), pos: $from.before(1) };
}

export const inQuote = (state: EditorState) => topBlock(state)?.node.type === N.blockquote;

/** Quote on, or back to a paragraph (a poem line in a poem). */
export function toggleQuote(kind: Kind): Command {
    return (state, dispatch) => (inQuote(state) ? setBlockType(kind === 'poem' ? N.poemLine : N.paragraph)(state, dispatch) : setBlockType(N.blockquote)(state, dispatch));
}

/** A scene break after the current block (or in place of it), with a fresh paragraph to carry on in. */
export function sceneBreak(replace: boolean): Command {
    return (state, dispatch) => {
        const b = topBlock(state);
        if (!b) {
            return false;
        }
        if (dispatch) {
            const brk = N.sceneBreak.create({ id: newId() });
            const para = N.paragraph.create({ id: newId() });
            const from = replace ? b.pos : b.pos + b.node.nodeSize;
            const to = replace ? b.pos + b.node.nodeSize : from;
            const tr = state.tr.replaceWith(from, to, [brk, para]);
            tr.setSelection(TextSelection.create(tr.doc, from + brk.nodeSize + 1)).scrollIntoView();
            dispatch(tr);
        }

        return true;
    };
}

/** A footnote at the end of the selection; its note opens for writing. */
export const addFootnote: Command = (state, dispatch, view) => {
    const at = state.selection.to;
    const $at = state.doc.resolve(at);
    if (!$at.parent.inlineContent) {
        return false;
    }
    if (dispatch) {
        const fid = newId();
        const tr = state.tr.insert(at, N.footnote.create({ id: fid, text: '' }));
        tr.setSelection(TextSelection.create(tr.doc, at + 1));
        dispatch(tr);
        window.setTimeout(() => openFootnote(view!, fid, true), 0);
    }

    return true;
};

export function setLink(href: string): Command {
    return (state, dispatch) => {
        const { from, to } = state.selection;
        if (from === to) {
            return false;
        }
        if (dispatch) {
            const tr = state.tr.removeMark(from, to, M.link);
            if (href) {
                tr.addMark(from, to, M.link.create({ href: /^(https?:|mailto:)/i.test(href) ? href : 'https://' + href }));
            }
            dispatch(tr);
        }

        return true;
    };
}

export function bulletList(): Command {
    return (state, dispatch) => {
        const b = topBlock(state);
        if (!b || b.node.type !== N.paragraph) {
            return false;
        }
        if (dispatch) {
            const item = N.listItem.create(null, N.paragraph.create({ id: newId() }, b.node.content));
            const list = N.bulletList.create({ id: newId() }, item);
            const tr = state.tr.replaceWith(b.pos, b.pos + b.node.nodeSize, list);
            tr.setSelection(TextSelection.create(tr.doc, b.pos + 3 + b.node.content.size));
            dispatch(tr);
        }

        return true;
    };
}

export function insertImage(mediaId: number, src: string): Command {
    return (state, dispatch) => {
        const b = topBlock(state);
        const at = b ? b.pos + b.node.nodeSize : state.doc.content.size;
        if (dispatch) {
            const img = N.image.create({ id: newId(), mediaId, src, credit: '' });
            const tr = state.tr.insert(at, [img, N.paragraph.create({ id: newId() })]);
            tr.setSelection(TextSelection.create(tr.doc, at + img.nodeSize + 1)).scrollIntoView();
            dispatch(tr);
        }

        return true;
    };
}

/** Poems: Tab moves a line in (up to three steps), Shift Tab back out. */
function indent(by: number): Command {
    return (state, dispatch) => {
        const b = topBlock(state);
        if (!b || b.node.type !== N.poemLine) {
            return false;
        }
        if (dispatch) {
            dispatch(state.tr.setNodeMarkup(b.pos, undefined, { ...b.node.attrs, indent: Math.max(0, Math.min(3, b.node.attrs.indent + by)) }));
        }

        return true;
    };
}

/** Enter on an empty quote, or Backspace at its start, turns it back into a paragraph. */
function leaveQuote(onlyEmpty: boolean, kind: Kind): Command {
    return (state, dispatch) => {
        const b = topBlock(state);
        const sel = state.selection;
        if (!b || b.node.type !== N.blockquote || !sel.empty) {
            return false;
        }
        if (onlyEmpty ? b.node.content.size !== 0 : sel.$from.parentOffset !== 0) {
            return false;
        }

        return setBlockType(kind === 'poem' ? N.poemLine : N.paragraph)(state, dispatch);
    };
}

/** "***" (or "* * *") then Enter is a scene break. */
const breakOnStars: Command = (state, dispatch, view) => {
    const b = topBlock(state);
    if (!b || !b.node.isTextblock || !/^\*\s?\*\s?\*$/.test(b.node.textContent.trim())) {
        return false;
    }

    return sceneBreak(true)(state, dispatch, view);
};

/* ---------------------------------------------------------------- footnotes: a number in the text; the note unfolds in place */
let openFn: FootnoteView | null = null;

export function openFootnote(view: EditorView, fid: string, focus: boolean) {
    const sup = view.dom.querySelector<HTMLElement>(`sup.fn[data-f="${fid}"]`);
    const v = sup && (sup.parentElement as HTMLElement & { _fn?: FootnoteView })._fn;
    v?.open(focus);
}

export function closeFootnote() {
    openFn?.close();
}

class FootnoteView implements NodeView {
    dom: HTMLElement & { _fn?: FootnoteView };
    sup: HTMLElement;
    box: HTMLElement | null = null;

    constructor(
        private node: PMNode,
        private view: EditorView,
        private getPos: () => number | undefined,
        decos: readonly Decoration[],
    ) {
        this.dom = document.createElement('span');
        this.dom.className = 'w-fnw';
        this.dom._fn = this;
        this.sup = document.createElement('sup');
        this.sup.className = 'fn';
        this.sup.dataset.f = node.attrs.id;
        this.sup.contentEditable = 'false';
        this.dom.appendChild(this.sup);
        this.number(decos);
        this.sup.addEventListener('mousedown', (e) => e.preventDefault());
        this.sup.addEventListener('click', (e) => {
            e.preventDefault();
            if (this.box) {
                this.close();
            } else {
                this.open(false);
            }
        });
    }

    number(decos: readonly Decoration[]) {
        const n = (decos.find((d) => (d as unknown as { spec: { n?: number } }).spec.n) as unknown as { spec: { n: number } } | undefined)?.spec.n;
        if (n) {
            this.sup.textContent = String(n);
            const nn = this.box?.querySelector('.w-fnn');
            if (nn) {
                nn.textContent = String(n);
            }
        }
    }

    open(focus: boolean) {
        if (openFn && openFn !== this) {
            openFn.close(true);
        }
        if (this.box) {
            return;
        }
        openFn = this;
        this.sup.classList.add('on');
        const box = document.createElement('span');
        box.className = 'w-fnc';
        box.contentEditable = 'false';
        box.innerHTML = '<span class="w-fnn"></span><span class="w-fnt" contenteditable="true" data-ph="Note" spellcheck="true"></span><span class="w-fna"><button class="w-act" type="button">Remove</button></span>';
        box.querySelector('.w-fnn')!.textContent = this.sup.textContent;
        const t = box.querySelector<HTMLElement>('.w-fnt')!;
        t.textContent = this.node.attrs.text;
        t.addEventListener('input', () => {
            if (!t.textContent) {
                t.innerHTML = '';
            }
            const pos = this.getPos();
            if (pos !== undefined) {
                this.view.dispatch(this.view.state.tr.setNodeMarkup(pos, undefined, { ...this.node.attrs, text: t.textContent ?? '' }));
            }
        });
        t.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === 'Escape') {
                e.preventDefault();
                this.close();
                const pos = this.getPos();
                if (pos !== undefined) {
                    this.view.dispatch(this.view.state.tr.setSelection(TextSelection.create(this.view.state.doc, pos + 1)));
                }
                this.view.focus();
            }
        });
        t.addEventListener('paste', (e) => {
            e.preventDefault();
            document.execCommand('insertText', false, (e.clipboardData?.getData('text/plain') ?? '').replace(/\s+/g, ' '));
        });
        box.querySelector('button')!.addEventListener('click', () => this.remove());
        this.sup.after(box);
        this.box = box;
        window.setTimeout(() => box.classList.add('open'), 20);
        if (focus) {
            window.setTimeout(() => {
                t.focus();
                const r = document.createRange();
                r.selectNodeContents(t);
                r.collapse(false);
                const s = getSelection();
                s?.removeAllRanges();
                s?.addRange(r);
            }, prefersReducedMotion() ? 0 : 60);
        }
    }

    close(now = false) {
        if (openFn === this) {
            openFn = null;
        }
        const box = this.box;
        this.box = null;
        this.sup.classList.remove('on');
        if (!box) {
            return;
        }
        box.classList.remove('open');
        if (now || prefersReducedMotion()) {
            box.remove();
        } else {
            window.setTimeout(() => box.remove(), 320);
        }
    }

    remove() {
        this.close(true);
        const pos = this.getPos();
        const done = () => {
            const p = this.getPos();
            if (p !== undefined) {
                this.view.dispatch(this.view.state.tr.delete(p, p + 1));
            }
            this.view.focus();
        };
        if (pos === undefined) {
            return;
        }
        if (prefersReducedMotion()) {
            done();
        } else {
            this.sup.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.6)' }], { duration: 220 }).onfinish = done;
        }
    }

    update(node: PMNode, decos: readonly Decoration[]) {
        if (node.type !== this.node.type) {
            return false;
        }
        this.node = node;
        this.number(decos);

        return true;
    }

    stopEvent(e: Event) {
        return !!this.box && this.box.contains(e.target as Node);
    }

    ignoreMutation() {
        return true;
    }

    destroy() {
        if (openFn === this) {
            openFn = null;
        }
    }
}

/* ---------------------------------------------------------------- pictures: the picture, a credit line, Remove */
class ImageView implements NodeView {
    dom: HTMLElement;

    constructor(
        private node: PMNode,
        private view: EditorView,
        private getPos: () => number | undefined,
    ) {
        const fig = document.createElement('figure');
        fig.className = 'w-fig';
        fig.contentEditable = 'false';
        fig.innerHTML = '<img alt=""><figcaption><span class="cr" contenteditable="true" data-ph="Credit"></span><button class="w-act" type="button">Remove</button></figcaption>';
        fig.querySelector('img')!.src = node.attrs.src;
        const cr = fig.querySelector<HTMLElement>('.cr')!;
        cr.textContent = node.attrs.credit;
        cr.addEventListener('input', () => {
            const pos = this.getPos();
            if (pos !== undefined) {
                this.view.dispatch(this.view.state.tr.setNodeMarkup(pos, undefined, { ...this.node.attrs, credit: cr.textContent ?? '' }));
            }
        });
        cr.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.view.focus();
            }
        });
        fig.querySelector('button')!.addEventListener('click', () => {
            const pos = this.getPos();
            if (pos !== undefined) {
                this.view.dispatch(this.view.state.tr.delete(pos, pos + this.node.nodeSize));
                this.view.focus();
            }
        });
        this.dom = fig;
    }

    update(node: PMNode) {
        if (node.type !== this.node.type) {
            return false;
        }
        this.node = node;

        return true;
    }

    stopEvent(e: Event) {
        return (e.target as HTMLElement).closest?.('figcaption') !== null;
    }

    ignoreMutation() {
        return true;
    }
}

/* ---------------------------------------------------------------- paste: plain text in paragraphs (lines, in a poem) */
function plainText(kind: Kind) {
    return (text: string): Slice => {
        const clean = text.replace(/\r/g, '');
        const block = kind === 'poem' ? N.poemLine : N.paragraph;
        const parts = kind === 'poem' ? clean.split('\n') : clean.split(/\n{2,}/).map((p) => p.replace(/\n/g, ' '));
        const nodes = parts.map((p) => block.create({ id: newId() }, p ? schema.text(p) : null));

        return new Slice(Fragment.from(nodes), 1, 1);
    };
}

export type EditorHooks = { link: () => boolean };

/**
 * Everything the page's editor does, for this kind of writing.
 */
export function editorPlugins(kind: Kind, hooks: EditorHooks): Plugin[] {
    const essay = kind === 'essay';
    const rules = [
        /* "> " starts a quote */
        new InputRule(/^>\s$/, (state, _m, start, end) => {
            const tr = state.tr.delete(start, end);
            const $s = tr.doc.resolve(start);

            return tr.setBlockType($s.before(1) + 1, $s.after(1) - 1, N.blockquote, { id: newId() });
        }),
        ...(essay ? [wrappingInputRule(/^[-*]\s$/, N.bulletList)] : []),
    ];

    return [
        ids,
        look,
        history(),
        inputRules({ rules }),
        keymap({
            'Mod-z': undo,
            'Mod-Shift-z': redo,
            'Mod-y': redo,
            'Mod-b': toggleBold,
            'Mod-i': toggleItalic,
            'Mod-k': () => hooks.link(),
            'Mod-Alt-f': addFootnote,
            'Shift-Enter': (state, dispatch) => {
                dispatch?.(state.tr.replaceSelectionWith(N.hardBreak.create()).scrollIntoView());

                return true;
            },
            Enter: chainCommands(breakOnStars, leaveQuote(true, kind), ...(essay ? [splitListItem(N.listItem)] : [])),
            Backspace: leaveQuote(false, kind),
            Tab: kind === 'poem' ? indent(1) : essay ? sinkListItem(N.listItem) : () => false,
            'Shift-Tab': kind === 'poem' ? indent(-1) : essay ? liftListItem(N.listItem) : () => false,
            Escape: () => {
                closeFootnote();

                return false;
            },
        }),
        keymap(baseKeymap),
        new Plugin({
            props: {
                nodeViews: {
                    footnote: (node, view, getPos, decos) => new FootnoteView(node, view, getPos, decos),
                    image: (node, view, getPos) => new ImageView(node, view, getPos),
                },
                clipboardTextParser: plainText(kind),
            },
        }),
    ];
}

/** A poem is all lines: paragraphs loaded into a poem become lines. */
export function asPoem(doc: PMNode): PMNode {
    const blocks: PMNode[] = [];
    doc.forEach((n) => blocks.push(n.type === N.paragraph ? N.poemLine.create({ id: n.attrs.id, indent: 0 }, n.content) : n));

    return N.doc.create(null, blocks);
}

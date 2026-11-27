import { baseKeymap, toggleMark } from 'prosemirror-commands';
import { history, redo, undo } from 'prosemirror-history';
import { InputRule, inputRules } from 'prosemirror-inputrules';
import { keymap } from 'prosemirror-keymap';
import { MarkType, Node as PMNode, Schema } from 'prosemirror-model';
import { Command, EditorState, Plugin, Transaction } from 'prosemirror-state';
import { newId, schema as pageSchema } from '../editor/schema';

/** A note is paragraphs of text with italics, bold and links (and line breaks): the page's own pieces, nothing more. */
export const noteSchema = new Schema({
    nodes: {
        doc: { content: 'paragraph+' },
        paragraph: pageSchema.spec.nodes.get('paragraph')!,
        text: { group: 'inline' },
        hardBreak: pageSchema.spec.nodes.get('hardBreak')!,
    },
    marks: pageSchema.spec.marks,
});

const M = noteSchema.marks;

export type Json = { type: string; attrs?: Record<string, unknown>; content?: Json[]; text?: string; marks?: unknown[] };

/** A stored note to the editor's document (anything a note can't hold is left out). */
export function noteDoc(body: Json | null): PMNode {
    try {
        const doc = PMNode.fromJSON(noteSchema, body ?? { type: 'doc' });
        doc.check();

        return doc;
    } catch {
        return noteSchema.node('doc', null, [noteSchema.node('paragraph', { id: newId() })]);
    }
}

export const isEmptyDoc = (doc: PMNode) => !doc.textContent.trim();

/** Every paragraph keeps a unique id. */
const ids = new Plugin({
    appendTransaction(trs, _old, state) {
        if (!trs.some((t) => t.docChanged)) {
            return null;
        }
        const seen = new Set<string>();
        let tr: Transaction | null = null;
        state.doc.forEach((node, pos) => {
            const id = node.attrs.id as string | null;
            if (!id || seen.has(id)) {
                tr = (tr ?? state.tr).setNodeMarkup(pos, undefined, { ...node.attrs, id: newId() });
            } else {
                seen.add(id);
            }
        });
        if (tr) {
            (tr as Transaction).setMeta('addToHistory', false);
        }

        return tr;
    },
});

/** **x** and *x* (or _x_) become bold and italic as she types the closing mark. */
function markRule(re: RegExp, mark: MarkType, delim: number): InputRule {
    return new InputRule(re, (state, match, start, end) => {
        const inner = match[1];
        const lead = match[0].length - inner.length - 2 * delim;
        const from = start + lead;
        const tr = state.tr;
        tr.delete(from + delim + inner.length, end);
        tr.delete(from, from + delim);
        tr.addMark(from, from + inner.length, mark.create());
        tr.removeStoredMark(mark);

        return tr;
    });
}

export const toggleBold = toggleMark(M.bold);
export const toggleItalic = toggleMark(M.italic);

/** Links the selected words (or unlinks them when the address is empty). */
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

export const markActive = (state: EditorState, type: 'bold' | 'italic' | 'link') => {
    const { from, to, empty, $from } = state.selection;
    const mark = M[type];

    return empty ? !!mark.isInSet(state.storedMarks || $from.marks()) : state.doc.rangeHasMark(from, to, mark);
};

/** The note editor's plugins; Ctrl K asks the page for a link. */
export function notePlugins(link: () => boolean): Plugin[] {
    return [
        ids,
        history(),
        inputRules({
            rules: [
                markRule(/\*\*([^*]+)\*\*$/, M.bold, 2),
                markRule(/(?:^|[^*])\*([^*\s][^*]*)\*$/, M.italic, 1),
                markRule(/(?:^|[^\w])_([^_\s][^_]*)_$/, M.italic, 1),
            ],
        }),
        keymap({
            'Mod-z': undo,
            'Mod-Shift-z': redo,
            'Mod-y': redo,
            'Mod-b': toggleBold,
            'Mod-i': toggleItalic,
            'Mod-k': () => link(),
            'Shift-Enter': (state, dispatch) => {
                dispatch?.(state.tr.replaceSelectionWith(noteSchema.nodes.hardBreak.create()).scrollIntoView());

                return true;
            },
        }),
        keymap(baseKeymap),
    ];
}

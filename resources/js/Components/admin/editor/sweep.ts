import { Node as PMNode } from 'prosemirror-model';
import { EditorView } from 'prosemirror-view';

/**
 * Sweep, as approved: plain names typed while writing become their marked forms. Whole words only, capitals kept
 * ("XU WEI" → "XÚ WĒI", "xu wei" → "xú wēi"), possessives come along, and a name with two readings is picked
 * each time it's found.
 */
export type SweepName = { id: number | null; plain: string; readings: string[]; from: string | null };
export type Term = { a: string; opts: string[]; once?: boolean };

/** One place a name was found: in this page (editor positions) or another page (a path into its stored JSON). */
export type Hit = {
    key: string;
    page: number;
    term: number;
    match: string;
    ctx: [string, string, string];
    pre: boolean;
    post: boolean;
    note: boolean;
    from?: number;
    to?: number;
    footnotePos?: number;
    path?: number[];
    index: number;
};

const escRx = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
export const wordRx = (p: string) => new RegExp('(?<![\\p{L}\\p{M}\\p{N}])' + escRx(p) + '(?![\\p{L}\\p{M}\\p{N}])', 'giu');

export function keepCase(m: string, rep: string): string {
    if (m.length > 1 && m === m.toUpperCase() && m !== m.toLowerCase()) {
        return rep.toUpperCase();
    }
    if (m[0] === m[0].toLowerCase() && m[0] !== m[0].toUpperCase()) {
        return rep[0].toLowerCase() + rep.slice(1);
    }

    return rep;
}

/** Names whose marked form differs from how she types them, and any one-off find-and-replace. */
export function termsOf(names: SweepName[], once: [string, string][]): Term[] {
    return [
        ...names.filter((n) => n.readings.some((r) => r !== n.plain)).map((n) => ({ a: n.plain, opts: n.readings })),
        ...once.map(([a, b]) => ({ a, opts: [b], once: true })),
    ];
}

function scan(text: string, terms: Term[], each: (ti: number, m: RegExpMatchArray) => void) {
    terms.forEach((t, ti) => {
        for (const m of text.matchAll(wordRx(t.a))) {
            each(ti, m);
        }
    });
}

function ctxOf(text: string, i: number, len: number): Pick<Hit, 'ctx' | 'pre' | 'post'> {
    return { ctx: [text.slice(Math.max(0, i - 34), i), text.slice(i, i + len), text.slice(i + len, i + len + 34)], pre: i > 34, post: i + len + 34 < text.length };
}

/** Matches in the page being written (its text and its footnotes). */
export function hitsInView(view: EditorView, page: number, terms: Term[]): Hit[] {
    const out: Hit[] = [];
    const seen: Record<number, number> = {};
    view.state.doc.descendants((node, pos) => {
        if (node.isText) {
            const text = node.text ?? '';
            scan(text, terms, (ti, m) => {
                seen[ti] = (seen[ti] ?? 0) + 1;
                out.push({ key: `${page}|${ti}|${seen[ti]}`, page, term: ti, match: m[0], ...ctxOf(text, m.index!, m[0].length), note: false, from: pos + m.index!, to: pos + m.index! + m[0].length, index: m.index! });
            });
        } else if (node.type.name === 'footnote') {
            const text = String(node.attrs.text ?? '');
            scan(text, terms, (ti, m) => {
                seen[ti] = (seen[ti] ?? 0) + 1;
                out.push({ key: `${page}|${ti}|${seen[ti]}`, page, term: ti, match: m[0], ...ctxOf(text, m.index!, m[0].length), note: true, footnotePos: pos, index: m.index! });
            });
        }

        return true;
    });

    return out;
}

type Json = { type: string; text?: string; attrs?: Record<string, unknown>; content?: Json[] };

/** Matches in another page's stored JSON. */
export function hitsInJson(doc: Json | null, page: number, terms: Term[]): Hit[] {
    const out: Hit[] = [];
    const seen: Record<number, number> = {};
    const walk = (node: Json, path: number[]) => {
        if (node.type === 'text') {
            const text = node.text ?? '';
            scan(text, terms, (ti, m) => {
                seen[ti] = (seen[ti] ?? 0) + 1;
                out.push({ key: `${page}|${ti}|${seen[ti]}`, page, term: ti, match: m[0], ...ctxOf(text, m.index!, m[0].length), note: false, path, index: m.index! });
            });
        } else if (node.type === 'footnote') {
            const text = String(node.attrs?.text ?? '');
            scan(text, terms, (ti, m) => {
                seen[ti] = (seen[ti] ?? 0) + 1;
                out.push({ key: `${page}|${ti}|${seen[ti]}`, page, term: ti, match: m[0], ...ctxOf(text, m.index!, m[0].length), note: true, path, index: m.index! });
            });
        }
        node.content?.forEach((c, i) => walk(c, [...path, i]));
    };
    if (doc) {
        walk(doc, []);
    }

    return out;
}

/** Replace in the page being written, last first so earlier positions stay true. */
export function replaceInView(view: EditorView, hits: { hit: Hit; rep: string }[]) {
    const tr = view.state.tr;
    const notes = new Map<number, { node: PMNode; list: { hit: Hit; rep: string }[] }>();
    hits.filter((h) => !h.hit.note)
        .sort((a, b) => b.hit.from! - a.hit.from!)
        .forEach(({ hit, rep }) => tr.insertText(rep, hit.from!, hit.to!));
    hits.filter((h) => h.hit.note).forEach((h) => {
        const pos = tr.mapping.map(h.hit.footnotePos!);
        const node = tr.doc.nodeAt(pos);
        if (node) {
            const e = notes.get(pos) ?? { node, list: [] };
            e.list.push(h);
            notes.set(pos, e);
        }
    });
    notes.forEach(({ node, list }, pos) => {
        let text = String(node.attrs.text ?? '');
        list.sort((a, b) => b.hit.index - a.hit.index).forEach(({ hit, rep }) => (text = text.slice(0, hit.index) + rep + text.slice(hit.index + hit.match.length)));
        tr.setNodeMarkup(pos, undefined, { ...node.attrs, text });
    });
    if (tr.docChanged) {
        view.dispatch(tr);
    }
}

/** Replace in another page's JSON (a copy comes back). */
export function replaceInJson(doc: Json, hits: { hit: Hit; rep: string }[]): Json {
    const copy: Json = JSON.parse(JSON.stringify(doc));
    const byPath = new Map<string, { hit: Hit; rep: string }[]>();
    hits.forEach((h) => {
        const k = h.hit.path!.join('.');
        byPath.set(k, [...(byPath.get(k) ?? []), h]);
    });
    byPath.forEach((list, k) => {
        let node = copy;
        (k ? k.split('.').map(Number) : []).forEach((i) => (node = node.content![i]));
        const field = node.type === 'footnote' ? 'note' : 'text';
        let text = field === 'note' ? String(node.attrs?.text ?? '') : node.text ?? '';
        list.sort((a, b) => b.hit.index - a.hit.index).forEach(({ hit, rep }) => (text = text.slice(0, hit.index) + rep + text.slice(hit.index + hit.match.length)));
        if (field === 'note') {
            node.attrs = { ...node.attrs, text };
        } else {
            node.text = text;
        }
    });

    return copy;
}

/** The plain names faintly marked while writing (a CSS highlight; nothing in the text changes). */
export function markNames(view: EditorView | null, names: SweepName[], on: boolean) {
    const registry = (CSS as unknown as { highlights?: Map<string, unknown> }).highlights;
    if (!registry || !view) {
        return;
    }
    registry.delete('eyiName');
    if (!on) {
        return;
    }
    const terms = names.filter((n) => n.readings.some((r) => r !== n.plain));
    const H = (window as unknown as { Highlight: new () => { add: (r: Range) => void } }).Highlight;
    const hl = new H();
    const walker = document.createTreeWalker(view.dom, NodeFilter.SHOW_TEXT, { acceptNode: (n) => ((n.parentElement?.closest('.w-fnc,figure,.brk') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT)) });
    while (walker.nextNode()) {
        const n = walker.currentNode as Text;
        terms.forEach((t) => {
            for (const m of n.data.matchAll(wordRx(t.plain))) {
                const r = new Range();
                r.setStart(n, m.index!);
                r.setEnd(n, m.index! + m[0].length);
                hl.add(r);
            }
        });
    }
    registry.set('eyiName', hl);
}

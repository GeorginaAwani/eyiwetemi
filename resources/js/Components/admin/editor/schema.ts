import { Node as PMNode, Schema } from 'prosemirror-model';

/** A new block id, the way stored blocks are named (comments and reviewers' marks hold on to them). */
export const newId = () => 'b' + Math.random().toString(16).slice(2, 12).padEnd(10, '0');

const id = { default: null as string | null };
const getId = (dom: HTMLElement | string) => (typeof dom === 'string' ? null : dom.getAttribute('data-id'));

/**
 * The page's schema. In the editor a quote is a block of text and a list item a paragraph; on disk the quote holds
 * one paragraph (see toStored / fromStored), as the reader expects.
 */
export const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: {
            group: 'block',
            content: 'inline*',
            attrs: { id },
            parseDOM: [{ tag: 'p', getAttrs: (d) => ({ id: getId(d) }) }],
            toDOM: (n) => ['p', { 'data-id': n.attrs.id }, 0],
        },
        poemLine: {
            group: 'block',
            content: 'inline*',
            attrs: { id, indent: { default: 0 } },
            parseDOM: [{ tag: 'p.ln', priority: 60, getAttrs: (d) => ({ id: getId(d), indent: Number((d as HTMLElement).dataset.i ?? 0) }) }],
            toDOM: (n) => ['p', { class: 'ln', 'data-i': String(n.attrs.indent), 'data-id': n.attrs.id }, 0],
        },
        blockquote: {
            group: 'block',
            content: 'inline*',
            attrs: { id },
            defining: true,
            parseDOM: [{ tag: 'blockquote', getAttrs: (d) => ({ id: getId(d) }) }],
            toDOM: (n) => ['blockquote', { 'data-id': n.attrs.id }, 0],
        },
        sceneBreak: {
            group: 'block',
            attrs: { id },
            atom: true,
            selectable: true,
            parseDOM: [{ tag: 'div.brk' }],
            toDOM: (n) => ['div', { class: 'brk r2b', 'data-brk': '', 'data-id': n.attrs.id, contenteditable: 'false' }, ['i'], ['b'], ['i']],
        },
        bulletList: {
            group: 'block',
            content: 'listItem+',
            attrs: { id },
            parseDOM: [{ tag: 'ul' }],
            toDOM: (n) => ['ul', { 'data-id': n.attrs.id }, 0],
        },
        listItem: {
            content: 'paragraph',
            defining: true,
            parseDOM: [{ tag: 'li' }],
            toDOM: () => ['li', 0],
        },
        image: {
            group: 'block',
            attrs: { id, mediaId: { default: null }, src: { default: '' }, credit: { default: '' } },
            atom: true,
            selectable: true,
            draggable: false,
            toDOM: (n) => ['figure', { class: 'w-fig' }, ['img', { src: n.attrs.src, alt: '' }]],
        },
        text: { group: 'inline' },
        hardBreak: {
            group: 'inline',
            inline: true,
            selectable: false,
            parseDOM: [{ tag: 'br' }],
            toDOM: () => ['br'],
        },
        footnote: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { id, text: { default: '' } },
            toDOM: (n) => ['sup', { class: 'fn', 'data-f': n.attrs.id }],
        },
    },
    marks: {
        italic: {
            parseDOM: [{ tag: 'i' }, { tag: 'em' }, { style: 'font-style=italic' }],
            toDOM: () => ['i', 0],
        },
        bold: {
            parseDOM: [{ tag: 'b' }, { tag: 'strong' }, { style: 'font-weight', getAttrs: (v) => (/^(bold(er)?|[5-9]\d{2,})$/.test(v as string) ? null : false) }],
            toDOM: () => ['b', 0],
        },
        link: {
            attrs: { href: {} },
            inclusive: false,
            parseDOM: [{ tag: 'a[href]', getAttrs: (d) => ({ href: (d as HTMLElement).getAttribute('href') }) }],
            toDOM: (m) => ['a', { href: m.attrs.href, rel: 'noopener noreferrer', target: '_blank' }, 0],
        },
    },
});

type Json = { type: string; attrs?: Record<string, unknown>; content?: Json[]; text?: string; marks?: unknown[] };

/** Stored JSON to the editor's: a quote's paragraph opens into the quote; pictures get their address. */
export function fromStored(doc: Json, images: Record<number, string>): PMNode {
    const map = (n: Json): Json => {
        if (n.type === 'blockquote') {
            return { type: 'blockquote', attrs: { id: n.attrs?.id ?? null }, content: (n.content ?? []).flatMap((p, i) => [...(i ? [{ type: 'hardBreak' }] : []), ...(p.content ?? [])]) };
        }
        if (n.type === 'image') {
            const mediaId = n.attrs?.mediaId as number | undefined;

            return { type: 'image', attrs: { ...n.attrs, src: (mediaId && images[mediaId]) || (n.attrs?.src as string) || '' } };
        }

        return n.content ? { ...n, content: n.content.map(map) } : n;
    };
    const clean = map(doc);
    if (!clean.content?.length) {
        clean.content = [{ type: 'paragraph', attrs: { id: newId() } }];
    }

    return PMNode.fromJSON(schema, clean);
}

/** The editor's document as stored: the quote holds one paragraph; pictures keep only their media id and credit. */
export function toStored(doc: PMNode): Json {
    const map = (n: Json): Json => {
        if (n.type === 'blockquote') {
            return { type: 'blockquote', attrs: n.attrs, content: [{ type: 'paragraph', attrs: { id: `${n.attrs?.id}-q` }, ...(n.content ? { content: n.content } : {}) }] };
        }
        if (n.type === 'image') {
            return { type: 'image', attrs: { id: n.attrs?.id, mediaId: n.attrs?.mediaId, credit: n.attrs?.credit ?? '' } };
        }

        return n.content ? { ...n, content: n.content.map(map) } : n;
    };

    return map(doc.toJSON() as Json);
}

import { ReactNode, useEffect, useRef, useState } from 'react';
import { bye, EASE, inA, MONTH, pd, turn } from '../notes/lines';
import { prefersReducedMotion as RM } from '../../../lib/motion';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { Area, Doc, DocSection, I, isLink, newId, same } from './kit';

const dLong = (d: string) => `${pd(d).getDate()} ${MONTH[pd(d).getMonth()]} ${pd(d).getFullYear()}`;

/** The sections she has changed since the page went out (new ones included). */
export function editedIds(d: Doc, live: Doc | null): string[] {
    return d.sections.filter((x) => {
        const l = live?.sections.find((y) => y.id === x.id);

        return !l || l.heading !== x.heading || l.html !== x.html;
    }).map((x) => x.id);
}

/**
 * Privacy and Terms: sections of a heading and formatted text (links included). A change that matters gets one
 * line readers see on the page, in a draft note, and in their notifications.
 */
export default function DocTab({ s, k, mattersBox }: { s: SiteCtx; k: 'privacy' | 'terms'; mattersBox: ReactNode }) {
    const page = s.page<Doc>(k);
    const v = page.draft;
    const live = page.live;
    const box = useRef<HTMLDivElement>(null);
    const upd = (fn: (d: Doc) => void) => s.update<Doc>(k, fn);
    const updEl = useRef<HTMLDivElement>(null);
    const [upShown, setUpShown] = useState(live?.updated_on ?? v.updated_on);
    const none = !v.sections.length;
    const [emptyShown, setEmptyShown] = useState(none);
    useLinkBubble();

    useEffect(() => {
        const u = live?.updated_on ?? v.updated_on;
        if (u !== upShown) {
            turn(updEl.current, () => setUpShown(u));
        }
    }, [live?.updated_on]);
    useEffect(() => {
        if (none !== emptyShown) {
            setEmptyShown(none);
            if (none) {
                s.after(() => inA(document.getElementById('spPv0')));
            }
        }
    }, [none]);

    function add() {
        const id = newId('x');
        upd((d) => d.sections.splice(Math.max(0, d.sections.length - 1), 0, { id, heading: '', html: '' }));
        s.after(() => {
            const r = document.querySelector<HTMLElement>(`.sp-pv[data-id="${id}"]`);
            inA(r);
            r?.querySelector<HTMLElement>('[data-h]')?.focus();
        });
    }

    return (
        <>
            {mattersBox}
            <div className="sp-upd" id="spUpd" ref={updEl}>
                {I.clock14}
                <span>Updated on the page: {dLong(upShown)}</span>
            </div>
            <div className={`sp-wc${s.matters ? ' open' : ''}`} id="spWc" aria-hidden={!s.matters}>
                <div>
                    <div className="in">
                        <label className="k-fl" htmlFor="spWi">
                            What changed
                        </label>
                        <div>
                            <div className="row">
                                <span className="k-in sp-in">
                                    <input id="spWi" maxLength={120} autoComplete="off" placeholder="One line readers will see" value={s.line} tabIndex={s.matters ? 0 : -1} onChange={(e) => s.setLine(e.target.value)} />
                                </span>
                                <span className="sp-cnt" id="spWn">
                                    {s.line.length} / 120
                                </span>
                            </div>
                            <div className="wh">
                                {I.note17}
                                <span>Goes in a draft note and to everyone with an account.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div className="sp-pvs" id="spPvs" ref={box}>
                {v.sections.map((sec) => (
                    <Section key={sec.id} sec={sec} edited={!same(sec, live?.sections.find((x) => x.id === sec.id))} s={s} upd={upd} />
                ))}
            </div>
            <p className="sp-pv0" id="spPv0" hidden={!none}>
                {k === 'terms' ? 'Terms need' : 'Privacy needs'} at least one section.
            </p>
            <div className="sp-addrow">
                <button className="w-act sp-add" type="button" onClick={add}>
                    {I.plus16}
                    <span>Add a section</span>
                </button>
            </div>
        </>
    );
}

function Section({ sec, edited, s, upd }: { sec: DocSection; edited: boolean; s: SiteCtx; upd: (fn: (d: Doc) => void) => void }) {
    const ta = useRef<HTMLDivElement>(null);
    const [focus, setFocus] = useState(false);
    const set = (fn: (x: DocSection) => void) => upd((d) => fn(d.sections.find((x) => x.id === sec.id)!));

    return (
        <div className={`sp-pv${focus ? ' on' : ''}`} data-id={sec.id} data-part={sec.id}>
            <div className="sp-pvh">
                <span className="k-in sp-in sm sp-hw">
                    <textarea
                        rows={1}
                        placeholder="Heading"
                        aria-label="Section heading"
                        data-h
                        defaultValue={sec.heading}
                        onChange={(e) => {
                            const h = e.target.value.replace(/\s*\n\s*/g, ' ');
                            set((x) => (x.heading = h));
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                ta.current?.focus();
                            }
                        }}
                    />
                </span>
                <span className="mk" data-on={edited}>
                    {edited && <span title="Edited, not out yet">{I.pen14}</span>}
                </span>
                <button
                    className="w-act sp-pvx"
                    type="button"
                    aria-haspopup="dialog"
                    onClick={(e) => {
                        const r = e.currentTarget.closest<HTMLElement>('.sp-pv');
                        s.confirm(e.currentTarget, 'Remove this section?', 'Readers still see it until you publish.', 'Remove', () => {
                            const go = () => upd((d) => (d.sections = d.sections.filter((x) => x.id !== sec.id)));
                            if (r) {
                                bye(r, go);
                            } else {
                                go();
                            }
                        });
                    }}
                >
                    {I.x14}
                    <span>Remove</span>
                </button>
            </div>
            <Area
                boxRef={ta}
                id={'spV' + sec.id}
                value={sec.html}
                placeholder="What you want to say"
                cls="rich"
                rich
                label={'Text of ' + sec.heading}
                onText={(h) => set((x) => (x.html = h))}
                onKeyDown={(e) => {
                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                        e.preventDefault();
                        window.dispatchEvent(new CustomEvent('sp:link', { detail: e.currentTarget }));
                    }
                }}
                onFocus={() => setFocus(true)}
                onBlur={() => setFocus(false)}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ the link bubble over selected words (Ctrl K too) */
function useLinkBubble() {
    useEffect(() => {
        let b: HTMLDivElement | null = null;
        let rg: Range | null = null;
        let ta: HTMLElement | null = null;
        let t = 0;
        const esc = (x: string) => x.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
        /* the bubble is plain markup over her text, so its icons are too */
        const L = {
            x15: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
            pen15: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg>',
            link15: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>',
        };
        const linkOf = (r: Range) => {
            const n = r.startContainer;

            return (n.nodeType === 3 ? n.parentElement : (n as Element))?.closest('a') ?? null;
        };
        function hide() {
            if (!b) {
                return;
            }
            const x = b;
            b = null;
            if (RM()) {
                x.remove();

                return;
            }
            x.style.pointerEvents = 'none';
            x.animate([{ opacity: 1 }, { opacity: 0, transform: 'translateY(3px)' }], { duration: 140, easing: 'ease-in', fill: 'forwards' });
            window.setTimeout(() => x.remove(), 150);
        }
        function show(area: HTMLElement, edit = false) {
            const s = window.getSelection();
            if (!s?.rangeCount) {
                return;
            }
            const r = s.getRangeAt(0);
            if (!area.contains(r.commonAncestorContainer)) {
                return;
            }
            const a = linkOf(r);
            if (r.collapsed && !a && !edit) {
                hide();

                return;
            }
            rg = r.cloneRange();
            ta = area;
            const href = a?.getAttribute('href') ?? '';
            const html = edit
                ? `<span class="k-in sp-in sm"><input placeholder="Paste a link" aria-label="Link" value="${esc(href)}" inputmode="url" spellcheck="false" autocomplete="off" autocapitalize="off"></span><button class="k-btn sm" type="button" data-lk="ok"><span>${a ? 'Save' : 'Add'}</span></button><button class="ad-ic sp-lkx" type="button" data-lk="x" aria-label="Close">${L.x15}</button><p class="k-err" hidden>That isn’t a link.</p>`
                : a
                  ? `<a class="sp-lkh" href="${esc(href)}" target="_blank" rel="noopener">${esc(href.replace(/^(https?:\/\/|mailto:)/, ''))}</a><button class="w-act" type="button" data-lk="edit">${L.pen15}<span>Edit</span></button><button class="w-act" type="button" data-lk="rm">${L.x15}<span>Remove</span></button>`
                  : `<button class="w-act" type="button" data-lk="edit">${L.link15}<span>Link</span></button>`;
            const fresh = !b;
            if (!b) {
                b = document.createElement('div');
                b.className = 'sp-lkb';
                b.setAttribute('role', 'dialog');
                b.setAttribute('aria-label', 'Link');
                document.body.append(b);
                wire(b);
            }
            b.classList.toggle('ed', edit);
            b.innerHTML = html;
            const R = (a && !edit ? a : r).getBoundingClientRect();
            const w = b.offsetWidth;
            const h = b.offsetHeight;
            b.style.left = Math.max(12, Math.min(window.innerWidth - w - 12, R.left + R.width / 2 - w / 2)) + window.scrollX + 'px';
            b.style.top = (R.top - h - 10 < 8 ? R.bottom + 10 : R.top - h - 10) + window.scrollY + 'px';
            if (fresh) {
                b.animate(RM() ? [{ opacity: 0 }, { opacity: 1 }] : [{ opacity: 0, transform: 'translateY(4px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: RM() ? 120 : 180, easing: EASE });
            }
            if (edit) {
                const ip = b.querySelector('input')!;
                window.setTimeout(() => {
                    ip.focus();
                    ip.select();
                }, 0);
            }
        }
        const back = () => {
            const area = ta;
            hide();
            if (area && rg) {
                area.focus();
                const s = window.getSelection();
                s?.removeAllRanges();
                s?.addRange(rg);
            }
        };
        function apply() {
            if (!b || !rg || !ta) {
                return;
            }
            const ip = b.querySelector('input')!;
            const val = ip.value.trim();
            const er = b.querySelector<HTMLElement>('.k-err')!;
            if (!val || !isLink(val, true)) {
                if (er.hidden) {
                    er.hidden = false;
                    inA(er);
                }
                ip.closest('.k-in')?.classList.add('bad');

                return;
            }
            const u = /^[^\s@/]+@[^\s@]+\.[a-z]{2,}$/i.test(val) ? 'mailto:' + val : /^(https?:|mailto:|\/)/i.test(val) ? val : 'https://' + val;
            const area = ta;
            const r0 = rg;
            b.classList.remove('ed');
            area.focus();
            const s = window.getSelection()!;
            s.removeAllRanges();
            s.addRange(r0);
            const a = linkOf(r0);
            if (a) {
                a.setAttribute('href', u);
            } else if (r0.collapsed) {
                document.execCommand('insertHTML', false, `<a href="${esc(u)}">${esc(val)}</a>`);
            } else {
                document.execCommand('createLink', false, u);
            }
            area.querySelectorAll('a').forEach((x) => (x.className = 'link'));
            area.dispatchEvent(new Event('input', { bubbles: true }));
            hide();
            s.collapseToEnd();
        }
        function wire(x: HTMLDivElement) {
            x.addEventListener('mousedown', (e) => {
                if (!(e.target as Element).closest('input')) {
                    e.preventDefault();
                }
            });
            x.addEventListener('click', (e) => {
                const k = (e.target as Element).closest<HTMLElement>('[data-lk]')?.dataset.lk;
                if (!k || !rg || !ta) {
                    return;
                }
                if (k === 'edit') {
                    const s = window.getSelection()!;
                    s.removeAllRanges();
                    s.addRange(rg);
                    show(ta, true);
                } else if (k === 'rm') {
                    const s = window.getSelection()!;
                    const a = linkOf(rg);
                    s.removeAllRanges();
                    if (a) {
                        const r2 = document.createRange();
                        r2.selectNodeContents(a);
                        s.addRange(r2);
                    } else {
                        s.addRange(rg);
                    }
                    document.execCommand('unlink');
                    ta.dispatchEvent(new Event('input', { bubbles: true }));
                    hide();
                } else if (k === 'ok') {
                    apply();
                } else if (k === 'x') {
                    back();
                }
            });
            x.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.target as Element).matches('input')) {
                    e.preventDefault();
                    apply();
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    back();
                }
            });
            x.addEventListener('focusout', (e) => {
                if (b === x && !x.contains(e.relatedTarget as Node) && e.relatedTarget !== ta) {
                    hide();
                }
            });
        }
        const sel = () => {
            if (b?.classList.contains('ed')) {
                return;
            }
            const s = window.getSelection();
            let n: Node | null = s?.rangeCount ? s.getRangeAt(0).commonAncestorContainer : null;
            if (n && n.nodeType === 3) {
                n = n.parentElement;
            }
            const area = (n as Element | null)?.closest?.('.sp-pv .sp-ta') as HTMLElement | null;
            if (!area) {
                if (b && !b.contains(document.activeElement)) {
                    hide();
                }

                return;
            }
            window.clearTimeout(t);
            t = window.setTimeout(() => show(area), 180);
        };
        const scroll = () => {
            if (b && !b.classList.contains('ed')) {
                hide();
            }
        };
        const down = (e: MouseEvent) => {
            if (b && !b.contains(e.target as Node) && !(!b.classList.contains('ed') && ta?.contains(e.target as Node))) {
                hide();
            }
        };
        const ctrlK = (e: Event) => show((e as CustomEvent<HTMLElement>).detail, true);
        document.addEventListener('selectionchange', sel);
        window.addEventListener('scroll', scroll, { passive: true });
        document.addEventListener('mousedown', down, true);
        window.addEventListener('sp:link', ctrlK);

        return () => {
            window.clearTimeout(t);
            document.removeEventListener('selectionchange', sel);
            window.removeEventListener('scroll', scroll);
            document.removeEventListener('mousedown', down, true);
            window.removeEventListener('sp:link', ctrlK);
            b?.remove();
        };
    }, []);
}


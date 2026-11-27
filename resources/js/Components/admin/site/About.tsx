import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Ring } from '../Icons';
import { bye, inA, xsrf } from '../notes/lines';
import { prefersReducedMotion as RM } from '../../../lib/motion';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { About, Area, Card, dragSort, flipFrom, I, moved, newId, plural, Row, same } from './kit';

type Para = About['sections'][number]['paragraphs'][number];

/** About me: her portrait, the opening, her sections (with margin notes and pull quotes), the books, and what Home says. */
export default function AboutTab({ s }: { s: SiteCtx }) {
    const page = s.page<About>('about');
    const a = page.draft;
    const live = page.live;
    const rows = useRef<HTMLDivElement>(null);
    const [crop, setCrop] = useState<{
        src: string;
        back: HTMLElement | null;
    } | null>(null);
    const upd = (fn: (d: About) => void) => s.update<About>('about', fn);
    const keys = ['portrait', 'hello', ...a.sections.map((x) => x.id), 'books', 'home'];

    function edited(k: string): boolean {
        if (!live) {
            return true;
        }
        if (k === 'portrait') {
            return a.image !== live.image;
        }
        if (k === 'hello') {
            return !same(a.hello, live.hello);
        }
        if (k === 'books') {
            return a.books !== live.books;
        }
        if (k === 'home') {
            return !same(a.home, live.home);
        }

        return (
            !same(
                a.sections.find((x) => x.id === k),
                live.sections.find((x) => x.id === k),
            ) || a.sections.findIndex((x) => x.id === k) !== live.sections.findIndex((x) => x.id === k)
        );
    }

    /* sections move by the grip (pointer) or the arrow keys */
    function moveSection(id: string, to: number, keys: boolean) {
        const from = a.sections.findIndex((x) => x.id === id);
        to = Math.max(0, Math.min(a.sections.length - 1, to));
        if (from === to) {
            return;
        }
        const play = keys ? flipFrom(rows.current, 'k') : null;
        upd((d) => (d.sections = moved(d.sections, from, to)));
        s.after(() => {
            play?.();
            if (keys) {
                document.querySelector<HTMLElement>(`.sp-rw[data-k="${id}"] [data-grip]`)?.focus();
            }
        });
    }

    function row(k: string) {
        if (k === 'portrait') {
            return (
                <Row
                    key={k}
                    k={k}
                    icon={<span className="sp-ri">{s.portrait.draft && <img src={s.portrait.draft} alt="" />}</span>}
                    title="Portrait"
                    sub="Cropped round"
                    edited={edited(k)}
                    onOpen={() => s.openCard(k)}
                />
            );
        }
        if (k === 'hello') {
            return (
                <Row
                    key={k}
                    k={k}
                    icon={I.pen17}
                    title={a.hello.heading}
                    sub={a.hello.paragraphs[0] ?? ''}
                    meta={plural(a.hello.paragraphs.filter((p) => p.trim()).length, 'paragraph')}
                    edited={edited(k)}
                    onOpen={() => s.openCard(k)}
                />
            );
        }
        if (k === 'books') {
            return <Row key={k} k={k} icon={I.book17} title="Books that made me" sub={a.books} meta="Margin note" edited={edited(k)} onOpen={() => s.openCard(k)} />;
        }
        if (k === 'home') {
            return <Row key={k} k={k} icon={I.desk17} title="On Home" sub={a.home.quote} meta="Quote and line" edited={edited(k)} onOpen={() => s.openCard(k)} />;
        }
        const sec = a.sections.find((x) => x.id === k)!;
        const ps = sec.paragraphs.filter((p) => !p.pull_quote);
        const ns = ps.filter((p) => p.margin_note?.trim()).length;
        const i = a.sections.indexOf(sec);

        return (
            <Row
                key={k}
                k={k}
                icon={I.note17}
                title={sec.heading}
                sub={ps[0]?.text ?? ''}
                meta={plural(ps.length, 'paragraph') + (ns ? ' · ' + plural(ns, 'note') : '')}
                edited={edited(k)}
                grip={sec.heading || 'section'}
                onOpen={() => s.openCard(k)}
                onGrip={(e) => {
                    if (e.button) {
                        return;
                    }
                    e.preventDefault();
                    const r = (e.target as Element).closest<HTMLElement>('.sp-rw')!;
                    dragSort(
                        e,
                        r,
                        () => [...rows.current!.querySelectorAll<HTMLElement>(':scope>.sp-rw[data-drag]')],
                        (from, to) => from !== to && moveSection(k, to, false),
                    );
                }}
                onGripKey={(e) => {
                    if (/^Arrow(Up|Down)$/.test(e.key)) {
                        e.preventDefault();
                        moveSection(k, i + (e.key === 'ArrowUp' ? -1 : 1), true);
                    }
                }}
            />
        );
    }

    function addSection() {
        const id = newId('s');
        upd((d) =>
            d.sections.push({
                id,
                heading: '',
                paragraphs: [{ text: '', margin_note: '', pull_quote: false }],
            }),
        );
        s.after(() => {
            inA(document.querySelector(`.sp-rw[data-k="${id}"]`));
            s.openCard(id);
        });
    }

    return (
        <>
            <div className="sp-rows" id="spRows" ref={rows}>
                {keys.map((k) => (k === s.open ? <AboutCard key={'o' + k} k={k} s={s} a={a} upd={upd} onCrop={(src, back) => setCrop({ src, back })} /> : row(k)))}
            </div>
            <div className="sp-addrow">
                <button className="w-act sp-add" type="button" onClick={addSection}>
                    {I.plus16}
                    <span>Add a section</span>
                </button>
            </div>
            {crop && (
                <Crop
                    src={crop.src}
                    onClose={() => {
                        setCrop(null);
                        crop.back?.focus();
                    }}
                    onDone={(id, src) => {
                        upd((d) => (d.image = id));
                        s.setPortrait(src);
                        setCrop(null);
                        s.after(() => inA(document.querySelector('.sp-o .sp-ri')));
                    }}
                />
            )}
        </>
    );
}

/* ------------------------------------------------------------------ the open card */
function AboutCard({ k, s, a, upd, onCrop }: { k: string; s: SiteCtx; a: About; upd: (fn: (d: About) => void) => void; onCrop: (src: string, back: HTMLElement | null) => void }) {
    const close = () => s.closeCard(true);
    const file = useRef<HTMLInputElement>(null);
    const live = s.page<About>('about').live;

    if (k === 'portrait') {
        return (
            <Card k={k} head="Portrait" saved={s.saved} onClose={close}>
                <div className="sp-pcard">
                    <span className="sp-ri xl">{s.portrait.draft && <img src={s.portrait.draft} alt="Your portrait" />}</span>
                    <div className="sp-pacts">
                        <label className="w-act sp-up" htmlFor="spFile">
                            {I.up16}
                            <span>Replace</span>
                        </label>
                        <input
                            type="file"
                            id="spFile"
                            accept="image/*"
                            hidden
                            ref={file}
                            onChange={() => {
                                const x = file.current?.files?.[0];
                                if (!x) {
                                    return;
                                }
                                const r = new FileReader();
                                r.onload = () => onCrop(String(r.result), document.querySelector('.sp-up'));
                                r.readAsDataURL(x);
                                file.current!.value = '';
                            }}
                        />
                        {s.portrait.draft && (
                            <button className="w-act" type="button" onClick={(e) => onCrop(s.portrait.draft!, e.currentTarget)}>
                                {I.crop16}
                                <span>Crop</span>
                            </button>
                        )}
                        {live && a.image !== live.image && (
                            <button
                                className="w-act"
                                type="button"
                                onClick={() => {
                                    upd((d) => (d.image = live.image));
                                    s.setPortrait(s.portrait.live);
                                }}
                            >
                                {I.undo16}
                                <span>Back to the first photo</span>
                            </button>
                        )}
                    </div>
                </div>
            </Card>
        );
    }
    if (k === 'books') {
        return (
            <Card k={k} head="Margin note" saved={s.saved} onClose={close}>
                <div className="k-f sp-f">
                    <span className="k-fl" id="spBkL">
                        Books that made me
                    </span>
                    <Area id="spBk" value={a.books} placeholder="The books, in a sentence" label="Books that made me" onText={(t) => upd((d) => (d.books = t))} />
                </div>
            </Card>
        );
    }
    if (k === 'home') {
        return (
            <Card k={k} head="On Home" saved={s.saved} onClose={close}>
                <div className="k-f sp-f">
                    <span className="k-fl">Quote</span>
                    <Area id="spHq" value={a.home.quote} placeholder="A line about your writing" cls="q" label="Quote" onText={(t) => upd((d) => (d.home.quote = t))} />
                </div>
                <div className="k-f sp-f sp-gap">
                    <span className="k-fl">Line</span>
                    <Area id="spHl" value={a.home.line} placeholder="A line under it" label="Line" onText={(t) => upd((d) => (d.home.line = t))} />
                </div>
            </Card>
        );
    }

    return <ParasCard k={k} s={s} a={a} upd={upd} />;
}

/** The opening or a section: a heading and paragraphs she can add, remove and drag into order. */
function ParasCard({ k, s, a, upd }: { k: string; s: SiteCtx; a: About; upd: (fn: (d: About) => void) => void }) {
    const hello = k === 'hello';
    const sec = a.sections.find((x) => x.id === k);
    const [gen, setGen] = useState(0);
    const pl = useRef<HTMLDivElement>(null);
    const focusAt = useRef<{ i: number; grip?: boolean } | null>(null);
    const list: Para[] = hello
        ? a.hello.paragraphs.map((t) => ({
              text: t,
              margin_note: null,
              pull_quote: false,
          }))
        : (sec?.paragraphs ?? []);

    useEffect(() => {
        const f = focusAt.current;
        focusAt.current = null;
        if (!f) {
            return;
        }
        const r = pl.current?.querySelector<HTMLElement>(`.sp-bp[data-i="${f.i}"]`);
        if (!r) {
            return;
        }
        if (f.grip) {
            r.querySelector<HTMLElement>('[data-pgrip]')?.focus();

            return;
        }
        inA(r);
        const ta = r.querySelector<HTMLElement>('.sp-ta');
        if (ta) {
            ta.focus();
            const sel = window.getSelection();
            sel?.selectAllChildren(ta);
            sel?.collapseToEnd();
        }
    }, [gen]);

    if (!hello && !sec) {
        return null;
    }
    const setList = (fn: (l: Para[]) => Para[]) =>
        upd((d) => {
            if (hello) {
                d.hello.paragraphs = fn(
                    d.hello.paragraphs.map((t) => ({
                        text: t,
                        margin_note: null,
                        pull_quote: false,
                    })),
                ).map((p) => p.text);
            } else {
                const x = d.sections.find((y) => y.id === k)!;
                x.paragraphs = fn(x.paragraphs);
            }
        });
    const restructure = (fn: (l: Para[]) => Para[], focus: { i: number; grip?: boolean } | null) => {
        setList(fn);
        focusAt.current = focus;
        setGen((g) => g + 1);
    };
    const addP = (quote: boolean, at?: number) => {
        const i = at ?? list.length;
        restructure((l) => [...l.slice(0, i), { text: '', margin_note: hello ? null : '', pull_quote: quote }, ...l.slice(i)], { i });
    };
    const rmP = (i: number, toPrev: boolean) => {
        const r = pl.current?.querySelector<HTMLElement>(`.sp-bp[data-i="${i}"]`);
        const go = () => restructure((l) => l.filter((_, j) => j !== i), toPrev ? { i: Math.max(0, i - 1) } : null);
        if (r && !toPrev) {
            bye(r, go);
        } else {
            go();
        }
    };
    const moveP = (from: number, to: number, keys: boolean) => {
        to = Math.max(0, Math.min(list.length - 1, to));
        if (from === to) {
            return;
        }
        const order = list.map((_, j) => j);
        const [m] = order.splice(from, 1);
        order.splice(to, 0, m);
        const play = keys ? flipFrom(pl.current, 'i') : null;
        restructure((l) => moved(l, from, to), keys ? { i: to, grip: true } : null);
        s.after(() => play?.((nk) => String(order[Number(nk)])));
    };

    const pgrip = (i: number) => (
        <button
            className="sp-pg"
            type="button"
            data-pgrip
            aria-label={`Move paragraph ${i + 1}. Use the up and down arrows`}
            onKeyDown={(e) => {
                if (/^Arrow(Up|Down)$/.test(e.key)) {
                    e.preventDefault();
                    moveP(i, i + (e.key === 'ArrowUp' ? -1 : 1), true);
                }
            }}
            onPointerDown={(e) => {
                if (e.button) {
                    return;
                }
                e.preventDefault();
                const r = (e.target as Element).closest<HTMLElement>('.sp-bp')!;
                dragSort(
                    e,
                    r,
                    () => [...pl.current!.querySelectorAll<HTMLElement>(':scope>.sp-bp')],
                    (from, to) => from !== to && moveP(from, to, false),
                );
            }}
        >
            {I.grip15}
        </button>
    );
    const rm = (i: number, what: string) => (
        <button
            className="ad-ic sp-prm"
            type="button"
            aria-label={`Remove ${what}`}
            onClick={(e) => {
                if (list.length <= 1) {
                    const ta = e.currentTarget.closest('.sp-bp')?.querySelector<HTMLElement>('.sp-ta');
                    if (ta) {
                        ta.textContent = '';
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                        ta.focus();
                    }

                    return;
                }
                rmP(i, false);
            }}
        >
            {I.x15}
        </button>
    );
    const keys = (i: number) => (e: React.KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addP(false, i + 1);
        } else if (e.key === 'Backspace' && !e.currentTarget.textContent && list.length > 1) {
            e.preventDefault();
            rmP(i, true);
        }
    };
    const setText = (i: number, t: string) => setList((l) => l.map((p, j) => (j === i ? { ...p, text: t } : p)));

    return (
        <Card
            k={k}
            head={hello ? 'The opening' : 'Section'}
            saved={s.saved}
            onClose={() => s.closeCard(true)}
            foot={
                <>
                    <div className="l">
                        <button className="w-act" type="button" onClick={() => addP(false)}>
                            {I.plus15}
                            <span>Paragraph</span>
                        </button>
                        {!hello && (
                            <button className="w-act" type="button" onClick={() => addP(true)}>
                                {I.quote15}
                                <span>Pull quote</span>
                            </button>
                        )}
                    </div>
                    {!hello && (
                        <div className="r">
                            <button
                                className="w-act nt-del"
                                type="button"
                                aria-haspopup="dialog"
                                onClick={(e) =>
                                    s.confirm(e.currentTarget, 'Delete this section?', 'Its paragraphs and notes go with it. Readers still see it until you publish.', 'Delete', () => {
                                        const c = document.getElementById('spO');
                                        const go = () => {
                                            s.setOpen('');
                                            upd((d) => (d.sections = d.sections.filter((x) => x.id !== k)));
                                        };
                                        if (c) {
                                            bye(c, go);
                                        } else {
                                            go();
                                        }
                                    })
                                }
                            >
                                {I.x15}
                                <span>Delete this section</span>
                            </button>
                        </div>
                    )}
                </>
            }
        >
            <div className="k-f sp-f">
                <label className="k-fl" htmlFor={hello ? 'spHh' : 'spSh'}>
                    Heading
                </label>
                <span className="k-in sp-in">
                    <input
                        id={hello ? 'spHh' : 'spSh'}
                        defaultValue={hello ? a.hello.heading : sec!.heading}
                        placeholder={hello ? 'I’m …' : 'A heading'}
                        autoComplete="off"
                        spellCheck
                        onChange={(e) => {
                            const v = e.target.value;
                            upd((d) => {
                                if (hello) {
                                    d.hello.heading = v;
                                } else {
                                    d.sections.find((x) => x.id === k)!.heading = v;
                                }
                            });
                        }}
                    />
                </span>
            </div>
            <div className="sp-bps" id="spPs">
                <span className="k-fl">Paragraphs</span>
                <div className="sp-pl" ref={pl}>
                    {list.map((p, i) =>
                        p.pull_quote ? (
                            <div className="sp-bp q" data-i={i} key={`${gen}-${i}`}>
                                {pgrip(i)}
                                <div className="sp-pbd">
                                    <Area value={p.text} placeholder="A line to stand out" cls="q" label="Pull quote" onText={(t) => setText(i, t)} onKeyDown={keys(i)} />
                                    <span className="sp-qt">{I.quote15}Pull quote</span>
                                </div>
                                {rm(i, 'this pull quote')}
                            </div>
                        ) : (
                            <div className="sp-bp" data-i={i} key={`${gen}-${i}`}>
                                {pgrip(i)}
                                <div className="sp-pbd">
                                    <Area value={p.text} placeholder="A paragraph" label={`Paragraph ${i + 1}`} onText={(t) => setText(i, t)} onKeyDown={keys(i)} />
                                    {!hello && (
                                        <div className="sp-nA">
                                            <i aria-hidden="true" />
                                            <span className="k-in sp-in sm">
                                                <input
                                                    defaultValue={p.margin_note ?? ''}
                                                    placeholder="Margin note"
                                                    aria-label={`Margin note for paragraph ${i + 1}`}
                                                    onChange={(e) => {
                                                        const v = e.target.value;
                                                        setList((l) =>
                                                            l.map((x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          margin_note: v,
                                                                      }
                                                                    : x,
                                                            ),
                                                        );
                                                    }}
                                                />
                                            </span>
                                        </div>
                                    )}
                                </div>
                                {rm(i, `paragraph ${i + 1}`)}
                            </div>
                        ),
                    )}
                </div>
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------------ crop: drag to place, zoom; saves a round 600px picture */
function Crop({ src, onClose, onDone }: { src: string; onClose: () => void; onDone: (id: number, src: string) => void }) {
    const S = 260;
    const [on, setOn] = useState(false);
    const [z, setZ] = useState(1);
    const [xy, setXy] = useState({ x: 0, y: 0 });
    const [err, setErr] = useState('');
    const [busy, setBusy] = useState(false);
    const im = useRef<HTMLImageElement>(null);
    const wr = useRef<HTMLDivElement>(null);

    useEffect(() => {
        requestAnimationFrame(() => setOn(true));
        const t = window.setTimeout(() => wr.current?.focus(), RM() ? 0 : 200);
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        };
        document.addEventListener('keydown', key);

        return () => {
            window.clearTimeout(t);
            document.removeEventListener('keydown', key);
        };
    }, []);
    const fit = () => {
        const i = im.current;
        const r = i && i.naturalHeight ? i.naturalWidth / i.naturalHeight : 1;

        return { w: r >= 1 ? S * r : S, h: r >= 1 ? S : S / r };
    };
    const clamp = (x: number, y: number, zz: number) => {
        const { w, h } = fit();

        return {
            x: Math.min(0, Math.max(S - w * zz, x)),
            y: Math.min(0, Math.max(S - h * zz, y)),
        };
    };
    function close() {
        setOn(false);
        window.setTimeout(onClose, RM() ? 0 : 320);
    }
    async function use() {
        const i = im.current;
        if (!i) {
            return;
        }
        setBusy(true);
        setErr('');
        const cv = document.createElement('canvas');
        cv.width = cv.height = 600;
        const g = cv.getContext('2d')!;
        const { w, h } = fit();
        const k = 600 / S;
        g.drawImage(i, xy.x * k, xy.y * k, w * z * k, h * z * k);
        const blob = await new Promise<Blob | null>((r) => cv.toBlob(r, 'image/jpeg', 0.86));
        const body = new FormData();
        if (blob) {
            body.append('image', blob, 'portrait.jpg');
        }
        try {
            const res = await fetch('/site/portrait', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body,
            });
            const data = (await res.json().catch(() => ({}))) as {
                id?: number;
                src?: string;
            };
            setBusy(false);
            if (!res.ok || !data.id || !data.src) {
                setErr('That didn’t upload. Try again.');

                return;
            }
            setOn(false);
            window.setTimeout(() => onDone(data.id!, data.src!), RM() ? 0 : 320);
        } catch {
            setBusy(false);
            setErr('No connection. Try again when you’re back online.');
        }
    }

    const { w, h } = fit();

    return createPortal(
        <>
            <div className={`k-scrim${on ? ' on' : ''}`} id="spCrS" onClick={close} />
            <div className={`b-dlg sp-crop${on ? ' on' : ''}`} id="spCr" role="dialog" aria-modal="true" aria-labelledby="spCrH">
                <button className="ad-ic x" type="button" aria-label="Close" onClick={close}>
                    {I.x18}
                </button>
                <h2 id="spCrH">Crop</h2>
                <div
                    className="sp-crw"
                    id="spCrW"
                    tabIndex={0}
                    ref={wr}
                    aria-label="Drag to move the picture. Arrow keys move it too."
                    onPointerDown={(e) => {
                        const el = e.currentTarget;
                        el.setPointerCapture(e.pointerId);
                        const x0 = e.clientX - xy.x;
                        const y0 = e.clientY - xy.y;
                        el.classList.add('drag');
                        const mv = (ev: PointerEvent) => setXy(clamp(ev.clientX - x0, ev.clientY - y0, z));
                        const up = () => {
                            el.classList.remove('drag');
                            el.removeEventListener('pointermove', mv);
                            el.removeEventListener('pointerup', up);
                        };
                        el.addEventListener('pointermove', mv);
                        el.addEventListener('pointerup', up);
                    }}
                    onKeyDown={(e) => {
                        const d = (
                            {
                                ArrowLeft: [8, 0],
                                ArrowRight: [-8, 0],
                                ArrowUp: [0, 8],
                                ArrowDown: [0, -8],
                            } as Record<string, number[]>
                        )[e.key];
                        if (d) {
                            e.preventDefault();
                            setXy(clamp(xy.x + d[0], xy.y + d[1], z));
                        }
                    }}
                >
                    <img
                        src={src}
                        alt=""
                        draggable={false}
                        ref={im}
                        crossOrigin="anonymous"
                        onLoad={() => {
                            const f = fit();
                            setXy({ x: (S - f.w) / 2, y: (S - f.h) / 2 });
                            setZ(1);
                        }}
                        style={{
                            width: w * z,
                            height: h * z,
                            transform: `translate(${xy.x}px,${xy.y}px)`,
                        }}
                    />
                </div>
                <div className="sp-zm">
                    <span aria-hidden="true">{I.minus15}</span>
                    <input
                        type="range"
                        min="1"
                        max="3"
                        step="0.01"
                        value={z}
                        aria-label="Zoom"
                        id="spZ"
                        onChange={(e) => {
                            const nz = Number(e.target.value);
                            const cx = (S / 2 - xy.x) / (w * z);
                            const cy = (S / 2 - xy.y) / (h * z);
                            setZ(nz);
                            setXy(clamp(S / 2 - cx * w * nz, S / 2 - cy * h * nz, nz));
                        }}
                    />
                    <span aria-hidden="true">{I.plus15}</span>
                </div>
                {err && <p className="k-err">{err}</p>}
                <div className="sp-cra">
                    <button className="w-act" type="button" onClick={close}>
                        Cancel
                    </button>
                    <button className={`k-btn sm${busy ? ' busy' : ''}`} type="button" disabled={busy} onClick={() => void use()}>
                        <span>Use this</span>
                        <Ring />
                    </button>
                </div>
            </div>
        </>,
        document.body,
    );
}

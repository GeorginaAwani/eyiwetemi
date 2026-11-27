import { CSSProperties, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { KSelect } from '../Controls';
import { inA, phone, turn, xsrf } from '../notes/lines';
import { json } from '../../../lib/http';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { Cov, Field, I, SavedMark, SaveMark, Work } from './kit';

type Share = { own: boolean; line: string; image: string | null };
export type SharingData = { site: Share; works: Record<string, Share> };

const KIND: Record<string, string> = {
    novel: 'A NOVEL',
    poem: 'A POEM',
    story: 'A SHORT STORY',
    essay: 'AN ESSAY',
    collection: 'A COLLECTION',
};
const Wm = ({ cls = 'wm' }: { cls?: string }) => (
    <span className={cls}>
        <span className="r">ey</span>
        <span className="r i first">ı</span>wetem<span className="i">ı</span>
    </span>
);

/** The picture a link to the site (or a work) shows when it's shared: made for her, or her own. */
function ShareCard({ work, o, portrait, width }: { work: Work | null; o: Share; portrait: string | null; width: number }) {
    const st = {
        width,
        height: Math.round((315 * width) / 600),
        '--s': width / 600,
    } as CSSProperties;
    if (o.own && o.image) {
        return (
            <div className="sc-card own" style={st}>
                <img src={o.image} alt="" />
            </div>
        );
    }
    if (!work) {
        return (
            <div className="sc-card site" style={st}>
                <div className="sc-por">{portrait ? <img src={portrait} alt="" /> : <span className="sc-por0" />}</div>
                <div className="sc-sx">
                    <Wm />
                    <span className="sc-l">{o.line}</span>
                </div>
            </div>
        );
    }
    if (!work.image && work.kind !== 'novel') {
        return (
            <div className="sc-card nc" style={st}>
                <span className="sc-k">{KIND[work.kind] ?? ''}</span>
                <b className="sc-t">{work.title}</b>
                <Wm cls="wm sc-wm" />
            </div>
        );
    }

    return (
        <div className="sc-card wk" style={st}>
            <span className="sc-cv" style={work.image ? undefined : { background: work.color ?? '#C46F8A' }}>
                {work.image && <img src={work.image} alt="" />}
            </span>
            <div className="sc-x">
                <span className="sc-k">{KIND[work.kind] ?? ''}</span>
                <b className="sc-t">{work.title}</b>
                <span className="sc-by">Eyiwetemi</span>
                <Wm cls="wm sc-wm" />
            </div>
        </div>
    );
}

/** Sharing: for the whole site and each public work, the picture and the line under the title, as WhatsApp, X and Instagram show it. */
export default function SharingTab({ s, sharing, setSharing, host }: { s: SiteCtx; sharing: SharingData; setSharing: (d: SharingData) => void; host: string }) {
    const pub = s.works.filter((w) => w.public);
    const q = new URLSearchParams(window.location.search).get('w') ?? '';
    const [sel, setSel] = useState(pub.some((w) => w.slug === q) ? q : '');
    const [more, setMore] = useState(false);
    const [out, setOut] = useState(false);
    const [saved, setSaved] = useState<SaveMark>('idle');
    const [, setTick] = useState(0);
    const file = useRef<HTMLInputElement>(null);
    const big = useRef<HTMLDivElement>(null);
    const t = useRef(0);
    const work = sel ? (pub.find((w) => w.slug === sel) ?? null) : null;
    const of = (slug: string): Share => (slug ? (sharing.works[slug] ?? { own: false, line: '', image: null }) : sharing.site);
    const o = of(sel);
    const line = sel ? o.line || work?.desc || '' : o.line;
    const N = 8;
    const list = more ? pub : pub.slice(0, N);
    const portrait = s.portrait.live;

    useEffect(() => {
        const r = () => setTick((x) => x + 1);
        window.addEventListener('resize', r);

        return () => window.removeEventListener('resize', r);
    }, []);
    /* the small previews scale their card to the box they sit in */
    useLayoutEffect(() => {
        document.querySelectorAll<HTMLElement>('.sp-pfs .pf-wa .sc-card,.sp-pfs .pf-x .sc-card').forEach((el) => el.style.setProperty('--s', String(el.clientWidth / 600)));
    });

    function pick(slug: string) {
        if (slug === sel) {
            return;
        }
        setOut(true);
        window.setTimeout(() => {
            setSel(slug);
            setSaved('idle');
            setOut(false);
        }, 160);
    }
    async function patch(body: Record<string, unknown>) {
        const { status, data } = await json<{ sharing: SharingData }>('PATCH', '/site/sharing', { slug: sel || null, ...body });
        if (status === 200 && data.sharing) {
            setSharing(data.sharing);
            setSaved('saved');
        } else {
            setSaved(status === 0 ? 'device' : 'bad');
        }
    }
    function local(fn: (x: Share) => Share) {
        const next = { ...sharing, works: { ...sharing.works } };
        if (sel) {
            next.works[sel] = fn(of(sel));
        } else {
            next.site = fn(sharing.site);
        }
        setSharing(next);
    }
    function setOwn(own: boolean) {
        if (own && !o.image) {
            file.current?.click();

            return;
        }
        local((x) => ({ ...x, own }));
        relabel();
        void patch({ own });
        s.after(() => inA(big.current));
    }
    function relabel() {
        const a = document.querySelector(`.sp-sw[data-w="${sel}"] small`);
        if (a) {
            turn(a, () => undefined);
        }
    }
    async function upload(f: File) {
        const body = new FormData();
        body.append('image', f);
        if (sel) {
            body.append('slug', sel);
        }
        try {
            const res = await fetch('/site/sharing/picture', {
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
                sharing?: SharingData;
            };
            if (res.ok && data.sharing) {
                setSharing(data.sharing);
                setSaved('saved');
                relabel();
                s.after(() => inA(big.current));
            } else {
                setSaved('bad');
            }
        } catch {
            setSaved('device');
        }
    }

    const item = (slug: string, title: string, icon: React.ReactNode) => {
        const x = of(slug);

        return (
            <a
                href="#"
                key={slug || 'site'}
                className={`sp-sw${sel === slug ? ' on' : ''}`}
                data-w={slug}
                aria-current={sel === slug ? 'true' : undefined}
                onClick={(e) => {
                    e.preventDefault();
                    pick(slug);
                }}
            >
                {icon}
                <span>{title}</span>
                <small>{x.own && x.image ? 'Your picture' : 'Made for you'}</small>
            </a>
        );
    };
    const url = sel ? `${host}/library/${sel}` : host;
    const title = work ? work.title : 'Eyiwetemi';
    const small = (w: number) => <ShareCard work={work} o={{ ...o, line }} portrait={portrait} width={w} />;

    return (
        <div className="sp-sh">
            <nav className="sp-shl" aria-label="Shared as">
                <span className="k-fl">Shared as</span>
                <div className="sp-shls">
                    {item('', 'The whole site', <span className="sp-ri sm">{portrait && <img src={portrait} alt="" />}</span>)}
                    {list.map((w) => item(w.slug, w.title, <Cov w={w} width={20} />))}
                    {pub.length > N && !more && (
                        <button
                            className="sp-sw more"
                            type="button"
                            onClick={() => {
                                setMore(true);
                                s.after(() => [...document.querySelectorAll('.sp-shls .sp-sw')].slice(N + 1).forEach((el) => inA(el)));
                            }}
                        >
                            {pub.length - N} more works
                        </button>
                    )}
                </div>
            </nav>
            <div className="sp-phsel">
                <div className="k-f sp-f">
                    <span className="k-fl" id="spShSL">
                        Shared as
                    </span>
                    <KSelect
                        className="sp-ks"
                        buttonClassName="k-sbtn sp-sel"
                        labelledBy="spShSL"
                        value={sel}
                        options={[
                            { value: '', label: 'The whole site' },
                            ...pub.map((w) => ({
                                value: w.slug,
                                label: w.title,
                            })),
                        ]}
                        onPick={pick}
                    />
                </div>
            </div>
            <div className={`sp-she${out ? ' nt-out' : ''}`} id="spShe" key={sel}>
                <div className="sp-shp">
                    <div className="nw-seg sp-seg" role="radiogroup" aria-label="Picture">
                        <button type="button" role="radio" aria-checked={!o.own} className={o.own ? '' : 'on'} onClick={() => setOwn(false)}>
                            {I.sun15}
                            <span>Made for you</span>
                        </button>
                        <button type="button" role="radio" aria-checked={o.own} className={o.own ? 'on' : ''} onClick={() => setOwn(true)}>
                            {I.img15}
                            <span>My own picture</span>
                        </button>
                    </div>
                    <label className="w-act sp-up2" htmlFor="spShF" hidden={!o.own}>
                        {I.up16}
                        <span>{o.image ? 'Replace the picture' : 'Upload a picture'}</span>
                    </label>
                    <input
                        type="file"
                        id="spShF"
                        accept="image/*"
                        hidden
                        ref={file}
                        onChange={() => {
                            const f = file.current?.files?.[0];
                            if (f) {
                                void upload(f);
                            }
                            file.current!.value = '';
                        }}
                    />
                    <SavedMark state={saved} cls="sp-shsv" />
                </div>
                <Field
                    id="spShL"
                    label="Line under the title"
                    value={line}
                    placeholder="One line"
                    onChange={(v) => {
                        local((x) => ({ ...x, line: v }));
                        window.clearTimeout(t.current);
                        t.current = window.setTimeout(() => void patch({ line: v }), 500);
                    }}
                />
                <div className="sp-shc" id="spShC" ref={big}>
                    {small(phone() ? Math.min(520, window.innerWidth - 40) : 520)}
                </div>
                <div id="spShP">
                    <div className="sp-pfs">
                        <div className="pf-wa">
                            <div className="b">
                                {small(300)}
                                <div className="m">
                                    <b>{title}</b>
                                    <span>{line}</span>
                                    <small>{host}</small>
                                </div>
                                <div className="u">{url}</div>
                            </div>
                            <span className="pf-n">WhatsApp</span>
                        </div>
                        <div className="pf-x">
                            <div className="b">
                                {small(300)}
                                <span className="u">From {host}</span>
                            </div>
                            <span className="pf-n">X</span>
                        </div>
                        <div className="pf-ig">
                            <div className="b">
                                <div className="sq">{small(300)}</div>
                                <div className="m">
                                    <b>{title}</b>
                                    <small>{host}</small>
                                </div>
                            </div>
                            <span className="pf-n">Instagram message</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

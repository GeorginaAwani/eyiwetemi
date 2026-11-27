import { CSSProperties, RefObject, useEffect, useRef, useState } from 'react';
import { KSelect } from '../Controls';
import { Ring } from '../Icons';
import { atFmt, dDay, inA, tFmt, TIMES } from '../notes/lines';
import { json } from '../../../lib/http';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { Cov, I, Work } from './kit';

export type HomeData = {
    pin: { slug: string; title: string; until: string } | null;
    lead: {
        label: string;
        title: string;
        image: string | null;
        color: string | null;
        button: string;
    } | null;
    side: { label: string; title: string }[];
    next: string | null;
};

const watNow = () => new Date(Date.now() + 3600e3).toISOString().slice(0, 16);
export const daysFrom = (n: number) =>
    [...Array(n)].map((_, i) => {
        const x = new Date(Date.now() + 3600e3);
        x.setUTCDate(x.getUTCDate() + i);

        return x.toISOString().slice(0, 10);
    });
export const ENDS = [...TIMES, '23:59'];

/** Home: what leads the page. The newest work, unless she pins one until a day. */
export default function HomeTab({ s, home, setHome }: { s: SiteCtx; home: HomeData; setHome: (h: HomeData) => void }) {
    const p = home.pin;
    const lead = home.lead;

    function pinPop(btn: HTMLElement, dateOnly: boolean) {
        s.popShow({
            id: 'pin',
            btn,
            side: 'right',
            render: (cls, style, box) => (
                <PinPop
                    cls={cls}
                    style={style}
                    box={box}
                    dateOnly={dateOnly}
                    pin={p}
                    works={s.works.filter((w) => w.public).sort((a, b) => a.title.localeCompare(b.title))}
                    onClose={() => s.popClose(true)}
                    onSave={async (slug, until) => {
                        const { status, data } = dateOnly
                            ? await json<{ home: HomeData }>('PATCH', '/site/home-pin', { until })
                            : await json<{ home: HomeData }>('PUT', '/site/home-pin', { slug, until });
                        if (status !== 200 || !data.home) {
                            return status === 0 ? 'No connection. Try again.' : 'That time has passed. Pick a later one.';
                        }
                        s.popClose(true);
                        s.swap(() => setHome(data.home!));

                        return '';
                    }}
                />
            ),
        });
    }
    async function unpin() {
        const { status, data } = await json<{ home: HomeData }>('DELETE', '/site/home-pin');
        if (status === 200 && data.home) {
            s.swap(() => setHome(data.home!));
        }
    }

    return (
        <section className="nw-m sp-m">
            <h2>What leads</h2>
            <div id="spHst">
                {p ? (
                    <div className="sp-hst on">
                        <span className="sp-pin">{I.pin16}</span>
                        <span>
                            Pinned until <b>{atFmt(p.until)}</b>
                        </span>
                        <button className="w-act" type="button" aria-haspopup="dialog" aria-expanded="false" onClick={(e) => pinPop(e.currentTarget, true)}>
                            {I.clock15}
                            <span>Change date</span>
                        </button>
                        <button className="w-act" type="button" onClick={() => void unpin()}>
                            {I.x15}
                            <span>Unpin</span>
                        </button>
                    </div>
                ) : (
                    <div className="sp-hst">
                        <span className="sp-pin">{I.sun16}</span>
                        <span>The newest work leads</span>
                        <button className="w-act sp-pinb" type="button" aria-haspopup="dialog" aria-expanded="false" onClick={(e) => pinPop(e.currentTarget, false)}>
                            {I.pin16}
                            <span>Pin a work</span>
                        </button>
                    </div>
                )}
            </div>
            {lead && (
                <div className="sp-hpv" id="spHpv">
                    <div className="sp-hl">
                        {lead.image ? (
                            <span className="k-cov sp-cv" style={{ width: 120, height: 180 }}>
                                <img src={lead.image} alt="" />
                            </span>
                        ) : (
                            <span
                                className="k-cov sp-cv"
                                style={{
                                    width: 120,
                                    height: 180,
                                    background: lead.color ?? '#C24E77',
                                }}
                            />
                        )}
                        <div>
                            <span className="k">{lead.label}</span>
                            <b>{lead.title}</b>
                            <span className="sp-hb">{lead.button}</span>
                        </div>
                    </div>
                    {home.side.length > 0 && (
                        <div className="sp-hr">
                            {home.side.map((x) => (
                                <div className="sp-hs" key={x.title}>
                                    <span className="k">{x.label}</span>
                                    <b>{x.title}</b>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
            {p && home.next && (
                <p className="sp-hnext">
                    {I.hist14}
                    <span>
                        After {dDay(p.until.slice(0, 10))}: {home.next}
                    </span>
                </p>
            )}
        </section>
    );
}

function PinPop({
    cls,
    style,
    box,
    dateOnly,
    pin,
    works,
    onClose,
    onSave,
}: {
    cls: string;
    style: CSSProperties;
    box: RefObject<HTMLDivElement>;
    dateOnly: boolean;
    pin: HomeData['pin'];
    works: Work[];
    onClose: () => void;
    onSave: (slug: string, until: { day: string; time: string }) => Promise<string>;
}) {
    const days = daysFrom(90);
    const [pick, setPick] = useState(pin?.slug ?? '');
    const [day, setDay] = useState(pin ? pin.until.slice(0, 10) : days[13]);
    const [time, setTime] = useState(pin ? pin.until.slice(11, 16) : '23:59');
    const [q, setQ] = useState('');
    const [err, setErr] = useState('');
    const [busy, setBusy] = useState(false);
    const ip = useRef<HTMLInputElement>(null);
    const none = useRef<HTMLParagraphElement>(null);
    const vis = works.filter((w) => !q.trim() || w.title.toLowerCase().includes(q.trim().toLowerCase()));
    const now = watNow();
    useEffect(() => {
        if (!dateOnly) {
            window.setTimeout(() => ip.current?.focus(), 60);
        }
    }, []);
    useEffect(() => {
        if (!vis.length) {
            inA(none.current);
        }
    }, [vis.length === 0]);

    return (
        <div className={`${cls} sp-pp`} role="dialog" aria-label={dateOnly ? 'Pinned until' : 'Pin a work'} ref={box} style={style}>
            <button className="ad-ic x" type="button" aria-label="Close" onClick={onClose}>
                {I.x16}
            </button>
            <h3>{dateOnly ? 'Pinned until' : 'Pin a work'}</h3>
            {!dateOnly && (
                <>
                    <label className="k-search sp-fs">
                        {I.search}
                        <input
                            ref={ip}
                            placeholder="Find a work"
                            aria-label="Find a work"
                            autoComplete="off"
                            role="combobox"
                            aria-controls="spPL"
                            aria-expanded="true"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    if (vis[0]) {
                                        setPick(vis[0].slug);
                                    }
                                }
                            }}
                        />
                    </label>
                    <div className="sp-lst" role="listbox" id="spPL" aria-label="Works">
                        {vis.map((w) => (
                            <div className={`sp-lo${pick === w.slug ? ' on' : ''}`} role="option" key={w.slug} aria-selected={pick === w.slug} onClick={() => setPick(w.slug)}>
                                <Cov w={w} width={20} />
                                <span>{w.title}</span>
                                {I.ltick}
                            </div>
                        ))}
                        <p className="sp-none sp-lnone" ref={none} hidden={vis.length > 0}>
                            No public work called that.
                        </p>
                    </div>
                </>
            )}
            <div className="nt-when">
                <div className="k-f sp-f">
                    <span className="k-fl" id="spPdL">
                        Until, WAT
                    </span>
                    <KSelect
                        className="sp-ks"
                        buttonClassName="k-sbtn sp-sel"
                        labelledBy="spPdL"
                        value={day}
                        options={days.map((d) => ({
                            value: d,
                            label: dDay(d),
                        }))}
                        onPick={(v) => (setDay(v), setErr(''))}
                    />
                </div>
                <div className="k-f sp-f">
                    <span className="k-fl" id="spPtL">
                        Time
                    </span>
                    <KSelect
                        className="sp-ks"
                        buttonClassName="k-sbtn sp-sel"
                        labelledBy="spPtL"
                        value={time}
                        options={ENDS.map((t) => ({
                            value: t,
                            label: tFmt(t),
                            disabled: `${day}T${t}` <= now,
                        }))}
                        onPick={(v) => (setTime(v), setErr(''))}
                    />
                </div>
            </div>
            {err && <p className="pp">{err}</p>}
            <div className="pf">
                <button
                    className={`k-btn sm${busy ? ' busy' : ''}`}
                    type="button"
                    disabled={(!dateOnly && !pick) || busy}
                    onClick={async () => {
                        setBusy(true);
                        const e = await onSave(pick, { day, time });
                        setBusy(false);
                        setErr(e);
                    }}
                >
                    <span>{dateOnly ? 'Save' : 'Pin'}</span>
                    <Ring />
                </button>
            </div>
        </div>
    );
}

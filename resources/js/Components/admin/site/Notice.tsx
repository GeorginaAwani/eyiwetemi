import { useEffect, useRef, useState } from 'react';
import { KSelect } from '../Controls';
import { Ring } from '../Icons';
import { atFmt, dDay, inA, tFmt } from '../notes/lines';
import { json } from '../../../lib/http';
import type { SiteCtx } from '../../../Pages/Admin/Site';
import { daysFrom, ENDS } from './Home';
import { I, SavedMark, SaveMark } from './kit';

export type NoticeData = {
    current: {
        id: number;
        text: string;
        link: string;
        startsAt: string;
        endsAt: string | null;
        state: 'up' | 'sch';
    } | null;
    earlier: { id: number; when: string; text: string; closed: number }[];
};

/* where the notice's link goes, and the words it shows on the site */
const LINKS: [string, string, string][] = [
    ['', 'No link', ''],
    ['in_print', 'In print', 'See where to buy'],
    ['notes', 'Notes', 'Read the note'],
    ['about', 'About me', 'Read more'],
    ['library', 'Library', 'Have a look'],
    ['readings', 'Readings', 'See the readings'],
];

/** The notice: one short line across the top of the site, from a day until a day. */
export default function NoticeTab({ s, notice, setNotice }: { s: SiteCtx; notice: NoticeData; setNotice: (n: NoticeData) => void }) {
    const n = notice.current;
    const live = !!n;
    const days = daysFrom(60);
    const [text, setText] = useState(n?.text ?? '');
    const [link, setLink] = useState(n?.link ?? '');
    const [start, setStart] = useState(n && n.state === 'sch' ? n.startsAt.slice(0, 10) : 'now');
    const [end, setEnd] = useState(n?.endsAt ? n.endsAt.slice(0, 10) : days[14]);
    const [endT, setEndT] = useState(n?.endsAt ? n.endsAt.slice(11, 16) : '23:59');
    const [saved, setSaved] = useState<SaveMark>('idle');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');
    const t = useRef(0);
    const first = useRef(true);
    const go = LINKS.find((l) => l[0] === link)!;

    const body = () => ({
        text: text.trim(),
        link,
        starts: start,
        ends: { day: end, time: endT },
    });
    /* while it's up (or waiting), every change goes straight out */
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }
        if (!n || !text.trim()) {
            return;
        }
        window.clearTimeout(t.current);
        t.current = window.setTimeout(async () => {
            const { status, data } = await json<{ notice: NoticeData }>('PATCH', `/site/notice/${n.id}`, body());
            if (status === 200 && data.notice) {
                setSaved('saved');
                setNotice(data.notice);
            } else {
                setSaved(status === 0 ? 'device' : 'bad');
            }
        }, 400);

        return () => window.clearTimeout(t.current);
    }, [text, link, start, end, endT]);

    async function putUp() {
        if (!text.trim() || busy) {
            return;
        }
        setBusy(true);
        setErr('');
        const { status, data } = await json<{
            notice: NoticeData;
            errors?: Record<string, string[]>;
        }>('POST', '/site/notice', body());
        setBusy(false);
        if (status !== 200 && status !== 201) {
            setErr(status === 0 ? 'No connection. Try again.' : (Object.values(data.errors ?? {})[0]?.[0] ?? 'That didn’t work. Try again.'));

            return;
        }
        s.swap(() => setNotice(data.notice!));
    }
    async function takeDown() {
        if (!n) {
            return;
        }
        const { status, data } = await json<{ notice: NoticeData }>('POST', `/site/notice/${n.id}/down`);
        if (status === 200 && data.notice) {
            s.swap(() => setNotice(data.notice!));
        }
    }

    return (
        <>
            <section className="nw-m sp-m">
                <h2>
                    Notice{' '}
                    <span id="spNst">
                        {n?.state === 'up' ? (
                            <span className="nt-sv on">
                                {I.dot}
                                <span>{n.endsAt ? `Up until ${atFmt(n.endsAt)}` : 'Up'}</span>
                            </span>
                        ) : n?.state === 'sch' ? (
                            <span className="nt-st sch">
                                {I.clock15}
                                <span>Goes up {atFmt(n.startsAt)}</span>
                            </span>
                        ) : null}
                    </span>
                </h2>
                <div className="sp-nf">
                    <div className="k-f sp-f">
                        <label className="k-fl" htmlFor="spNt">
                            Line
                        </label>
                        <span className="k-in sp-in big">
                            <input id="spNt" maxLength={70} value={text} placeholder="One short line" autoComplete="off" onChange={(e) => setText(e.target.value)} />
                        </span>
                        <span className="sp-cnt" id="spNc">
                            {text.length} / 70
                        </span>
                    </div>
                    <div className="sp-n2">
                        <div className="k-f sp-f">
                            <span className="k-fl" id="spNlL">
                                Links to
                            </span>
                            <KSelect
                                className="sp-ks"
                                buttonClassName="k-sbtn sp-sel"
                                labelledBy="spNlL"
                                value={link}
                                options={LINKS.map(([v, l]) => ({
                                    value: v,
                                    label: l,
                                }))}
                                onPick={setLink}
                            />
                        </div>
                        <div className="k-f sp-f">
                            <span className="k-fl" id="spNsL">
                                Starts, WAT
                            </span>
                            <KSelect
                                className="sp-ks"
                                buttonClassName="k-sbtn sp-sel"
                                labelledBy="spNsL"
                                value={start}
                                disabled={n?.state === 'up'}
                                options={[
                                    {
                                        value: 'now',
                                        label: n?.state === 'up' ? atFmt(n.startsAt) : 'Now',
                                    },
                                    ...days.slice(1).map((d) => ({
                                        value: d,
                                        label: dDay(d),
                                    })),
                                ]}
                                onPick={setStart}
                            />
                        </div>
                        <div className="k-f sp-f">
                            <span className="k-fl" id="spNeL">
                                Ends, WAT
                            </span>
                            <div className="sp-dt2">
                                <KSelect
                                    className="sp-ks"
                                    buttonClassName="k-sbtn sp-sel sm"
                                    label="Ends"
                                    value={end}
                                    options={days.slice(1).map((d) => ({
                                        value: d,
                                        label: dDay(d),
                                        disabled: start !== 'now' && d < start,
                                    }))}
                                    onPick={setEnd}
                                />
                                <KSelect
                                    className="sp-ks"
                                    buttonClassName="k-sbtn sp-sel sm"
                                    label="Ends at"
                                    value={endT}
                                    options={ENDS.map((v) => ({
                                        value: v,
                                        label: tFmt(v),
                                    }))}
                                    onPick={setEndT}
                                />
                            </div>
                        </div>
                    </div>
                </div>
                <div className="sp-npv">
                    <span className="k-fl">On the site</span>
                    <div className="sp-nl" id="spNpv">
                        <span className="dt">{I.dot}</span>
                        <span className="t">{text.trim() || <i>One short line</i>}</span>
                        {go[2] ? <span className="lk">{go[2]}</span> : <span className="lk sp-gone" />}
                        <span className="x">{I.x15}</span>
                    </div>
                </div>
                <div className="sp-nacts" id="spNa">
                    {live ? (
                        <>
                            <button className="w-act" type="button" onClick={() => void takeDown()}>
                                {I.x15}
                                <span>{n.state === 'up' ? 'Take it down' : 'Cancel'}</span>
                            </button>
                            <SavedMark state={saved} cls="sp-nsv" />
                        </>
                    ) : (
                        <button className={`k-btn sm${busy ? ' busy' : ''}`} type="button" disabled={!text.trim() || busy} onClick={() => void putUp()}>
                            <span>{start === 'now' ? 'Put it up' : 'Schedule'}</span>
                            <Ring />
                        </button>
                    )}
                </div>
                {err && <ErrLine text={err} />}
            </section>
            {notice.earlier.length > 0 && (
                <section className="nw-m sp-m">
                    <h2>
                        Earlier <small>{notice.earlier.length}</small>
                    </h2>
                    <div className="sp-nps">
                        {notice.earlier.map((p) => (
                            <div className="sp-np" key={p.id}>
                                <span className="d">{p.when}</span>
                                <span className="t">{p.text}</span>
                                <span className="k-meta">{p.closed} closed it</span>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}

function ErrLine({ text }: { text: string }) {
    const r = useRef<HTMLParagraphElement>(null);
    useEffect(() => inA(r.current), [text]);

    return (
        <p className="k-err" ref={r}>
            {text}
        </p>
    );
}

import { router, usePage } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';
import { inertWhen } from '../../lib/dom';
import { json } from '../../lib/http';
import { prefersReducedMotion } from '../../lib/motion';
import { FieldError, KSelect, loading, Unfold } from './Controls';
import { Close, Pen, Ring } from './Icons';
import NameField from './NameField';

export type KKind = 'novel' | 'story' | 'poem' | 'essay' | 'collection';
export type SeriesChoice = { id: number; name: string; books: number };
export type CollectionChoice = { id: number; title: string; slug: string; kind: string };
type Taken = { title: string; href: string } | null;

export const KINDS: [KKind, string][] = [
    ['novel', 'Novel'],
    ['story', 'Short story'],
    ['poem', 'Poem'],
    ['essay', 'Essay'],
    ['collection', 'Collection'],
];
export const COLLECTION_KINDS: [string, string][] = [
    ['story', 'Stories'],
    ['poem', 'Poems'],
    ['essay', 'Essays'],
];

const OPEN_EVENT = 'admin:new-work';

/** Open the New work dialog from anywhere in the admin. */
export const openNewWork = () => window.dispatchEvent(new Event(OPEN_EVENT));

/**
 * New work: a title (no two works share one) and the kind. A novel can join a series or start one; a poem, story or
 * essay can go into a collection or start one. Start writing makes it and opens a blank page.
 */
export default function NewWork() {
    const { newWork } = usePage().props as unknown as { newWork: { series: SeriesChoice[]; collections: CollectionChoice[] } | null };
    const [on, setOn] = useState(false);
    const [title, setTitle] = useState('');
    const [taken, setTaken] = useState<Taken>(null);
    const [checked, setChecked] = useState(true);
    const [kind, setKind] = useState<KKind>('novel');
    const [ofKind, setOfKind] = useState('story');
    const [series, setSeries] = useState<SeriesChoice[]>([]);
    const [seriesId, setSeriesId] = useState('');
    const [naming, setNaming] = useState<'' | 'series' | 'collection'>('');
    const [collectionId, setCollectionId] = useState('');
    const [pending, setPending] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const dialog = useRef<HTMLDivElement>(null);
    const titleInput = useRef<HTMLInputElement>(null);
    const lastFocus = useRef<HTMLElement | null>(null);
    const checkTimer = useRef(0);

    useEffect(() => {
        const open = () => {
            lastFocus.current = document.activeElement as HTMLElement;
            setTitle('');
            setTaken(null);
            setChecked(true);
            setKind('novel');
            setOfKind('story');
            setSeriesId('');
            setCollectionId('');
            setPending('');
            setNaming('');
            setError('');
            setSeries(newWork?.series ?? []);
            setOn(true);
            window.setTimeout(() => titleInput.current?.focus(), prefersReducedMotion() ? 0 : 120);
        };
        window.addEventListener(OPEN_EVENT, open);

        return () => window.removeEventListener(OPEN_EVENT, open);
    }, [newWork]);

    function close() {
        setOn(false);
        lastFocus.current?.focus?.();
    }

    useEffect(() => {
        if (!on) {
            return;
        }
        const esc = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape' && !e.defaultPrevented) {
                close();
            }
        };
        document.addEventListener('keydown', esc);

        return () => document.removeEventListener('keydown', esc);
    }, [on]);

    /* the title is checked a moment after she stops typing */
    function typed(v: string) {
        setTitle(v);
        setChecked(false);
        setError('');
        window.clearTimeout(checkTimer.current);
        checkTimer.current = window.setTimeout(async () => {
            const { data } = await json<{ taken: Taken }>('GET', '/works/title-taken?title=' + encodeURIComponent(v));
            setTaken(data.taken ?? null);
            setChecked(true);
        }, 220);
    }

    function trap(e: KeyboardEvent) {
        if (e.key !== 'Tab' || !dialog.current) {
            return;
        }
        const f = [...dialog.current.querySelectorAll<HTMLElement>('button:not(:disabled),input,a[href]')].filter((x) => x.offsetParent && !x.closest('[inert]'));
        const first = f[0];
        const last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    const single = kind === 'story' || kind === 'poem' || kind === 'essay';
    const collections = (newWork?.collections ?? []).filter((c) => c.kind === kind);
    const ready = title.trim() !== '' && checked && !taken && !sending;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!ready) {
            titleInput.current?.focus();

            return;
        }
        setSending(true);
        loading(true);
        router.post(
            '/works',
            {
                title: title.trim(),
                kind,
                collection_kind: kind === 'collection' ? ofKind : null,
                series_id: kind === 'novel' && seriesId ? Number(seriesId) : null,
                collection_id: single && collectionId && collectionId !== '__pend' ? Number(collectionId) : null,
                new_collection: single && collectionId === '__pend' ? pending : null,
            },
            {
                onError: (errors) => setError(errors.title ?? errors.new_collection ?? Object.values(errors)[0] ?? ''),
                onFinish: () => {
                    setSending(false);
                    loading(false);
                },
                onSuccess: () => setOn(false),
            },
        );
    }

    return (
        <>
            <div className={`k-scrim${on ? ' on' : ''}`} id="nwS" onClick={close} />
            <div className={`b-dlg${on ? ' on' : ''}`} id="nw" role="dialog" aria-modal="true" aria-labelledby="nwH" ref={dialog} onKeyDown={trap} {...inertWhen(!on)}>
                <button className="ad-ic x" type="button" aria-label="Close" onClick={close}>
                    <Close />
                </button>
                <h2 id="nwH">New work</h2>
                <form id="nwF" noValidate onSubmit={submit}>
                    <div className="nwt">
                        <div className="k-f">
                            <label className="k-fl" htmlFor="nt">
                                Title
                            </label>
                            <span className={`k-in big${taken ? ' bad' : ''}`} id="ntIn">
                                <input id="nt" ref={titleInput} autoComplete="off" spellCheck={false} value={title} onChange={(e) => typed(e.target.value)} />
                            </span>
                        </div>
                        <FieldError message={taken ? `You already have a work called ${taken.title}.` : error}>
                            {taken && (
                                <a className="k-l" href={taken.href} onClick={(e) => {
                                    e.preventDefault();
                                    setOn(false);
                                    router.visit(taken.href);
                                }}>
                                    <span>Open it</span>
                                </a>
                            )}
                        </FieldError>
                    </div>
                    <div className="k-f">
                        <span className="k-fl" id="nkL">
                            Kind
                        </span>
                        <div className="k-pills" role="radiogroup" aria-labelledby="nkL">
                            {KINDS.map(([v, label]) => (
                                <label className="k-pill" key={v}>
                                    <input type="radio" name="nk" value={v} checked={kind === v} onChange={() => setKind(v)} />
                                    <span>{label}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                    <Unfold show={kind === 'collection'} id="nckU">
                        <div className="k-f w-of">
                            <span className="k-fl" id="nckL">
                                Of
                            </span>
                            <div className="k-pills" role="radiogroup" aria-labelledby="nckL">
                                {COLLECTION_KINDS.map(([v, label]) => (
                                    <label className="k-pill" key={v}>
                                        <input type="radio" name="nck" value={v} checked={ofKind === v} onChange={() => setOfKind(v)} />
                                        <span>{label}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </Unfold>
                    <Unfold show={kind === 'novel'} id="nseU">
                        <div className="k-f w-of">
                            <span className="k-fl" id="nseL">
                                Series
                            </span>
                            <KSelect
                                className="w-se2"
                                id="nse2"
                                labelledBy="nseL"
                                value={seriesId}
                                options={[{ value: '', label: 'Not in a series' }, ...series.map((s) => ({ value: String(s.id), label: s.name })), { value: '__new', label: 'New series', add: true }]}
                                onPick={(v) => (v === '__new' ? setNaming('series') : (setSeriesId(v), setNaming('')))}
                            />
                            <NameField
                                show={naming === 'series'}
                                placeholder="Series name"
                                onCancel={() => {
                                    setNaming('');
                                    document.getElementById('nse2')?.focus();
                                }}
                                onCreate={async (name) => {
                                    const { status, data } = await json<{ series: SeriesChoice; errors: Record<string, string[]> }>('POST', '/series', { name });
                                    if (status !== 201 || !data.series) {
                                        return data.errors?.name?.[0] ?? (status === 0 ? 'No connection. Try again.' : 'That didn’t work. Try again.');
                                    }
                                    setSeries([...series, data.series].sort((a, b) => a.name.localeCompare(b.name)));
                                    setSeriesId(String(data.series.id));
                                    setNaming('');
                                    document.getElementById('nse2')?.focus();

                                    return null;
                                }}
                            />
                        </div>
                    </Unfold>
                    <Unfold show={single} id="nclU">
                        <div className="k-f w-of">
                            <span className="k-fl" id="nclL">
                                Collection
                            </span>
                            <KSelect
                                className="w-se2"
                                id="ncl2"
                                labelledBy="nclL"
                                value={collectionId}
                                options={[
                                    { value: '', label: 'Not in a collection' },
                                    ...collections.map((c) => ({ value: String(c.id), label: c.title })),
                                    ...(pending ? [{ value: '__pend', label: pending }] : []),
                                    { value: '__new', label: 'New collection', add: true },
                                ]}
                                onPick={(v) => (v === '__new' ? setNaming('collection') : (setCollectionId(v), setNaming('')))}
                            />
                            <NameField
                                show={naming === 'collection'}
                                placeholder="Collection name"
                                onCancel={() => {
                                    setNaming('');
                                    document.getElementById('ncl2')?.focus();
                                }}
                                onCreate={async (name) => {
                                    if (name.toLowerCase() === title.trim().toLowerCase()) {
                                        return `You already have a work called ${name}.`;
                                    }
                                    const { data } = await json<{ taken: Taken }>('GET', '/works/title-taken?title=' + encodeURIComponent(name));
                                    if (data.taken) {
                                        return `You already have a work called ${data.taken.title}.`;
                                    }
                                    setPending(name);
                                    setCollectionId('__pend');
                                    setNaming('');
                                    document.getElementById('ncl2')?.focus();

                                    return null;
                                }}
                            />
                        </div>
                    </Unfold>
                    <div className="acts">
                        <button className="k-l" type="button" onClick={close}>
                            <span>Cancel</span>
                        </button>
                        <button className={`k-btn${sending ? ' busy' : ''}`} type="submit" disabled={!ready && !sending} aria-busy={sending}>
                            <Pen />
                            <span>Start writing</span>
                            <Ring />
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}

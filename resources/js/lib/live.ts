import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { json } from './http';
import { askSignIn, SignInDetail } from './signin';

export type LikeType = 'work' | 'work_item' | 'note' | 'comment';
export type FollowType = 'work' | 'series';

/** Something a reader does that needs them signed in. */
export type Action =
    | { kind: 'like'; type: LikeType; id: number }
    | { kind: 'follow'; type: FollowType; id: number }
    | { kind: 'comment'; work_item_id: number; body: string; reply_to: number | null };

/** What came back, told to every part of the page that shows it. */
export type Done = { action: Action; status: number; data: any };

const KEY = 'eyiPending';
const DONE = 'eyi:done';
/** A Like or comment left waiting this long is stale: it doesn't fire on a later sign-in. */
const KEEP_MS = 20 * 60 * 1000;

const here = () => window.location.pathname + window.location.search;

/** Sends an action. Like and Follow are set, not toggled, so carrying one on after sign-in is safe. */
export async function act(action: Action, undo = false): Promise<Done> {
    let res: { status: number; data: any };
    if (action.kind === 'comment') {
        res = await json('POST', '/comments', { work_item_id: action.work_item_id, body: action.body, reply_to: action.reply_to });
    } else {
        const base = action.kind === 'like' ? '/likes' : '/follows';
        res = await json(undo ? 'DELETE' : 'PUT', `${base}/${action.type}/${action.id}`);
    }
    const done: Done = { action, status: res.status, data: res.data };
    if (!undo) {
        window.dispatchEvent(new CustomEvent<Done>(DONE, { detail: done }));
    }

    return done;
}

/** Signed out: keep what they tapped, ask them to sign in; it carries on once they're in (even back from Google). */
export function ask(action: Action, detail: SignInDetail): void {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ action, path: here(), at: Date.now() }));
    } catch {
        /* private mode: the card still opens, the tap just won't carry on */
    }
    askSignIn(detail);
}

/** The card closed without signing in: forget the tap. */
export function forgetPending(): void {
    try {
        sessionStorage.removeItem(KEY);
    } catch {
        /* nothing kept */
    }
}

/** Runs the kept tap once, on the page it was made on. */
export function resumePending(): void {
    let saved: { action: Action; path: string; at: number } | null = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(KEY) ?? 'null');
        sessionStorage.removeItem(KEY);
    } catch {
        return;
    }
    if (!saved || saved.path !== here() || Date.now() - saved.at > KEEP_MS) {
        return;
    }
    void act(saved.action);
}

const same = (a: Action, b: Action) =>
    a.kind === b.kind &&
    (a.kind === 'comment' ? b.kind === 'comment' && a.work_item_id === b.work_item_id && a.reply_to === b.reply_to : b.kind !== 'comment' && a.type === b.type && a.id === b.id);

/** Hears actions done anywhere on the page (a tap here, or one carried on after sign-in). */
export function useDone(match: Action | null, on: (done: Done) => void): void {
    const ref = useRef(on);
    ref.current = on;
    const key = match ? JSON.stringify(match.kind === 'comment' ? { ...match, body: '' } : match) : '';
    useEffect(() => {
        if (!match) {
            return;
        }
        const listen = (e: Event) => {
            const done = (e as CustomEvent<Done>).detail;
            if (same(done.action, match)) {
                ref.current(done);
            }
        };
        window.addEventListener(DONE, listen);

        return () => window.removeEventListener(DONE, listen);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);
}

/** Hears every action done on the page (the comment list uses it to add what was posted). */
export function useAnyDone(on: (done: Done) => void): void {
    const ref = useRef(on);
    ref.current = on;
    useEffect(() => {
        const listen = (e: Event) => ref.current((e as CustomEvent<Done>).detail);
        window.addEventListener(DONE, listen);

        return () => window.removeEventListener(DONE, listen);
    }, []);
}

export const useSignedIn = () => usePage().props.auth.user !== null;

/**
 * A Like: on/off, its count, and `pop` (a counter that bumps when it turns on, so the heart blooms).
 * Turns on at once and settles to the real count; a failed send puts it back.
 */
export function useLike(type: LikeType, id: number, liked0: boolean, count0: number, why: SignInDetail) {
    const signedIn = useSignedIn();
    const [liked, setLiked] = useState(liked0);
    const [count, setCount] = useState(count0);
    const [pop, setPop] = useState(0);
    const likedRef = useRef(liked);
    likedRef.current = liked;
    useEffect(() => {
        setLiked(liked0);
        setCount(count0);
    }, [liked0, count0]);

    const action: Action = { kind: 'like', type, id };
    useDone(action, ({ status, data }) => {
        if (status !== 200) {
            return;
        }
        if (!likedRef.current) {
            setPop((p) => p + 1);
        }
        setLiked(true);
        setCount(Number(data.count ?? 0));
    });

    const toggle = async () => {
        if (!signedIn) {
            ask(action, why);

            return;
        }
        const next = !liked;
        setLiked(next);
        setCount((c) => Math.max(0, c + (next ? 1 : -1)));
        if (next) {
            setPop((p) => p + 1);
        }
        const { status, data } = await act(action, !next);
        if (status === 200) {
            setCount(Number(data.count ?? 0));
        } else {
            setLiked(!next);
            setCount((c) => Math.max(0, c + (next ? -1 : 1)));
        }
    };

    return { liked, count, pop, toggle };
}

/** A Follow: on/off at once, put back if the send fails. */
export function useFollow(type: FollowType, id: number, following0: boolean, why: SignInDetail) {
    const signedIn = useSignedIn();
    const [following, setFollowing] = useState(following0);
    useEffect(() => setFollowing(following0), [following0]);

    const action: Action = { kind: 'follow', type, id };
    useDone(action, ({ status }) => status === 200 && setFollowing(true));

    const toggle = async () => {
        if (!signedIn) {
            ask(action, why);

            return;
        }
        const next = !following;
        setFollowing(next);
        const { status } = await act(action, !next);
        if (status !== 200) {
            setFollowing(!next);
        }
    };

    return { following, toggle };
}

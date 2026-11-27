/**
 * Writing without a connection (admin). Drafts the server hasn't got yet wait in localStorage and go as soon as
 * the connection is back; works she keeps on this device are kept whole by the service worker (public/admin-sw.js).
 */
import { useEffect, useState } from 'react';

type Json = Record<string, unknown>;

/** A chapter's changes the server hasn't confirmed: the page (if it changed), other fields, and the draft it grew from. */
export type LocalDraft = { url: string; body?: Json; fields: Json; base: number; at: number; clash?: boolean };

const DRAFT = 'eyiAdmWriteDraft:';
const KEEP = 'eyiAdmKeep';

/* ---------- connection ---------- */
const listeners = new Set<(on: boolean) => void>();
let online = typeof navigator === 'undefined' ? true : navigator.onLine;

function setOnline(on: boolean) {
    if (on === online) {
        return;
    }
    online = on;
    listeners.forEach((f) => f(on));
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => setOnline(true));
    window.addEventListener('offline', () => setOnline(false));
}

export const isOnline = () => online;

/** A request came back with no connection although the browser still says online (captive wifi, a dead line). */
export function noConnection() {
    setOnline(false);
    /* the browser won't say 'online' again if it never said 'offline': try for ourselves */
    const probe = window.setInterval(async () => {
        if (online) {
            window.clearInterval(probe);

            return;
        }
        try {
            await fetch('/up', { method: 'HEAD', cache: 'no-store' });
            window.clearInterval(probe);
            setOnline(true);
        } catch {
            /* still away */
        }
    }, 8000);
}

export function onConnection(f: (on: boolean) => void): () => void {
    listeners.add(f);

    return () => listeners.delete(f);
}

export function useOnline(): boolean {
    const [on, setOn] = useState(online);
    useEffect(() => {
        setOn(online);

        return onConnection(setOn);
    }, []);

    return on;
}

/* ---------- drafts waiting on this device ---------- */
export const localDraft = {
    get(id: number): LocalDraft | null {
        try {
            return JSON.parse(localStorage.getItem(DRAFT + id) ?? 'null') as LocalDraft | null;
        } catch {
            return null;
        }
    },
    put(id: number, d: LocalDraft) {
        try {
            localStorage.setItem(DRAFT + id, JSON.stringify(d));
        } catch {
            /* storage full: the open page still holds it */
        }
    },
    drop(id: number) {
        localStorage.removeItem(DRAFT + id);
    },
    all(): [number, LocalDraft][] {
        return Object.keys(localStorage)
            .filter((k) => k.startsWith(DRAFT))
            .map((k) => [Number(k.slice(DRAFT.length)), localDraft.get(Number(k.slice(DRAFT.length)))] as [number, LocalDraft | null])
            .filter((e): e is [number, LocalDraft] => !!e[1]);
    },
};

const xsrf = (): string => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** Drafts left on this device by pages that are no longer open go now. One changed elsewhere meanwhile waits for her, marked. */
let sending = false;
let openItem: number | null = null;

/** The writing screen sends its own chapter's draft; the background sender leaves it alone. */
export const writingItem = (id: number | null) => {
    openItem = id;
};

export async function sendWaitingDrafts() {
    if (sending || !online) {
        return;
    }
    sending = true;
    for (const [id, d] of localDraft.all()) {
        if (id === openItem || d.clash) {
            continue;
        }
        const body: Json = { ...d.fields, base: d.base };
        if (d.body) {
            body.body = d.body;
        }
        try {
            const res = await fetch(d.url, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                body: JSON.stringify(body),
            });
            const now = localDraft.get(id);
            if (!now || now.at !== d.at) {
                continue;
            }
            if (res.status === 409) {
                localDraft.put(id, { ...d, clash: true });
            } else if (res.ok || res.status === 404 || res.status === 422) {
                localDraft.drop(id);
            }
        } catch {
            break;
        }
    }
    sending = false;
}

/* ---------- the service worker ---------- */
let worker: Promise<ServiceWorkerRegistration | null> | null = null;

export function startOffline() {
    if (!('serviceWorker' in navigator) || worker) {
        return;
    }
    worker = navigator.serviceWorker.register('/admin-sw.js', { scope: '/' }).catch(() => null);
    onConnection((on) => on && sendWaitingDrafts());
    window.setTimeout(() => sendWaitingDrafts(), 1500);
}

async function ask<T>(message: Json): Promise<T | null> {
    if (!('serviceWorker' in navigator)) {
        return null;
    }
    const reg = await navigator.serviceWorker.ready;
    const sw = reg.active;
    if (!sw) {
        return null;
    }

    return new Promise((resolve) => {
        const ch = new MessageChannel();
        ch.port1.onmessage = (e) => resolve(e.data as T);
        sw.postMessage(message, [ch.port2]);
    });
}

/* ---------- works kept on this device ---------- */
export const keptWorks = (): string[] => {
    try {
        return JSON.parse(localStorage.getItem(KEEP) ?? '[]') as string[];
    } catch {
        return [];
    }
};

export const isKept = (slug: string) => keptWorks().includes(slug);

function markKept(slug: string, on: boolean) {
    const all = keptWorks().filter((s) => s !== slug);
    localStorage.setItem(KEEP, JSON.stringify(on ? [...all, slug] : all));
}

/** Keep (or refresh) a work's pages on this device. Resolves with the size kept, in bytes. */
export async function keepWork(slug: string, paths: string[], version: string): Promise<number | null> {
    markKept(slug, true);
    const r = await ask<{ bytes: number }>({ type: 'keep', slug, paths, version });

    return r?.bytes ?? null;
}

export async function dropWork(slug: string) {
    markKept(slug, false);
    await ask({ type: 'drop', slug });
}

export async function keptSize(slug: string): Promise<number | null> {
    return (await ask<{ bytes: number | null }>({ type: 'size', slug }))?.bytes ?? null;
}

/** Signing out: nothing of the admin stays kept on this device (drafts not sent yet stay, so no writing is lost). */
export async function forgetKept() {
    localStorage.removeItem(KEEP);
    await Promise.race([ask({ type: 'clear' }), new Promise((r) => window.setTimeout(r, 800))]);
}

/** What kind of device this is, as the server names it (App\Support\DeviceKind). */
export function deviceKind(ua = navigator.userAgent): 'phone' | 'tablet' | 'laptop' {
    if (/iPad|Tablet/.test(ua) || (/Android/.test(ua) && !/Mobile/.test(ua))) {
        return 'tablet';
    }

    return /iPhone|iPod|Mobile/.test(ua) ? 'phone' : 'laptop';
}

export const megabytes = (bytes: number) => `${Math.max(0.1, bytes / 1048576).toFixed(1)} MB`;

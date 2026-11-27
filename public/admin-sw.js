/*
 * The admin's service worker: writing goes on without a connection.
 *
 * - Built files and fonts are kept as they're used (they never change under the same name).
 * - Works she keeps on this device ("Keep on this device" on a work's Chapters tab): every writing page of that
 *   work, and the work page itself, is kept, both as a first load (HTML) and as an Inertia visit (JSON). Kept pages
 *   always try the network first and quietly fall back to the kept copy when there's no connection.
 * - Each save of a kept page refreshes its kept copy a few seconds later, so an offline visit shows the latest draft.
 * Nothing else is cached. Drafts written offline live in localStorage (see resources/js/lib/offline.ts).
 */
const ASSETS = 'eyi-adm-assets-v1';
const PAGES = 'eyi-adm-pages-v1';
const KEPT = '/__eyi/kept';
const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

let kept = null; /* { slug: [paths] } */
const refreshing = new Map();

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

async function keptSet() {
    if (kept) {
        return kept;
    }
    const res = await (await caches.open(PAGES)).match(KEPT);
    kept = res ? await res.json() : {};

    return kept;
}

async function saveKept() {
    await (await caches.open(PAGES)).put(KEPT, new Response(JSON.stringify(kept), { headers: { 'Content-Type': 'application/json' } }));
}

const keyOf = (path, inertia) => `${path}${path.includes('?') ? '&' : '?'}__eyi=${inertia ? 'i' : 'h'}`;
const pathOf = (url) => url.pathname + url.search;

async function isKept(path) {
    const set = await keptSet();
    const bare = path.split('?')[0];

    return Object.values(set).some((paths) => paths.some((p) => p === path || p.split('?')[0] === bare));
}

/* ---------- fetches ---------- */
self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);

    if (FONT_HOSTS.includes(url.hostname) && req.method === 'GET') {
        e.respondWith(cacheFirst(req));

        return;
    }
    if (url.origin !== self.location.origin) {
        return;
    }
    if (req.method === 'GET' && url.pathname.startsWith('/build/')) {
        e.respondWith(cacheFirst(req));

        return;
    }
    if (req.method === 'PATCH' && /^\/works\/[^/]+\/write\/\d+$/.test(url.pathname)) {
        e.respondWith(
            fetch(req).then((res) => {
                if (res.ok) {
                    e.waitUntil(refreshSoon(url.pathname));
                }

                return res;
            }),
        );

        return;
    }
    const inertia = req.headers.get('X-Inertia') === 'true';
    if (req.method !== 'GET' || (!inertia && req.mode !== 'navigate') || req.headers.get('X-Inertia-Partial-Data')) {
        return;
    }
    e.respondWith(page(req, pathOf(url), inertia));
});

async function cacheFirst(req) {
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(req);
    if (hit) {
        return hit;
    }
    const res = await fetch(req);
    if (res.ok || res.type === 'opaque') {
        cache.put(req, res.clone());
    }

    return res;
}

/** A page: the network first; a kept copy when there's no connection. */
async function page(req, path, inertia) {
    try {
        const res = await fetch(req);
        if (res.ok && !res.redirected && (await isKept(path))) {
            (await caches.open(PAGES)).put(keyOf(path, inertia), res.clone());
        }

        return res;
    } catch (err) {
        const hit = await (await caches.open(PAGES)).match(keyOf(path, inertia));
        if (hit) {
            return hit;
        }
        throw err;
    }
}

/* ---------- keeping a work ---------- */
async function fetchPage(path, inertia, version) {
    const headers = inertia ? { 'X-Inertia': 'true', 'X-Inertia-Version': version || '', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' } : { Accept: 'text/html' };
    const res = await fetch(path, { credentials: 'same-origin', headers, redirect: 'follow' });
    if (res.ok && !res.redirected) {
        await (await caches.open(PAGES)).put(keyOf(path, inertia), res);

        return true;
    }

    return false;
}

let version = '';
function refreshSoon(path) {
    if (refreshing.has(path)) {
        return refreshing.get(path);
    }
    const job = new Promise((r) => setTimeout(r, 6000))
        .then(async () => {
            refreshing.delete(path);
            if (await isKept(path)) {
                await fetchPage(path, true, version);
                await fetchPage(path, false, version);
            }
        })
        .catch(() => refreshing.delete(path));
    refreshing.set(path, job);

    return job;
}

/** Every built file, so a writing page opens with no connection even on a fresh start. */
async function keepBuild() {
    const res = await fetch('/build/manifest.json', { cache: 'no-store' });
    if (!res.ok) {
        return;
    }
    const manifest = await res.json();
    const files = new Set();
    Object.values(manifest).forEach((m) => {
        files.add('/build/' + m.file);
        (m.css || []).forEach((c) => files.add('/build/' + c));
        (m.assets || []).forEach((a) => files.add('/build/' + a));
    });
    const cache = await caches.open(ASSETS);
    /* files from an older build go */
    (await cache.keys()).forEach((r) => {
        const u = new URL(r.url);
        if (u.origin === self.location.origin && u.pathname.startsWith('/build/') && !files.has(u.pathname)) {
            cache.delete(r);
        }
    });
    await Promise.all([...files].map(async (f) => ((await cache.match(f)) ? null : cache.add(f).catch(() => null))));
}

async function sizeOf(slug) {
    const set = await keptSet();
    const cache = await caches.open(PAGES);
    let bytes = 0;
    for (const path of set[slug] || []) {
        for (const inertia of [true, false]) {
            const hit = await cache.match(keyOf(path, inertia));
            if (hit) {
                bytes += (await hit.blob()).size;
            }
        }
    }

    return bytes;
}

async function drop(slug) {
    const set = await keptSet();
    const cache = await caches.open(PAGES);
    const others = new Set(
        Object.entries(set)
            .filter(([s]) => s !== slug)
            .flatMap(([, p]) => p),
    );
    for (const path of set[slug] || []) {
        if (!others.has(path)) {
            await cache.delete(keyOf(path, true));
            await cache.delete(keyOf(path, false));
        }
    }
    delete set[slug];
    await saveKept();
}

self.addEventListener('message', (e) => {
    const m = e.data || {};
    const reply = (data) => e.ports[0] && e.ports[0].postMessage(data);
    if (m.version) {
        version = m.version;
    }
    const job = (async () => {
        if (m.type === 'keep') {
            const set = await keptSet();
            const before = set[m.slug] || [];
            set[m.slug] = m.paths;
            await saveKept();
            /* pages no longer in the work (a chapter deleted) go */
            const cache = await caches.open(PAGES);
            for (const p of before.filter((p) => !m.paths.includes(p))) {
                await cache.delete(keyOf(p, true));
                await cache.delete(keyOf(p, false));
            }
            await keepBuild().catch(() => null);
            for (const path of m.paths) {
                await fetchPage(path, true, m.version).catch(() => false);
                await fetchPage(path, false, m.version).catch(() => false);
            }
            reply({ bytes: await sizeOf(m.slug) });
        } else if (m.type === 'drop') {
            await drop(m.slug);
            reply({ ok: true });
        } else if (m.type === 'size') {
            reply({ bytes: (await keptSet())[m.slug] ? await sizeOf(m.slug) : null });
        } else if (m.type === 'clear') {
            await Promise.all((await caches.keys()).filter((k) => k.startsWith('eyi-adm-')).map((k) => caches.delete(k)));
            kept = {};
            reply({ ok: true });
        }
    })();
    e.waitUntil(job);
});

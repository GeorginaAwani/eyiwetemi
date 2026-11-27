/*
 * The reading site's service worker: light offline reading.
 *
 * - The chapter open on the screen and the next one are kept quietly (the reader asks for them; nothing to download).
 * - Built files and fonts are kept as they're used, so a kept chapter opens with no connection.
 * - Pages always try the network first; a kept chapter answers only when there's no connection.
 * Only the last few chapters read are kept; signing out forgets them.
 */
const ASSETS = 'eyi-read-assets-v1';
const PAGES = 'eyi-read-pages-v1';
const LIST = '/__eyi/read';
const KEEP = 6;
const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com'];

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

const keyOf = (path, inertia) => `${path}${path.includes('?') ? '&' : '?'}__eyi=${inertia ? 'i' : 'h'}`;

self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET') {
        return;
    }
    if (FONT_HOSTS.includes(url.hostname) || (url.origin === self.location.origin && url.pathname.startsWith('/build/'))) {
        e.respondWith(cacheFirst(req));

        return;
    }
    if (url.origin !== self.location.origin) {
        return;
    }
    const inertia = req.headers.get('X-Inertia') === 'true';
    if ((!inertia && req.mode !== 'navigate') || req.headers.get('X-Inertia-Partial-Data')) {
        return;
    }
    e.respondWith(
        fetch(req).catch(async (err) => {
            const hit = await (await caches.open(PAGES)).match(keyOf(url.pathname + url.search, inertia));
            if (hit) {
                return hit;
            }
            throw err;
        }),
    );
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

async function keep(paths, version) {
    const cache = await caches.open(PAGES);
    const res = await cache.match(LIST);
    let list = res ? await res.json() : [];
    for (const path of paths) {
        for (const inertia of [true, false]) {
            const headers = inertia
                ? { 'X-Inertia': 'true', 'X-Inertia-Version': version || '', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' }
                : { Accept: 'text/html' };
            try {
                const page = await fetch(path, { credentials: 'same-origin', headers });
                if (page.ok && !page.redirected) {
                    await cache.put(keyOf(path, inertia), page);
                }
            } catch {
                /* no connection: keep what we had */
            }
        }
        list = [path, ...list.filter((p) => p !== path)];
    }
    /* only the last few chapters stay */
    for (const old of list.slice(KEEP)) {
        await cache.delete(keyOf(old, true));
        await cache.delete(keyOf(old, false));
    }
    await cache.put(LIST, new Response(JSON.stringify(list.slice(0, KEEP)), { headers: { 'Content-Type': 'application/json' } }));
}

self.addEventListener('message', (e) => {
    const m = e.data || {};
    const reply = (data) => e.ports[0] && e.ports[0].postMessage(data);
    const job = (async () => {
        if (m.type === 'keep') {
            await keep(m.paths, m.version);
        } else if (m.type === 'clear') {
            await Promise.all((await caches.keys()).filter((k) => k.startsWith('eyi-read-')).map((k) => caches.delete(k)));
        }
        reply({ ok: true });
    })();
    e.waitUntil(job);
});

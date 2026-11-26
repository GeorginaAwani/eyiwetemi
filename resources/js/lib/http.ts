/** JSON requests to the app outside Inertia (the sign-in card, live checks). Laravel reads the XSRF cookie. */
export type JsonResult<T> = { status: number; data: Partial<T> };

const xsrf = (): string => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** Never throws: no connection comes back as status 0. */
export async function json<T = Record<string, unknown>>(method: 'GET' | 'POST', url: string, body?: Record<string, unknown>): Promise<JsonResult<T>> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: body ? JSON.stringify(body) : undefined,
        });

        return { status: res.status, data: (await res.json().catch(() => ({}))) as Partial<T> };
    } catch {
        return { status: 0, data: {} };
    }
}

/** The first message Laravel gave for each field. */
export const fieldErrors = (data: unknown): Record<string, string> => {
    const errors = (data as { errors?: Record<string, string[]> })?.errors ?? {};

    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]));
};

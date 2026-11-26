/** What the sign-in card says about what you were doing, and what carries on once you're in. */
export type SignInDetail = {
    /** "Sign in to like Chapter 3" */
    why?: string;
    /** The line under it: "Chapter 3 · The Ember Gate" */
    sub?: string;
    /** The work's cover, or its colour when it has none. */
    picture?: { src?: string | null; color?: string | null };
    tab?: 'in' | 'up';
    /** Runs after signing in, so the Like, Follow or comment carries on. */
    then?: () => void;
    follow?: number | null;
};

export const askSignIn = (detail: SignInDetail = {}): void => {
    window.dispatchEvent(new CustomEvent<SignInDetail>('eyi:signin', { detail }));
};

/** The prototype's first guess at a username: "Tolu Ade" → toluade; a long pair keeps a dot. */
export function usernameFrom(name: string): string {
    const words = name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .split(/[\s-]+/)
        .filter(Boolean);

    if (!words.length) {
        return '';
    }
    let u = words[0] + (words[1] ?? '');
    if (u.length > 12 && words[1]) {
        u = `${words[0]}.${words[1]}`;
    }

    return u.slice(0, 20);
}

import { SignInFlash } from '@/Components/site/SignIn';
import { Notice, SiteChrome } from './site';

/** You, when you're signed in. */
export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar_seed: string;
    /** Your reading defaults, so a new device opens chapters the way you set them. */
    reading: { size: number; font: 'serif' | 'sans'; width: 'narrow' | 'wide'; lines: 'close' | 'airy'; theme: string };
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User | null;
    };
    site: SiteChrome;
    signin: SignInFlash;
    notice: Notice | null;
};

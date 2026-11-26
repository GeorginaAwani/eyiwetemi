import { SignInFlash } from '@/Components/site/SignIn';
import { Notice, SiteChrome } from './site';

/** You, when you're signed in. */
export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar_seed: string;
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

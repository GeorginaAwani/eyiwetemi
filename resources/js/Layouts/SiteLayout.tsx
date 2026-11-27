import { Head, usePage } from "@inertiajs/react";
import { ReactNode, useEffect } from "react";
import Footer from "@/Components/site/Footer";
import Header from "@/Components/site/Header";
import NoticeLine from "@/Components/site/NoticeLine";
import Search from "@/Components/site/Search";
import SignIn from "@/Components/site/SignIn";
import { countPage } from "@/lib/count";
import { watchReveals } from "@/lib/motion";
import { FooterPage, Section } from "@/types/site";

type Props = {
    /** The page title; the site name follows it. Home passes none. */
    title?: string;
    children: ReactNode;
    /** Which header item is the page you're on. */
    section?: Section;
    /** Which footer link is the page you're on. */
    footer?: FooterPage;
    /** Reading pages don't show the notice line. */
    notice?: boolean;
    /** What sits in the notice spot when there's no notice (Home: the newest note). */
    noteLine?: ReactNode;
    /** The email pages leave the footer's sign-up out. */
    newsletter?: boolean;
};

/** The reading site's frame: header, the notice line, the page, the footer. */
export default function SiteLayout({
    title,
    children,
    section,
    footer,
    notice = true,
    noteLine,
    newsletter = true,
}: Props) {
    const { url } = usePage();

    /* each page's lines and pictures settle in as they come into view */
    useEffect(() => watchReveals(), [url]);
    /* a visit to a site page is counted as it opens */
    useEffect(() => countPage(url), [url]);

    return (
        <>
            <Head title={title ?? ""} />
            <Header current={section} />
            <main key={url}>
                {notice ? <NoticeLine fallback={noteLine} /> : noteLine}
                {children}
            </main>
            <Footer key={`f-${url}`} current={footer} newsletter={newsletter} />
            <Search />
            <SignIn />
        </>
    );
}

import SiteLayout from '@/Layouts/SiteLayout';

/** Home: the reading room. Its sections are built in the next step; for now it shows the shared frame. */
export default function Home() {
    return (
        <SiteLayout>
            <div className="wrap" style={{ minHeight: '60vh' }} />
        </SiteLayout>
    );
}

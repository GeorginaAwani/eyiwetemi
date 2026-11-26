import AdminLayout, { AdminSection } from '../../Layouts/AdminLayout';

/** A section of the admin. Step 1 is the shell: each section's page is built in its own step. */
export default function Section({ section }: { section: AdminSection }) {
    return <AdminLayout section={section} />;
}

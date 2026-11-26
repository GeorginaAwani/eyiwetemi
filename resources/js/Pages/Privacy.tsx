import ContentsPage, { ContentsPageProps } from "@/Components/site/ContentsPage";

/** Privacy: her sections, with the contents beside them. */
export default function Privacy(props: ContentsPageProps) {
    return <ContentsPage title="Privacy" footer="privacy" {...props} />;
}

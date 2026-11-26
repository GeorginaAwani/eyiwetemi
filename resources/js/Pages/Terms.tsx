import ContentsPage, { ContentsPageProps } from "@/Components/site/ContentsPage";

/** Terms: her sections, laid out like Privacy. */
export default function Terms(props: ContentsPageProps) {
    return <ContentsPage title="Terms" footer="terms" {...props} />;
}

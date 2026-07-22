import { SiteDocumentsSurface, type SiteDocumentsProps } from '../documents';

export type SiteDocumentsData = SiteDocumentsProps & {
    locked?: boolean;
    href: string;
};

export function SiteProfileDocuments({ data }: { data: SiteDocumentsData }) {
    return <SiteDocumentsSurface {...data} embedded />;
}

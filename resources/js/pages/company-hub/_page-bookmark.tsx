import { HubBookmarkButton } from './_bookmarks';

export function PageBookmark({
    pageId,
    title,
    saved,
}: {
    pageId: number;
    title: string;
    saved: boolean;
}) {
    return (
        <div className="hub-page-bookmark">
            <HubBookmarkButton
                subject={{ subject_type: 'page', subject_id: pageId }}
                title={title}
                saved={saved}
                compact
            />
        </div>
    );
}

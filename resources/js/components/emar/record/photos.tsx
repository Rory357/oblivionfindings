import { Download, Eye, Image as ImageIcon, Info, Pill } from 'lucide-react';
import { useState } from 'react';

import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import {
    compactMenu,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';

import { ReadingState } from './reading';
import { ConcealedCaption, Wrap } from './ui';
import { useRecordJson } from './use-record-json';

/** One staff photo of a supplied pack (Lane C11, PackPhotoReader::history). */
export type RecordPackPhoto = {
    id: number;
    kind: 'staff';
    url: string;
    download_url: string;
    taken_at: string | null;
    taken_by: string | null;
    pack: string | null;
    pharmacy: string | null;
    pack_changed: boolean;
    medication_id: number;
    medication_name: string;
    medication_strength: string | null;
    controlled: boolean;
    state: 'current' | 'changed' | 'replaced';
    original_name: string;
    mime: string;
    bytes: number;
    description: string | null;
};

const STATE = {
    current: ['Current pack', 'success'],
    changed: ['Pack or brand changed — check the label', 'warning'],
    replaced: ['Replaced', 'neutral'],
} as const;

/**
 * P02 person record › Medicines › Photos (approved P02 v1; Lane C11): every
 * staff photo of the packs supplied, newest first. A medicine filter comes
 * from "View pack photos" on a medicine (?med=). Staff take these photos
 * when stock is received; they never block recording.
 */
export function PhotosSection({
    clientId,
    medicationId,
    onMedicine,
    onShowAll,
}: {
    clientId: number;
    medicationId: number | null;
    onMedicine: (id: number) => void;
    onShowAll: () => void;
}) {
    const { data, load, reload } = useRecordJson<{
        photos: RecordPackPhoto[];
        concealed: number;
    }>(
        `/emar/clients/${clientId}/record/photos${medicationId ? `?medication_id=${medicationId}` : ''}`,
    );
    const [file, setFile] = useState<PreviewFile | null>(null);
    const context = useEntityContextMenu<RecordPackPhoto>();
    if (load !== 'ready' || !data)
        return <ReadingState load={load} reload={reload} />;
    const label = (photo: RecordPackPhoto) =>
        `${photo.medication_name} ${photo.medication_strength ?? ''}`.trim();
    const view = (photo: RecordPackPhoto) =>
        setFile({
            id: photo.id,
            name: `${label(photo)} — photo of the supplied pack`,
            filename: photo.original_name,
            mime: photo.mime,
            bytes: photo.bytes,
            source: `Taken ${photo.taken_at ? formatDateTime(photo.taken_at) : 'date unknown'} by ${photo.taken_by ?? 'unknown'} · ${photo.pack ?? 'pack unknown'}${photo.pharmacy ? ` · ${photo.pharmacy}` : ''}`,
            previewUrl: photo.url,
            downloadUrl: photo.download_url,
        });
    const menu = (photo: RecordPackPhoto): MenuItem[] =>
        compactMenu([
            { label: 'View photo', icon: Eye, onClick: () => view(photo) },
            {
                label: 'Download the original',
                icon: Download,
                onClick: () => window.location.assign(photo.download_url),
            },
            { separator: true },
            {
                label: 'View medicine details',
                icon: Pill,
                onClick: () => onMedicine(photo.medication_id),
            },
        ]);
    const filtered = medicationId !== null && data.photos.length > 0;
    return (
        <section
            aria-label="Pack photos"
            className="flex min-w-0 flex-col gap-2.5"
        >
            <ListCaption
                title={
                    filtered
                        ? `Pack photos · ${label(data.photos[0])}`
                        : 'Pack photos'
                }
                caption={
                    <>
                        {data.photos.length}{' '}
                        {data.photos.length === 1 ? 'photo' : 'photos'} · newest
                        first
                        {data.concealed ? ' · ' : ''}
                        <ConcealedCaption n={data.concealed} listed={false} />
                    </>
                }
                right={
                    medicationId !== null ? (
                        <Button size="sm" variant="ghost" onClick={onShowAll}>
                            Show every medicine
                        </Button>
                    ) : null
                }
            />
            <Alert role="note">
                <Info className="size-4" />
                <AlertTitle>
                    Check the label — the picture is a guide only
                </AlertTitle>
                <AlertDescription>
                    Staff take these photos when stock is received (Stock &
                    controlled drugs). They’re optional and never block
                    recording. When the pack or brand changes, the older photo
                    is kept here and marked replaced.
                </AlertDescription>
            </Alert>
            {data.photos.length ? (
                <EntityTable
                    rows={data.photos}
                    rowKey={(photo) => String(photo.id)}
                    identityLabel="Photo"
                    identityWidth="2.2fr"
                    rowHeight="content"
                    minWidth={900}
                    identity={(photo) => ({
                        mark: (
                            <img
                                src={photo.url}
                                alt=""
                                className="size-[42px] rounded-[8px] border object-cover"
                            />
                        ),
                        name: label(photo),
                        subline: (
                            <Wrap>
                                {photo.pack ?? 'Pack unknown'}
                                {photo.pharmacy ? ` · ${photo.pharmacy}` : ''}
                            </Wrap>
                        ),
                    })}
                    columns={[
                        {
                            key: 'taken',
                            label: 'Taken',
                            width: '1.2fr',
                            cell: (photo) => (
                                <span className="text-[12.5px]">
                                    {photo.taken_at
                                        ? formatDateTime(photo.taken_at)
                                        : 'Date unknown'}{' '}
                                    by {photo.taken_by ?? 'unknown'}
                                </span>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'Status',
                            width: '1.3fr',
                            cell: (photo) => (
                                <StatusBadge variant={STATE[photo.state][1]}>
                                    {STATE[photo.state][0]}
                                </StatusBadge>
                            ),
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={view}
                    onRowContextMenu={context.open}
                    mutedFor={(photo) => photo.state === 'replaced'}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState
                        variant="compact"
                        icon={ImageIcon}
                        title="No pack photos yet"
                        description="A photo can be added when stock is received. It’s optional."
                    />
                </Card>
            )}
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={ImageIcon}
                    title={`${label(context.ctx.record)} photo`}
                    items={menu(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            <FilePreviewDialog file={file} onClose={() => setFile(null)} />
        </section>
    );
}

import { FilePreviewDialog } from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/fleet-utils';
import type { Props } from '@/pages/fleet-assets/assets/show';
import { Link } from '@inertiajs/react';
import {
    Camera,
    FileText,
    Package,
    Pencil,
    Printer,
    QrCode,
    Shield,
} from 'lucide-react';
import { useState } from 'react';
import {
    ActionRow,
    Empty,
    Fact,
    Panel,
    State,
    TextAction,
    human,
} from './presentation';
import type { AssetFile, ProfileAction, ProfileWorkspace } from './types';

export function AssetIdentityView({
    asset,
    data,
    onEdit,
    onAction,
    onQr,
    onDocuments,
}: {
    asset: Props['asset'];
    data: ProfileWorkspace;
    onEdit: () => void;
    onAction: (action: ProfileAction) => void;
    onQr: () => void;
    onDocuments: () => void;
}) {
    const [preview, setPreview] = useState<AssetFile | null>(null);
    const mutable = data.ready && asset.status !== 'retired';
    const canEdit = data.permissions.update && mutable;
    const canPhoto = canEdit && data.permissions.manageDocuments;
    const currentFiles = data.documents.filter(
        (file) => file.current && !file.archived,
    );
    return (
        <div className="space-y-5">
            <div className="grid items-stretch gap-5 lg:grid-cols-[minmax(240px,0.8fr)_2.2fr]">
                <Card className="gap-4 p-5">
                    <div className="flex min-h-48 flex-col items-center justify-center gap-4 rounded-lg bg-muted/70 p-5">
                        {data.photo_url ? (
                            <img
                                src={data.photo_url}
                                alt={`${asset.name} profile photo`}
                                className="max-h-64 max-w-full rounded-lg object-contain"
                            />
                        ) : (
                            <Package className="size-16 text-muted-foreground" />
                        )}
                        {canPhoto && (
                            <Button
                                variant="outline"
                                onClick={() => onAction('set_photo')}
                            >
                                <Camera />
                                {data.photo_url
                                    ? 'Change profile photo'
                                    : 'Choose profile photo'}
                            </Button>
                        )}
                        {canPhoto && data.photo_url && (
                            <Button
                                variant="ghost"
                                onClick={() => onAction('remove_photo')}
                            >
                                Remove photo
                            </Button>
                        )}
                    </div>
                    <p className="text-caption font-semibold tracking-wide">
                        {asset.asset_tag} ·{' '}
                        {asset.serial_number || 'Serial number not recorded'}
                    </p>
                    <h2 className="text-page-title">{asset.name}</h2>
                    <p className="text-subtle text-muted-foreground">
                        {human(asset.category)} ·{' '}
                        {asset.site?.name || 'Site not recorded'}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <State value={asset.status} />
                    </div>
                </Card>
                <Panel
                    title="Asset details"
                    icon={Package}
                    actions={
                        canEdit && (
                            <Button variant="outline" onClick={onEdit}>
                                <Pencil />
                                Edit details
                            </Button>
                        )
                    }
                >
                    <dl className="grid gap-x-6 gap-y-5 sm:grid-cols-2 [&>div]:border-b [&>div]:pb-4">
                        {[
                            ['Asset tag', asset.asset_tag],
                            ['Serial number', asset.serial_number],
                            ['Category', human(asset.category)],
                            ['Ownership', data.sources?.ownership.name],
                            [
                                'Linked suppliers',
                                [
                                    ...new Set(
                                        data.sources?.costs.bills
                                            .map((bill) => bill.supplier)
                                            .filter(Boolean),
                                    ),
                                ].join(', ') ||
                                    'No authorised supplier source linked',
                            ],
                            ['Condition', human(data.condition)],
                            ['Manufacturer', asset.manufacturer],
                            ['Model', asset.model],
                            [
                                'Purchase date',
                                asset.purchase_date
                                    ? formatDate(asset.purchase_date)
                                    : null,
                            ],
                            [
                                'Warranty expires',
                                asset.warranty_expires_at
                                    ? formatDate(asset.warranty_expires_at)
                                    : null,
                            ],
                            ['Lifecycle', human(asset.status)],
                            [
                                'Criticality / risk level',
                                asset.risk_level
                                    ? human(asset.risk_level)
                                    : 'Needs classification by asset owner',
                            ],
                        ].map(([label, value]) => (
                            <Fact key={label} label={label!}>
                                {value}
                            </Fact>
                        ))}
                    </dl>
                    {asset.notes && (
                        <p className="text-subtle">{asset.notes}</p>
                    )}
                    {data.permissions.manageOwnership && mutable && (
                        <Button
                            variant="outline"
                            onClick={() => onAction('ownership')}
                        >
                            Record ownership
                        </Button>
                    )}
                    <p className="text-caption flex items-start gap-2 text-muted-foreground">
                        <Shield className="size-4 shrink-0" />
                        Custody, Maintenance holds and Finance decisions keep
                        their own history.
                    </p>
                </Panel>
            </div>
            <Panel
                title="QR label"
                icon={QrCode}
                actions={
                    <State value={data.qr ? 'available' : 'not_recorded'} />
                }
            >
                <div className="flex flex-wrap items-center gap-6">
                    {data.qr && (
                        <img
                            src={data.qr.image}
                            alt={`QR code for ${asset.asset_tag}`}
                            className="size-40 rounded-xl border bg-white p-3"
                        />
                    )}
                    <div className="min-w-0 flex-1 space-y-3">
                        <h3 className="text-section-title">
                            {asset.asset_tag} · Stable asset identity
                        </h3>
                        <p className="text-subtle text-muted-foreground">
                            Print a replacement label or download this asset’s
                            QR. Reprints keep the same identity when its name,
                            room or custodian changes.
                        </p>
                        {data.qr ? (
                            <Button onClick={onQr}>
                                <Printer />
                                Print & export QR
                            </Button>
                        ) : canEdit ? (
                            <Button onClick={() => onAction('generate_qr')}>
                                <QrCode />
                                Create QR identity
                            </Button>
                        ) : (
                            <p className="text-subtle">
                                No QR identity has been recorded.
                            </p>
                        )}
                        <Button variant="outline" asChild>
                            <Link
                                href={`/fleet-assets/asset-register/labels/workspace?selected=${asset.id}`}
                            >
                                Bulk QR labels
                            </Link>
                        </Button>
                    </div>
                </div>
            </Panel>
            <Panel
                title="Asset documents"
                icon={FileText}
                actions={
                    <TextAction onClick={onDocuments}>
                        Open document library
                    </TextAction>
                }
            >
                {currentFiles.length ? (
                    <div className="grid gap-4 md:grid-cols-2">
                        {currentFiles.slice(0, 4).map((file) => (
                            <ActionRow
                                key={file.id}
                                icon={FileText}
                                title={file.name}
                                description={`${file.filename} · v${file.version}`}
                                action={
                                    <TextAction
                                        onClick={() => setPreview(file)}
                                    >
                                        {file.downloadUrl
                                            ? 'View file'
                                            : 'File details'}
                                    </TextAction>
                                }
                            />
                        ))}
                    </div>
                ) : (
                    <Empty>No asset documents recorded.</Empty>
                )}
            </Panel>
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
        </div>
    );
}

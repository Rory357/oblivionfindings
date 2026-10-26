import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { Download, FileDiff, ShieldCheck } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

export type ConfigurationDiffTarget = {
    deviceId: number;
    deviceName: string;
    snapshotId: number;
    baselineSnapshotId?: number | null;
};

export type SnapshotComparisonMeta = {
    id: number;
    source_kind: string;
    source_label: string;
    source: string | null;
    captured_at: string | null;
    firmware_version: string | null;
    configuration_hash: string | null;
    content_size: number | null;
    download_href: string | null;
};

export type ConfigurationFieldChange = {
    kind: 'added' | 'removed' | 'changed';
    path: string;
    before: string | null;
    after: string | null;
};

export type ConfigurationDiffLine = {
    kind: 'context' | 'added' | 'removed' | 'separator';
    old_line: number | null;
    new_line: number | null;
    content: string;
};

export type ConfigurationComparisonPayload = {
    device: {
        id: number;
        name: string;
        uid: string | null;
    };
    target: SnapshotComparisonMeta;
    baseline: SnapshotComparisonMeta | null;
    identical: boolean;
    summary: {
        added_fields: number;
        removed_fields: number;
        changed_fields: number;
        added_lines: number;
        removed_lines: number;
    };
    field_changes: ConfigurationFieldChange[];
    field_changes_truncated: boolean;
    line_diff: ConfigurationDiffLine[];
    line_diff_truncated: boolean;
    line_source: 'running_config' | 'structured_json';
    available_snapshots: SnapshotComparisonMeta[];
};

function shortHash(value: string | null | undefined): string {
    return value ? `${value.slice(0, 12)}…` : 'Not recorded';
}

function formatSnapshotChoice(snapshot: SnapshotComparisonMeta): string {
    const captured = snapshot.captured_at ? formatDateTime(snapshot.captured_at) : `Snapshot #${snapshot.id}`;
    return `${captured} · ${snapshot.source_label} (${shortHash(snapshot.configuration_hash)})`;
}

function changeVariant(kind: ConfigurationFieldChange['kind']): 'success' | 'critical' | 'warning' {
    if (kind === 'added') return 'success';
    if (kind === 'removed') return 'critical';
    return 'warning';
}

function changeLabel(kind: ConfigurationFieldChange['kind']): string {
    if (kind === 'added') return 'Added';
    if (kind === 'removed') return 'Removed';
    return 'Changed';
}

export function ConfigurationDiffDialog({
    target,
    onClose,
}: {
    target: ConfigurationDiffTarget | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 900px)', width: 'min(92vw, 900px)' }}
            >
                {target !== null ? <ConfigurationDiffBody target={target} onClose={onClose} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function ConfigurationDiffBody({
    target,
    onClose,
}: {
    target: ConfigurationDiffTarget;
    onClose: () => void;
}) {
    const baselineSelectId = useId();
    const [selectedBaselineId, setSelectedBaselineId] = useState<string>(
        target.baselineSnapshotId ? String(target.baselineSnapshotId) : 'auto',
    );
    const [payload, setPayload] = useState<ConfigurationComparisonPayload | null>(null);
    const [loading, setLoading] = useState<boolean>(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setErrorMessage(null);

        const baselineSegment =
            selectedBaselineId !== 'auto' && selectedBaselineId !== ''
                ? `/${encodeURIComponent(selectedBaselineId)}`
                : '';
        const url = `/security-devices/devices/${target.deviceId}/configuration-snapshots/${target.snapshotId}/compare${baselineSegment}`;

        axios
            .get<ConfigurationComparisonPayload>(url, {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (!active) return;
                setPayload(response.data);
                setLoading(false);
            })
            .catch((error: unknown) => {
                if (!active) return;
                const status =
                    typeof error === 'object' && error !== null && 'response' in error
                        ? (error as { response?: { status?: number } }).response?.status
                        : undefined;
                setErrorMessage(
                    status === 403
                        ? 'You do not have permission to view or compare configuration backups for this device.'
                        : 'Configuration comparison could not be loaded right now.',
                );
                setLoading(false);
            });

        return () => {
            active = false;
        };
    }, [target.deviceId, target.snapshotId, selectedBaselineId]);

    const baselineChoices = (payload?.available_snapshots ?? []).filter(
        (snapshot) => snapshot.id !== target.snapshotId,
    );

    return (
        <div className="space-y-4">
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FileDiff className="h-4 w-4 text-primary" aria-hidden="true" />
                    <span>Compare configuration versions</span>
                </DialogTitle>
                <DialogDescription>
                    Inspect redacted configuration changes for {target.deviceName} between captured snapshots.
                </DialogDescription>
            </DialogHeader>

            {errorMessage ? (
                <SettingsNotice role="alert">{errorMessage}</SettingsNotice>
            ) : null}

            {loading && !payload ? (
                <div className="rounded-lg border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                    Loading configuration comparison…
                </div>
            ) : null}

            {payload ? (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {payload.baseline === null ? (
                            <StatusBadge variant="info">Initial captured version</StatusBadge>
                        ) : payload.identical ? (
                            <StatusBadge variant="success">Identical configuration</StatusBadge>
                        ) : (
                            <StatusBadge variant="warning">Configuration changed</StatusBadge>
                        )}
                        <StatusBadge variant="neutral">
                            +{payload.summary.added_lines} / −{payload.summary.removed_lines} lines
                        </StatusBadge>
                        <StatusBadge variant="neutral">
                            {payload.summary.changed_fields +
                                payload.summary.added_fields +
                                payload.summary.removed_fields}{' '}
                            field changes
                        </StatusBadge>
                        <StatusBadge variant="neutral">
                            <ShieldCheck className="h-3 w-3" aria-hidden="true" />
                            <span>Secrets redacted</span>
                        </StatusBadge>
                    </div>

                    <div className="grid gap-3 md:grid-cols-2">
                        <ReviewCard icon={FileDiff} title="Selected version">
                            <ReviewRow
                                label="Captured"
                                value={
                                    payload.target.captured_at
                                        ? formatDateTime(payload.target.captured_at)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow label="Source" value={payload.target.source_label} />
                            <ReviewRow
                                label="Hash"
                                value={
                                    <span className="font-mono text-xs">
                                        {shortHash(payload.target.configuration_hash)}
                                    </span>
                                }
                            />
                            <ReviewRow
                                label="Firmware"
                                value={payload.target.firmware_version ?? 'Not recorded'}
                            />
                        </ReviewCard>

                        <Card className="py-3 shadow-none">
                            <CardContent className="space-y-2 px-3">
                                <Label htmlFor={baselineSelectId}>Compare against baseline</Label>
                                <Select
                                    value={selectedBaselineId}
                                    onValueChange={(value) => setSelectedBaselineId(value)}
                                    disabled={baselineChoices.length === 0}
                                >
                                    <SelectTrigger id={baselineSelectId}>
                                        <SelectValue placeholder="Previous captured snapshot" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="auto">
                                            Previous captured snapshot (default)
                                        </SelectItem>
                                        {baselineChoices.map((choice) => (
                                            <SelectItem key={choice.id} value={String(choice.id)}>
                                                {formatSnapshotChoice(choice)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {payload.baseline ? (
                                    <div className="space-y-1 pt-1 text-xs text-muted-foreground">
                                        <p>
                                            Baseline captured:{' '}
                                            <span className="font-medium text-foreground">
                                                {payload.baseline.captured_at
                                                    ? formatDateTime(payload.baseline.captured_at)
                                                    : 'Not recorded'}
                                            </span>
                                        </p>
                                        <p>
                                            Source: {payload.baseline.source_label} · Hash{' '}
                                            <span className="font-mono">
                                                {shortHash(payload.baseline.configuration_hash)}
                                            </span>
                                        </p>
                                    </div>
                                ) : (
                                    <p className="pt-1 text-xs text-muted-foreground">
                                        No earlier snapshot is stored for this device at this site yet.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {payload.field_changes.length > 0 ? (
                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold">Structured field changes</h3>
                                {payload.field_changes_truncated ? (
                                    <span className="text-xs text-muted-foreground">
                                        Showing first {payload.field_changes.length} field changes
                                    </span>
                                ) : null}
                            </div>
                            <div className="max-h-56 overflow-y-auto rounded-lg border">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-b bg-muted/40 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Change</th>
                                            <th className="px-3 py-2 font-medium">Configuration path</th>
                                            <th className="px-3 py-2 font-medium">Baseline</th>
                                            <th className="px-3 py-2 font-medium">Selected</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {payload.field_changes.map((change) => (
                                            <tr key={`${change.kind}-${change.path}`}>
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    <StatusBadge
                                                        size="sm"
                                                        variant={changeVariant(change.kind)}
                                                    >
                                                        {changeLabel(change.kind)}
                                                    </StatusBadge>
                                                </td>
                                                <td className="px-3 py-2 font-mono break-all">
                                                    {change.path}
                                                </td>
                                                <td className="px-3 py-2 font-mono text-muted-foreground break-all">
                                                    {change.before ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 font-mono break-all">
                                                    {change.after ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-semibold">
                                {payload.line_source === 'running_config'
                                    ? 'Running configuration line diff'
                                    : 'Normalised configuration line diff'}
                            </h3>
                            {payload.line_diff_truncated ? (
                                <span className="text-xs text-muted-foreground">
                                    Showing first {payload.line_diff.length} diff lines
                                </span>
                            ) : null}
                        </div>

                        {payload.identical && payload.baseline !== null ? (
                            <div className="rounded-lg border bg-muted/20 p-4 text-sm text-muted-foreground">
                                No configuration differences were detected between these two versions.
                            </div>
                        ) : payload.line_diff.length === 0 ? (
                            <div className="rounded-lg border bg-muted/20 p-4 text-sm text-muted-foreground">
                                No diff lines available for this snapshot.
                            </div>
                        ) : (
                            /* eslint-disable-next-line no-restricted-syntax -- Scrollable unified diff code region requires custom overflow container */
                            <div
                                className="max-h-80 overflow-auto rounded-lg border bg-card font-mono text-xs"
                                role="region"
                                aria-label="Configuration unified diff"
                            >
                                <table className="w-full border-collapse">
                                    <tbody>
                                        {payload.line_diff.map((line, index) => {
                                            if (line.kind === 'separator') {
                                                return (
                                                    <tr
                                                        key={`sep-${index}`}
                                                        className="border-y bg-muted/40 text-muted-foreground"
                                                    >
                                                        <td
                                                            colSpan={4}
                                                            className="px-3 py-1 text-center text-[11px]"
                                                        >
                                                            ··· Unchanged lines omitted ···
                                                        </td>
                                                    </tr>
                                                );
                                            }

                                            const rowClass =
                                                line.kind === 'added'
                                                    ? 'bg-status-success-bg/50 text-status-success'
                                                    : line.kind === 'removed'
                                                      ? 'bg-status-critical-bg/50 text-status-critical'
                                                      : 'text-foreground';
                                            const marker =
                                                line.kind === 'added'
                                                    ? '+'
                                                    : line.kind === 'removed'
                                                      ? '−'
                                                      : ' ';

                                            return (
                                                <tr
                                                    key={`${line.kind}-${line.old_line ?? 'n'}-${line.new_line ?? 'n'}-${index}`}
                                                    className={rowClass}
                                                >
                                                    <td className="w-12 border-r px-2 py-0.5 text-right text-muted-foreground select-none">
                                                        {line.old_line ?? ''}
                                                    </td>
                                                    <td className="w-12 border-r px-2 py-0.5 text-right text-muted-foreground select-none">
                                                        {line.new_line ?? ''}
                                                    </td>
                                                    <td
                                                        className="w-6 px-1.5 py-0.5 text-center font-semibold select-none"
                                                        aria-label={
                                                            line.kind === 'added'
                                                                ? 'Added line'
                                                                : line.kind === 'removed'
                                                                  ? 'Removed line'
                                                                  : 'Unchanged context line'
                                                        }
                                                    >
                                                        {marker}
                                                    </td>
                                                    <td className="px-2 py-0.5 whitespace-pre">
                                                        {line.content}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            ) : null}

            <DialogFooter className="flex flex-wrap items-center justify-between gap-2 sm:justify-between">
                <div>
                    {payload?.target.download_href ? (
                        <Button asChild size="sm" variant="outline">
                            <a href={payload.target.download_href}>
                                <Download className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
                                Download snapshot
                            </a>
                        </Button>
                    ) : null}
                </div>
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            </DialogFooter>
        </div>
    );
}

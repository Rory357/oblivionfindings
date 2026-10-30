import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { formatDateTimeLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowUpRight,
    Eye,
    FileClock,
    GitBranch,
    History,
    RotateCcw,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type MouseEvent as ReactMouseEvent,
} from 'react';
import type {
    ConfigurationVersion,
    ReferralSettingsPage,
    SettingsAuditEvent,
} from './settings-types';
import { Nested, NestedRow, SettingGroup, SettingRow } from './settings-ui';

/** Plain status for a version, with its verified badge tone. */
export function versionStatus(
    version: ConfigurationVersion,
    settings: Pick<ReferralSettingsPage, 'latest' | 'effective'>,
): {
    label: string;
    variant: 'success' | 'warning' | 'info' | 'neutral';
} {
    if (version.state === 'discarded')
        return { label: 'Discarded', variant: 'neutral' };
    if (version.state === 'draft')
        return version.base_version_id === (settings.latest?.id ?? null)
            ? { label: 'Waiting for review', variant: 'warning' }
            : { label: 'Out of date', variant: 'neutral' };
    if (version.id === settings.effective?.id)
        return { label: 'In use', variant: 'success' };
    if (version.effective_at && new Date(version.effective_at) > new Date())
        return {
            label: `Starts ${formatDateTimeLong(version.effective_at)}`,
            variant: 'info',
        };
    return { label: 'Earlier version', variant: 'neutral' };
}

function auditText(event: SettingsAuditEvent) {
    const n = event.version ?? '?';
    switch (event.kind) {
        case 'settings.drafted':
            return `Draft version ${n} saved`;
        case 'settings.published':
            return `Version ${n} published`;
        case 'settings.discarded':
            return `Draft version ${n} discarded`;
        case 'settings.restored':
            return `Version ${event.restored_from_version ?? '?'} restored into draft version ${n}`;
        default:
            return `Version ${n} updated`;
    }
}

type Failure = { message: string; uncertain: boolean };
function failure(error: unknown, fallback: string): Failure {
    if (axios.isAxiosError(error) && error.response) {
        const data = error.response.data as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        return {
            message:
                Object.values(data?.errors ?? {})
                    .flat()
                    .join(' ') ||
                data?.message ||
                fallback,
            uncertain: false,
        };
    }
    return {
        message:
            'The result couldn’t be confirmed. Retry sends exactly the same request.',
        uncertain: true,
    };
}

/** Discarding sets an unpublished draft aside. It stays in history with who discarded it and why. */
export function DiscardDraftDialog({
    version,
    onClose,
}: {
    version: ConfigurationVersion;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<Failure | null>(null);
    async function discard() {
        setBusy(true);
        setProblem(null);
        try {
            await axios.post(`/settings/referrals/${version.id}/discard`, {
                reason,
            });
            onClose();
            router.get('/settings/referrals', {}, { preserveState: false });
        } catch (error) {
            setProblem(failure(error, 'The draft couldn’t be discarded.'));
        } finally {
            setBusy(false);
        }
    }
    return (
        <SettingsModal
            title={`Discard draft version ${version.version}?`}
            description="The published settings stay in use. The draft stays in history, marked discarded, with your reason."
            onClose={() => !busy && onClose()}
            footer={
                <>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Keep the draft
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={busy || reason.trim().length < 5}
                        onClick={() => void discard()}
                    >
                        {problem?.uncertain
                            ? 'Retry discarding'
                            : 'Discard draft'}
                    </Button>
                </>
            }
        >
            <Field label="Reason for discarding" required>
                <Textarea
                    value={reason}
                    rows={4}
                    maxLength={2000}
                    disabled={busy}
                    onChange={(event) => setReason(event.target.value)}
                />
            </Field>
            {problem && <SettingsNotice>{problem.message}</SettingsNotice>}
        </SettingsModal>
    );
}

/** Restoring never edits history: it starts a new draft from an earlier publication. */
export function RestoreDialog({
    version,
    onClose,
}: {
    version: ConfigurationVersion;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<Failure | null>(null);
    const request = useRef<{ reason: string; request_key: string } | null>(
        null,
    );
    async function restore() {
        setBusy(true);
        setProblem(null);
        request.current ??= { reason, request_key: crypto.randomUUID() };
        try {
            const response = await axios.post<{
                version: { id: number };
            }>(`/settings/referrals/${version.id}/restore`, request.current);
            onClose();
            router.get(
                '/settings/referrals',
                { version: response.data.version.id },
                { preserveState: false },
            );
        } catch (error) {
            const next = failure(
                error,
                'This version couldn’t be restored as it is.',
            );
            if (!next.uncertain) request.current = null;
            setProblem(next);
        } finally {
            setBusy(false);
        }
    }
    return (
        <SettingsModal
            title={`Restore version ${version.version} into a new draft?`}
            description="Nothing published changes now. Anything added since this version is kept but turned off, and another person still has to publish the draft."
            onClose={() => !busy && onClose()}
            footer={
                <>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={
                            busy ||
                            (!problem?.uncertain && reason.trim().length < 5)
                        }
                        onClick={() => void restore()}
                    >
                        {problem?.uncertain
                            ? 'Retry restoring'
                            : 'Restore into a new draft'}
                    </Button>
                </>
            }
        >
            <Field label="Reason for restoring" required>
                <Textarea
                    value={reason}
                    rows={4}
                    maxLength={2000}
                    disabled={busy || !!problem?.uncertain}
                    onChange={(event) => setReason(event.target.value)}
                />
            </Field>
            {problem && <SettingsNotice>{problem.message}</SettingsNotice>}
        </SettingsModal>
    );
}

type Candidates = {
    can_migrate: boolean;
    total: number;
    target_version: number | null;
    data: {
        reference: string;
        name: string;
        stage: string;
        site_name: string | null;
        owner_name: string | null;
        settings_version: number | null;
    }[];
};

/** Open referrals still on an earlier version. Each one moves on its own, after its own review. */
function MigrationDialog({
    onClose,
    stageLabel,
}: {
    onClose: () => void;
    stageLabel: (id: string) => string;
}) {
    const [data, setData] = useState<Candidates | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        let current = true;
        setData(null);
        setError('');
        axios
            .get<Candidates>('/settings/referrals/migration-candidates')
            .then((response) => current && setData(response.data))
            .catch(
                () =>
                    current &&
                    setError(
                        'Open referrals couldn’t be checked. Check your access and try again.',
                    ),
            );
        return () => {
            current = false;
        };
    }, [attempt]);
    return (
        <SettingsModal
            title="Move open referrals to the current settings"
            description="New checklist steps start incomplete. Completed steps that still match are kept. Funding decisions and evidence never change."
            width={720}
            onClose={onClose}
        >
            {error ? (
                <ErrorState
                    title="Couldn’t load open referrals"
                    message={error}
                    onRetry={() => setAttempt((value) => value + 1)}
                />
            ) : !data ? (
                <LoadingState message="Checking open referrals…" />
            ) : !data.total ? (
                <EmptyState
                    icon={ShieldCheck}
                    title="Every open referral you can see uses the current settings"
                    description="There’s nothing to move."
                />
            ) : !data.can_migrate ? (
                <SettingsNotice role="note">
                    <span>
                        {data.total} open{' '}
                        {data.total === 1 ? 'referral uses' : 'referrals use'}{' '}
                        an earlier version. Moving one needs permission to
                        approve weaker safeguards, plus edit, assessment and
                        funding permissions. Ask a referral manager.
                    </span>
                </SettingsNotice>
            ) : (
                <>
                    <p className="text-subtle">
                        {data.total} open{' '}
                        {data.total === 1 ? 'referral' : 'referrals'} you can
                        see {data.total === 1 ? 'uses' : 'use'} an earlier
                        version
                        {data.data.length < data.total
                            ? ` · showing the oldest ${data.data.length}`
                            : ''}
                        . Open one to review what changes for it and move it
                        with a reason.
                    </p>
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {data.data.map((row) => (
                            <li
                                key={row.reference}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-[13px] font-semibold">
                                        {row.name}
                                    </p>
                                    <p className="text-caption">
                                        {[
                                            stageLabel(row.stage),
                                            row.site_name,
                                            row.owner_name
                                                ? `Owner ${row.owner_name}`
                                                : 'No owner yet',
                                            `Version ${row.settings_version ?? '?'} to ${data.target_version}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <Button variant="link" asChild>
                                    <Link
                                        href={`/operations/referrals/${row.reference}?tab=configuration`}
                                    >
                                        Review the move
                                        <ArrowUpRight className="size-4" />
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </SettingsModal>
    );
}

/** History: every saved version (with the same menu on the kebab and right-click), and the audit trail. */
export function HistorySettings({
    settings,
    onDiscard,
    onRestore,
}: {
    settings: ReferralSettingsPage;
    onDiscard: (version: ConfigurationVersion) => void;
    onRestore: (version: ConfigurationVersion) => void;
}) {
    const [detail, setDetail] = useState<ConfigurationVersion | null>(null);
    const [migrating, setMigrating] = useState(false);
    const context = useEntityContextMenu<ConfigurationVersion>();
    const canRestore = (version: ConfigurationVersion) =>
        settings.can_manage &&
        version.state === 'published' &&
        version.id !== settings.latest?.id;
    const canDiscard = (version: ConfigurationVersion) =>
        version.state === 'draft' &&
        (settings.can_manage || settings.can_publish);
    const open = (version: ConfigurationVersion) =>
        router.get(
            '/settings/referrals',
            { version: version.id },
            { preserveState: false },
        );
    const actions = (version: ConfigurationVersion): MenuItem[] => [
        {
            label: 'Review details',
            icon: Eye,
            onClick: () => setDetail(version),
        },
        {
            label: 'Open this version',
            icon: FileClock,
            onClick: () => open(version),
        },
        ...(canRestore(version)
            ? [
                  {
                      label: 'Restore into a new draft',
                      icon: RotateCcw,
                      onClick: () => onRestore(version),
                  },
              ]
            : []),
        ...(canDiscard(version)
            ? [
                  { separator: true },
                  {
                      label: 'Discard draft',
                      icon: Trash2,
                      danger: true,
                      onClick: () => onDiscard(version),
                  },
              ]
            : []),
    ];
    const stageLabel = (id: string) =>
        settings.configuration.stages.find((stage) => stage.id === id)?.label ??
        id.replaceAll('_', ' ');
    const audit = settings.audit ?? [];
    return (
        <div className="space-y-5">
            <ListCaption
                title="Published configuration versions"
                caption={`${settings.history.total} saved ${settings.history.total === 1 ? 'version' : 'versions'} · history is never edited`}
                right={
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setMigrating(true)}
                    >
                        <GitBranch className="size-4" />
                        Review open-referral moves
                    </Button>
                }
            />
            {settings.history.total ? (
                <VersionPathway
                    versions={settings.history.data}
                    settings={settings}
                    actions={actions}
                    onOpen={setDetail}
                    onContextMenu={context.open}
                />
            ) : (
                <EmptyState
                    icon={History}
                    title="No versions saved yet"
                    description="The first draft appears here once it’s saved."
                />
            )}
            <LaravelPagination
                links={settings.history.links}
                lastPage={settings.history.last_page}
                preserveState={false}
            />
            <SettingGroup
                icon={ShieldCheck}
                title="Configuration audit"
                caption="Who saved, published, discarded or restored each version."
            >
                {audit.length ? (
                    audit.map((event) => (
                        <SettingRow
                            key={event.id}
                            label={auditText(event)}
                            hint={[
                                event.actor_name ?? 'A colleague',
                                event.occurred_at
                                    ? formatDateTimeLong(event.occurred_at)
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        />
                    ))
                ) : (
                    <SettingRow
                        label="Nothing recorded yet"
                        hint="Drafts and publications are recorded here."
                    />
                )}
            </SettingGroup>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={History}
                    title={`Version ${context.ctx.record.version}`}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {detail && (
                <SettingsModal
                    title={`Version ${detail.version}`}
                    description={versionStatus(detail, settings).label}
                    width={720}
                    onClose={() => setDetail(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDetail(null)}
                            >
                                Close
                            </Button>
                            {canDiscard(detail) && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setDetail(null);
                                        onDiscard(detail);
                                    }}
                                >
                                    Discard draft
                                </Button>
                            )}
                            {canRestore(detail) && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setDetail(null);
                                        onRestore(detail);
                                    }}
                                >
                                    Restore into a new draft
                                </Button>
                            )}
                            <Button onClick={() => open(detail)}>
                                Open this version
                            </Button>
                        </>
                    }
                >
                    <Nested icon={History} title="What happened">
                        <NestedRow
                            label="Saved"
                            value={`${detail.created_by_name ?? 'A colleague'} · ${formatDateTimeLong(detail.created_at)}`}
                        />
                        <NestedRow label="Reason" value={detail.reason} />
                        {detail.restored_from_version && (
                            <NestedRow
                                label="Restored from"
                                value={`Version ${detail.restored_from_version}`}
                            />
                        )}
                        {detail.base_version && (
                            <NestedRow
                                label="Built on"
                                value={`Version ${detail.base_version}`}
                            />
                        )}
                        {detail.published_at && (
                            <NestedRow
                                label="Published"
                                value={`${detail.published_by_name ?? 'A colleague'} · ${formatDateTimeLong(detail.published_at)}`}
                            />
                        )}
                        {detail.effective_at && (
                            <NestedRow
                                label="Starts"
                                value={formatDateTimeLong(detail.effective_at)}
                            />
                        )}
                        {detail.publication_note && (
                            <NestedRow
                                label="Publication note"
                                value={detail.publication_note}
                            />
                        )}
                        {detail.discarded_at && (
                            <NestedRow
                                label="Discarded"
                                value={`${detail.discarded_by_name ?? 'A colleague'} · ${formatDateTimeLong(detail.discarded_at)} · ${detail.discard_reason ?? ''}`}
                            />
                        )}
                    </Nested>
                    <p className="text-caption">
                        Open this version to see every setting it contains,
                        read-only.
                    </p>
                </SettingsModal>
            )}
            {migrating && (
                <MigrationDialog
                    onClose={() => setMigrating(false)}
                    stageLabel={stageLabel}
                />
            )}
        </div>
    );
}

/**
 * The version history as a pathway (the Journey line): the version in use is
 * the ring, earlier publications are filled, drafts and scheduled versions
 * are hollow, and discarded drafts are muted. Every entry keeps the kebab,
 * right-click and click-to-open of a list row.
 */
function VersionPathway({
    versions,
    settings,
    actions,
    onOpen,
    onContextMenu,
}: {
    versions: ConfigurationVersion[];
    settings: ReferralSettingsPage;
    actions: (version: ConfigurationVersion) => MenuItem[];
    onOpen: (version: ConfigurationVersion) => void;
    onContextMenu: (
        event: ReactMouseEvent,
        version: ConfigurationVersion,
    ) => void;
}) {
    return (
        <Card className="p-5">
            <ol aria-label="Settings versions, newest first">
                {versions.map((version, index) => {
                    const status = versionStatus(version, settings);
                    const current = version.id === settings.effective?.id;
                    const done =
                        version.state === 'published' &&
                        !current &&
                        status.variant !== 'info';
                    const discarded = version.state === 'discarded';
                    return (
                        <li
                            key={version.id}
                            className="relative flex gap-4 pb-5 last:pb-0"
                            onContextMenu={(event) =>
                                onContextMenu(event, version)
                            }
                        >
                            {index < versions.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className="absolute top-6 bottom-0 left-[11px] w-0.5 rounded-full bg-border"
                                />
                            )}
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'relative mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full',
                                    current
                                        ? 'border-2 border-primary bg-card'
                                        : done
                                          ? 'bg-primary-fill'
                                          : discarded
                                            ? 'border border-border bg-muted'
                                            : 'border-2 border-muted-foreground bg-card',
                                )}
                            >
                                {current && (
                                    <span className="size-2 rounded-full bg-primary-fill" />
                                )}
                            </span>
                            {/* eslint-disable-next-line no-restricted-syntax -- the whole timeline entry is the open target (list-row click), not a styled button */}
                            <button
                                type="button"
                                onClick={() => onOpen(version)}
                                className="min-w-0 flex-1 rounded-lg px-2 py-1 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="flex flex-wrap items-center gap-2">
                                    <span
                                        className={cn(
                                            'text-sm font-semibold',
                                            discarded &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        Version {version.version}
                                    </span>
                                    <StatusBadge
                                        variant={status.variant}
                                        size="sm"
                                    >
                                        {status.label}
                                    </StatusBadge>
                                    {version.restored_from_version && (
                                        <span className="text-caption">
                                            Restored from version{' '}
                                            {version.restored_from_version}
                                        </span>
                                    )}
                                </span>
                                <span className="text-subtle mt-1 block break-words">
                                    {version.reason}
                                </span>
                                <span className="text-caption mt-0.5 block">
                                    {[
                                        `Saved by ${version.created_by_name ?? 'a colleague'}, ${formatDateTimeLong(version.created_at)}`,
                                        version.published_at
                                            ? `published by ${version.published_by_name ?? 'a colleague'}, ${formatDateTimeLong(version.published_at)}`
                                            : discarded
                                              ? `discarded by ${version.discarded_by_name ?? 'a colleague'}`
                                              : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </button>
                            <EntityKebab
                                actions={actions(version)}
                                label={`Actions for version ${version.version}`}
                            />
                        </li>
                    );
                })}
            </ol>
        </Card>
    );
}

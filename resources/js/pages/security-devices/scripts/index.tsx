import { ItModuleShell } from '@/components/it/it-module-shell';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileCode, Monitor, Plus, Search, ShieldCheck } from 'lucide-react';
import { useCallback, useState, type MouseEvent } from 'react';
import {
    scriptBase,
    scriptIndex,
    scriptStatuses,
    scriptStatusLabel,
    type ScriptIndex,
    type ScriptRecord,
    type ScriptStatus,
} from './_contracts';
import { ScriptReviewDialog } from './_dialogs';
import { ScriptUploadDialog } from './_upload';

const variants: Record<ScriptStatus, StatusVariant> = {
    uploaded: 'neutral',
    pending_review: 'info',
    published: 'success',
    withdrawn: 'warning',
};
const title = 'Approved scripts';
// The catalogue is reached from Remote support, so the trail goes back there.
const breadcrumbs = [
    { title: 'Home', href: '/dashboard' },
    { title: 'IT & Support', href: '/it' },
    { title: 'Remote support', href: '/it/remote-support' },
    { title, href: scriptBase },
];
function status(row: ScriptRecord) {
    return (
        <StatusBadge variant={variants[row.status]}>
            {scriptStatusLabel[row.status]}
        </StatusBadge>
    );
}

export default function ScriptCatalogue(props: ScriptIndex) {
    const signedInActor = usePage<{
        auth?: { user?: { id?: unknown } | null } | null;
    }>().props.auth?.user?.id;
    // Shared auth can change independently of retained page props during a
    // partial visit. Unmount the entire private workspace before using them.
    if (
        typeof signedInActor !== 'number' ||
        !Number.isSafeInteger(signedInActor) ||
        signedInActor <= 0 ||
        signedInActor !== props.actor_id
    ) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={title} />
                <Alert>
                    <AlertDescription>
                        Sign-in has changed. Load the current catalogue before
                        continuing.{' '}
                        <Button
                            variant="outline"
                            onClick={() => router.visit(scriptBase)}
                        >
                            Load current catalogue
                        </Button>
                    </AlertDescription>
                </Alert>
            </AppLayout>
        );
    }
    let index: ScriptIndex;
    try {
        // Shared Inertia props belong to the app shell, not the API document.
        index = scriptIndex({
            actor_id: props.actor_id,
            can_upload: props.can_upload,
            identity_confirmed: props.identity_confirmed,
            identity_confirm_href: props.identity_confirm_href,
            execution_available: props.execution_available,
            summary: props.summary,
            query: props.query,
            pagination: props.pagination,
            scripts: props.scripts,
        });
    } catch {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={title} />
                <Alert>
                    <AlertDescription>
                        Script information could not be verified.{' '}
                        <Button
                            variant="outline"
                            onClick={() => router.reload()}
                        >
                            Reload catalogue
                        </Button>
                    </AlertDescription>
                </Alert>
            </AppLayout>
        );
    }
    const key = JSON.stringify([
        index.actor_id,
        index.can_upload,
        index.identity_confirmed,
        index.query,
        index.pagination,
        index.scripts.map((x) => [
            x.uuid,
            x.status_version,
            x.can_submit,
            x.can_review,
            x.can_withdraw,
            x.can_read_material,
            x.publication_issue,
        ]),
    ]);
    return <ScriptWorkspace key={key} index={index} />;
}
export function ScriptWorkspace({ index }: { index: ScriptIndex }) {
    const [rows, setRows] = useState(index.scripts),
        [search, setSearch] = useState(index.query.search);
    // Header counts follow the latest props (a Refresh that leaves the visible
    // rows unchanged keeps this workspace mounted) plus decisions made here.
    const [counts, setCounts] = useState({
        source: index.summary,
        value: index.summary,
    });
    if (counts.source !== index.summary)
        setCounts({ source: index.summary, value: index.summary });
    const summary =
        counts.source === index.summary ? counts.value : index.summary;
    const [selected, setSelected] = useState<ScriptRecord | null>(null),
        [open, setOpen] = useState(false),
        [guarded, setGuarded] = useState(false);
    // Each upload gets its own dialog instance (and so its own request key);
    // a saved one is never reopened by "Add script".
    const [upload, setUpload] = useState<{
            key: number;
            open: boolean;
            saved: boolean;
        } | null>(null),
        [uploadSaved, setUploadSaved] = useState(false);
    const [context, setContext] = useState<{
        x: number;
        y: number;
        row: ScriptRecord;
    } | null>(null);
    const leave = useSettingsLeaveConfirmation(
        guarded,
        'Leave this script draft or review?',
        'Unsaved files and notes will be lost. A change already sent may still finish. Check its saved state before creating another request.',
        () => setGuarded(false),
    );
    const guard = useCallback((value: boolean) => setGuarded(value), []);
    function show(row: ScriptRecord) {
        setContext(null);
        if (selected?.uuid === row.uuid) {
            setOpen(true);
            return;
        }
        leave.request(() => {
            setUpload(null);
            setSelected(row);
            setOpen(true);
            setGuarded(false);
        });
    }
    function startUpload() {
        leave.request(() => {
            setSelected(null);
            setOpen(false);
            setGuarded(false);
            setUploadSaved(false);
            setUpload((previous) => ({
                key: (previous?.key ?? 0) + 1,
                open: true,
                saved: false,
            }));
        });
    }
    function addScript() {
        if (!index.can_upload) return;
        // An unfinished draft reopens as it was left; a saved one is done.
        if (upload && !upload.saved) {
            setUpload({ ...upload, open: true });
            return;
        }
        startUpload();
    }
    function refresh() {
        router.reload({ onSuccess: () => setUploadSaved(false) });
    }
    function changed(row: ScriptRecord) {
        const before = rows.find((x) => x.uuid === row.uuid);
        if (before && before.status !== row.status) {
            setCounts((current) => ({
                ...current,
                value: {
                    ...current.value,
                    [before.status]: Math.max(
                        0,
                        current.value[before.status] - 1,
                    ),
                    [row.status]: current.value[row.status] + 1,
                },
            }));
        }
        setRows((values) => values.map((x) => (x.uuid === row.uuid ? row : x)));
        setSelected(row);
    }
    const menu = (row: ScriptRecord): MenuItem[] => [
        {
            label: 'Open script review',
            icon: FileCode,
            onClick: () => show(row),
        },
    ];
    function contextMenu(event: MouseEvent, row: ScriptRecord) {
        event.preventDefault();
        setContext({ x: event.clientX, y: event.clientY, row });
    }
    function href(filter: string, term = search) {
        return (
            scriptBase +
            '?' +
            new URLSearchParams({
                expected_actor_id: String(index.actor_id),
                status: filter,
                search: term,
            })
        );
    }
    const shown = rows.filter(
        (row) =>
            index.query.status === 'all' || row.status === index.query.status,
    );
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <ItModuleShell>
                <PageHeader
                    title={title}
                    icon={FileCode}
                    subline="Reviewed Windows scripts and their approval history"
                    actions={
                        <>
                            {index.can_upload && (
                                <PageHeaderPrimaryButton onClick={addScript}>
                                    <Plus aria-hidden="true" />
                                    Add script
                                </PageHeaderPrimaryButton>
                            )}
                            <PageHeaderGlassButton asChild>
                                <Link href="/it/remote-support">
                                    <Monitor aria-hidden="true" />
                                    Computer support
                                </Link>
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={
                        <>
                            {scriptStatuses.map((s) => (
                                <PageHeaderMeterBlock
                                    key={s}
                                    label={scriptStatusLabel[s]}
                                    href={href(s, '')}
                                >
                                    <PageHeaderMeterBig>
                                        {summary[s]}
                                    </PageHeaderMeterBig>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                router.visit(href(index.query.status));
                            }}
                        >
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Script name…"
                            />
                            <PageHeaderGlassButton type="submit">
                                <Search aria-hidden="true" />
                                Search
                            </PageHeaderGlassButton>
                        </form>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                { key: 'all', label: 'All scripts' },
                                ...scriptStatuses.map((s) => ({
                                    key: s,
                                    label: scriptStatusLabel[s],
                                })),
                            ]}
                            value={index.query.status}
                            onSelect={(s) => router.visit(href(s))}
                            ariaLabel="Script status"
                        />
                    }
                />
                <div className="grid min-w-0 gap-5 pt-5">
                    <Alert>
                        <AlertDescription>
                            Scripts can’t run on computers yet. Approving a
                            script records the reviewed version; it does not run
                            it.
                        </AlertDescription>
                    </Alert>
                    {uploadSaved && (
                        <Alert role="status">
                            <AlertDescription>
                                Script draft saved. Refresh the catalogue to
                                update the list and counts.
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" onClick={refresh}>
                                        Refresh catalogue
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => setUploadSaved(false)}
                                    >
                                        Dismiss
                                    </Button>
                                </div>
                            </AlertDescription>
                        </Alert>
                    )}
                    {!index.identity_confirmed && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-subtle">
                                Confirm your identity to read script code or
                                make review decisions.
                            </p>
                            <Button variant="outline" asChild>
                                <a href={index.identity_confirm_href}>
                                    <ShieldCheck aria-hidden="true" />
                                    Confirm identity
                                </a>
                            </Button>
                        </div>
                    )}
                    {index.pagination.position_reset && (
                        <Alert>
                            <AlertDescription>
                                The previous page is no longer available.
                                Showing the first matching records.
                            </AlertDescription>
                        </Alert>
                    )}
                    <ListCaption
                        title={
                            index.query.status === 'all'
                                ? 'All scripts'
                                : scriptStatusLabel[index.query.status]
                        }
                        caption={`${shown.length} shown`}
                        right={
                            <Button variant="outline" onClick={refresh}>
                                Refresh
                            </Button>
                        }
                    />
                    {shown.length === 0 ? (
                        <EmptyState
                            icon={FileCode}
                            title="No matching scripts"
                            description="Uploaded scripts that you can review appear here. Try another status or search."
                        />
                    ) : (
                        <>
                            <div className="md:hidden">
                                <EntityCardGrid>
                                    {shown.map((row) => (
                                        <EntityCard
                                            key={row.uuid}
                                            meridian="neutral"
                                            icon={FileCode}
                                            name={row.manifest.name}
                                            subline={
                                                row.manifest.publisher_label
                                            }
                                            actions={menu(row)}
                                            onOpen={() => show(row)}
                                            onContextMenu={(event) =>
                                                contextMenu(event, row)
                                            }
                                            chips={
                                                <div className="flex flex-wrap gap-2">
                                                    {status(row)}
                                                    <span className="text-caption">
                                                        PowerShell{' '}
                                                        {
                                                            row.manifest
                                                                .interpreter_version
                                                        }{' '}
                                                        ·{' '}
                                                        {row.manifest
                                                            .architecture ===
                                                        'amd64'
                                                            ? 'x64'
                                                            : 'ARM64'}
                                                    </span>
                                                </div>
                                            }
                                            footer={{
                                                primary: `Version ${row.script_version}`,
                                                secondary:
                                                    row.uploaded_by_user_id ===
                                                    index.actor_id
                                                        ? 'Your upload'
                                                        : 'Independent review',
                                            }}
                                            openLabel="Review details"
                                        />
                                    ))}
                                </EntityCardGrid>
                            </div>
                            <div className="hidden min-w-0 md:block">
                                <EntityTable
                                    rows={shown}
                                    rowKey={(row) => row.uuid}
                                    identityLabel="Script"
                                    identity={(row) => ({
                                        icon: FileCode,
                                        name: row.manifest.name,
                                        subline: row.manifest.publisher_label,
                                    })}
                                    wrapIdentity
                                    rowHeight="content"
                                    minWidth={760}
                                    actionsFor={menu}
                                    onOpen={show}
                                    onRowContextMenu={contextMenu}
                                    columns={[
                                        {
                                            key: 'status',
                                            label: 'Status',
                                            width: '150px',
                                            cell: status,
                                        },
                                        {
                                            key: 'runtime',
                                            label: 'Runtime',
                                            width: '160px',
                                            cell: (row) => (
                                                <span className="text-subtle">
                                                    PowerShell{' '}
                                                    {
                                                        row.manifest
                                                            .interpreter_version
                                                    }{' '}
                                                    ·{' '}
                                                    {row.manifest
                                                        .architecture ===
                                                    'amd64'
                                                        ? 'x64'
                                                        : 'ARM64'}
                                                </span>
                                            ),
                                        },
                                        {
                                            key: 'version',
                                            label: 'Version',
                                            width: '80px',
                                            cell: (row) => row.script_version,
                                        },
                                    ]}
                                />
                            </div>
                        </>
                    )}
                    {(index.pagination.previous_href ||
                        index.pagination.next_href) && (
                        <LaravelPagination
                            preserveState={false}
                            links={[
                                {
                                    url: index.pagination.previous_href,
                                    label: 'Previous',
                                    active: false,
                                },
                                {
                                    url: index.pagination.previous_href
                                        ? index.pagination.reset_href
                                        : null,
                                    label: 'First page',
                                    active: false,
                                },
                                { url: null, label: 'Current', active: true },
                                {
                                    url: index.pagination.next_href,
                                    label: 'Next',
                                    active: false,
                                },
                            ]}
                        />
                    )}
                </div>
                {upload && index.can_upload && (
                    <ScriptUploadDialog
                        key={upload.key}
                        actorId={index.actor_id}
                        open={upload.open}
                        onClose={() =>
                            setUpload(
                                (current) =>
                                    current && { ...current, open: false },
                            )
                        }
                        onDirtyChange={guard}
                        onUploaded={() => {
                            setUpload(
                                (current) =>
                                    current && { ...current, saved: true },
                            );
                            setUploadSaved(true);
                        }}
                        onAddAnother={startUpload}
                    />
                )}
                {context && (
                    <EntityContextMenu
                        x={context.x}
                        y={context.y}
                        icon={FileCode}
                        title={context.row.manifest.name}
                        items={menu(context.row)}
                        onClose={() => setContext(null)}
                    />
                )}
                {selected && (
                    <ScriptReviewDialog
                        key={index.actor_id + ':' + selected.uuid}
                        actorId={index.actor_id}
                        item={selected}
                        open={open}
                        onClose={() => setOpen(false)}
                        onChanged={changed}
                        onPendingChange={guard}
                    />
                )}
                {leave.confirmation}
            </ItModuleShell>
        </AppLayout>
    );
}

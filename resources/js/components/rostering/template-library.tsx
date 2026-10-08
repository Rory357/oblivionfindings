import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    LayoutTemplate,
    ListChecks,
    Plus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TemplateCommandNotice } from './template-command-notice';
import { TemplateDetailDialog, TemplateWizardDialog } from './template-dialogs';
import {
    TemplatesPane,
    type RosterTemplateRow,
    type TemplateCapabilities,
} from './templates-pane';
import {
    currentTemplateLibrary,
    useTemplateCommand,
} from './use-template-command';

const NO_CAPABILITIES: TemplateCapabilities = {
    can_view: false,
    can_create: false,
    can_edit: false,
    can_duplicate: false,
    can_delete: false,
    can_apply: false,
};
export function TemplateLibrary({
    standalone = false,
    loading = false,
}: {
    standalone?: boolean;
    loading?: boolean;
}) {
    const page = usePage();
    const actorId = Number(
        (page.props.auth as { user?: { id?: number } } | undefined)?.user?.id ??
            0,
    );
    return (
        <LibraryBody
            key={actorId}
            actorId={actorId}
            page={page}
            week={standalone ? page.props.week : page.props.weekStart}
            standalone={standalone}
            loading={loading}
        />
    );
}
function LibraryBody({
    actorId,
    page,
    week,
    standalone,
    loading,
}: {
    actorId: number;
    page: unknown;
    week: unknown;
    standalone: boolean;
    loading: boolean;
}) {
    const library = currentTemplateLibrary(page, actorId, true);
    const command = useTemplateCommand(
        actorId,
        library,
        typeof week === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(week)
            ? week
            : undefined,
    );
    const initialRead = useRef(false);
    useEffect(() => {
        if (initialRead.current) return;
        initialRead.current = true;
        if (!loading && !library) command.refresh();
    }, [command, library, loading]);
    const [editor, setEditor] = useState<{
        template: RosterTemplateRow | null;
        data: NonNullable<ReturnType<typeof currentTemplateLibrary>>;
    } | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<RosterTemplateRow | null>(null);
    const [deleteAttempted, setDeleteAttempted] = useState(false);
    const [query, setQuery] = useState(''),
        [view, setView] = useState<'all' | 'active'>('all');
    const list = library?.rosterTemplates ?? null,
        capabilities = library?.templateCapabilities ?? NO_CAPABILITIES;
    const detail = list?.find((row) => row.id === detailId) ?? null;
    const blocked = command.blocked || loading;
    const edit = (template: RosterTemplateRow) => {
        if (blocked) return;
        setDetailId(null);
        if (library) setEditor({ template, data: library });
    };
    const requestDelete = (template: RosterTemplateRow) => {
        if (blocked) return;
        setDetailId(null);
        setDeleteAttempted(false);
        setDeleting(template);
    };
    const deleteCurrent = list?.find((row) => row.id === deleting?.id);
    const deleteSourceCurrent =
        deleteCurrent?.source_revision === deleting?.source_revision;
    const templates = list ?? [];
    const filter = (next: 'all' | 'active') => {
        setView(next);
        setQuery('');
    };
    return (
        <div className="space-y-4">
            {standalone ? (
                <WorkforcePageHeader
                    icon={LayoutTemplate}
                    title="Roster templates"
                    subline={
                        library
                            ? 'Reusable support patterns · ' +
                              library.workerTimezone
                            : 'Reusable support patterns'
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search templates…"
                            />
                            {capabilities.can_create ? (
                                <PageHeaderPrimaryButton
                                    disabled={blocked}
                                    onClick={() =>
                                        library &&
                                        setEditor({
                                            template: null,
                                            data: library,
                                        })
                                    }
                                >
                                    <Plus className="size-4" /> New template
                                </PageHeaderPrimaryButton>
                            ) : null}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Templates"
                                onClick={() => filter('all')}
                            >
                                <PageHeaderMeterBig>
                                    {templates.length}
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Active patterns"
                                onClick={() => filter('active')}
                            >
                                <PageHeaderMeterBig>
                                    {
                                        templates.filter((row) => row.is_active)
                                            .length
                                    }
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Saved shift rows"
                                onClick={() => filter('all')}
                            >
                                <PageHeaderMeterBig>
                                    {templates.reduce(
                                        (count, row) =>
                                            count + row.template_shifts_count,
                                        0,
                                    )}
                                </PageHeaderMeterBig>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'all',
                                    label: 'All templates',
                                    icon: ListChecks,
                                },
                                {
                                    key: 'active',
                                    label: 'Active',
                                    icon: CheckCircle2,
                                },
                            ]}
                            value={view}
                            onSelect={setView}
                            ariaLabel="Template views"
                        />
                    }
                />
            ) : null}
            {!editor && !deleting ? (
                <TemplateCommandNotice command={command} />
            ) : null}
            <TemplatesPane
                templates={list}
                loading={loading || (!list && command.busy)}
                capabilities={capabilities}
                actionsBlocked={blocked}
                showControls={!standalone}
                query={standalone ? query : undefined}
                activeFilter={standalone ? view === 'active' : undefined}
                onRetry={() => command.refresh()}
                onCreate={() =>
                    !blocked &&
                    library &&
                    setEditor({ template: null, data: library })
                }
                onView={(row) => setDetailId(row.id)}
                onEdit={edit}
                onDelete={requestDelete}
                onDuplicate={(row) => {
                    if (!blocked)
                        void command.submit({
                            action: 'duplicate',
                            source: {
                                template_id: row.id,
                                source_revision: row.source_revision ?? '',
                            },
                            values: null,
                            rowCount: row.template_shifts_count,
                        });
                }}
            />
            {editor ? (
                <TemplateWizardDialog
                    open
                    command={command}
                    onOpenChange={(open) =>
                        !open && !command.isBusy() && setEditor(null)
                    }
                    template={editor.template}
                    clients={(library ?? editor.data).templateOptions.clients}
                    staff={(library ?? editor.data).templateOptions.staff}
                    serviceContexts={
                        (library ?? editor.data).templateOptions.serviceContexts
                    }
                    workerTimezone={(library ?? editor.data).workerTimezone}
                />
            ) : null}
            <TemplateDetailDialog
                open={detail !== null}
                onOpenChange={(open) => !open && setDetailId(null)}
                template={detail}
                canManage={Boolean(
                    capabilities.can_edit &&
                    detail?.capabilities?.can_edit &&
                    detail.urls?.update,
                )}
                canDelete={Boolean(
                    capabilities.can_delete &&
                    detail?.capabilities?.can_delete &&
                    detail.urls?.delete,
                )}
                canApply={Boolean(
                    capabilities.can_apply &&
                    detail?.capabilities?.can_apply &&
                    detail.urls?.apply,
                )}
                actionsBlocked={blocked}
                onEdit={edit}
                onDelete={requestDelete}
            />
            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open && !command.isBusy()) setDeleting(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete “{deleting?.name}”?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This removes the pattern from the template library.
                            Its {deleting?.template_shifts_count ?? 0} saved
                            rows, existing roster shifts and history are
                            retained.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {deleteAttempted ? (
                        <TemplateCommandNotice command={command} />
                    ) : null}
                    {!deleteSourceCurrent && !command.busy ? (
                        <Alert>
                            <AlertTriangle className="size-4" />
                            <AlertDescription>
                                The saved template has changed or is no longer
                                available. Close this confirmation and review
                                the current library.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <AlertDialogFooter>
                        <Button
                            variant="outline"
                            disabled={command.busy}
                            onClick={() => setDeleting(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={blocked || !deleteSourceCurrent}
                            onClick={() => {
                                if (
                                    !deleting ||
                                    blocked ||
                                    !deleteSourceCurrent
                                )
                                    return;
                                setDeleteAttempted(true);
                                void command.submit(
                                    {
                                        action: 'delete',
                                        source: {
                                            template_id: deleting.id,
                                            source_revision:
                                                deleting.source_revision ?? '',
                                        },
                                        values: null,
                                        rowCount:
                                            deleting.template_shifts_count,
                                    },
                                    () => setDeleting(null),
                                );
                            }}
                        >
                            {command.busy ? 'Checking…' : 'Delete template'}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

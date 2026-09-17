import { ProvisioningCommandDialog } from '@/components/it/provisioning-command-dialog';
import {
    ProvisioningFacts,
    ProvisioningProfileHeader,
    ProvisioningSection,
    provisioningLabel,
} from '@/components/it/provisioning-workspace';
import { PageHeaderGlassButton } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';

interface TemplateVersion {
    id: number;
    version: number;
    recorded_at: string | null;
    provenance: string;
    contract: {
        name: string;
        description: string | null;
        lifecycle_type: string;
        position_role: string | null;
        employment_type: string | null;
        tasks: {
            task_key: string;
            title: string;
            description: string | null;
            stage: number;
            due_offset_days: number;
            dependency_task_keys: string[];
            approval_required: boolean;
            evidence_required: boolean;
        }[];
    };
}
interface Template {
    id: number;
    name: string;
    version: number;
    current_version_id: number | null;
    published_version_id: number | null;
    published_at: string | null;
    lifecycle_type: string;
    versions: TemplateVersion[];
}
export default function ProvisioningTemplatePage({
    actorId,
    template,
}: {
    actorId: number;
    template: Template;
}) {
    const currentActorId = usePage<SharedData>().props.auth.user.id;
    const [visible, setVisible] = useState(true);
    const [tab, setTab] = useState('details');
    const [search, setSearch] = useState('');
    const [selectedId, setSelectedId] = useState(template.current_version_id);
    const [operation, setOperation] = useState<'publish' | 'unpublish' | null>(
        null,
    );
    const selected =
        template.versions.find((version) => version.id === selectedId) ??
        template.versions[0];
    const published = template.versions.find(
        (version) => version.id === template.published_version_id,
    );
    const current = template.versions.find(
        (version) => version.id === template.current_version_id,
    );
    if (!visible || actorId !== currentActorId)
        return (
            <AppLayout>
                <p role="alert" className="p-6">
                    This template is hidden because your account or access
                    changed.
                </p>
                <Button
                    onClick={() =>
                        router.visit('/it/provisioning?view=templates')
                    }
                >
                    Open with current access
                </Button>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'IT & Support', href: '/it' },
                {
                    title: 'Provisioning templates',
                    href: '/it/provisioning?view=templates',
                },
                {
                    title: template.name,
                    href: '/it/provisioning/templates/' + template.id,
                },
            ]}
        >
            <Head title={template.name} />
            <ProvisioningProfileHeader
                template
                title={template.name}
                status={published ? 'published' : 'draft'}
                subline={
                    provisioningLabel(template.lifecycle_type) +
                    ' · Template publication'
                }
                tab={tab}
                onTab={setTab}
                search={search}
                onSearch={setSearch}
                actions={
                    <PageHeaderGlassButton
                        icon={Pencil}
                        onClick={() =>
                            router.visit('/it/setup?tab=provisioning')
                        }
                    >
                        Author templates
                    </PageHeaderGlassButton>
                }
                meters={[
                    {
                        label: 'Saved draft',
                        value: template.version,
                        caption: 'Review before publication',
                        tab: 'details',
                    },
                    {
                        label: 'Published version',
                        value: published?.version ?? 'None',
                        caption: 'Used by new workflows',
                        tab: 'work',
                    },
                    {
                        label: 'Original versions',
                        value: template.versions.length,
                        caption: 'Retained instructions',
                        tab: 'history',
                    },
                    {
                        label: 'Tasks in preview',
                        value: selected?.contract.tasks.length ?? 0,
                        caption: 'Selected version',
                        tab: 'work',
                    },
                ]}
            />
            <main
                id="provisioning-panel"
                role="tabpanel"
                aria-labelledby={'provisioning-tab-' + tab}
                className="space-y-5 py-5"
            >
                <ProvisioningSection title="Publication">
                    <p className="text-sm">
                        {published
                            ? 'New workflows use published version ' +
                              published.version +
                              '. Author edits remain separate until published.'
                            : 'This template is not published. It cannot start new work.'}{' '}
                        Existing workflows retain their original instructions.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={!current || current.id === published?.id}
                            onClick={() => {
                                setSelectedId(template.current_version_id);
                                setTab('work');
                                setOperation('publish');
                            }}
                        >
                            {current?.id === published?.id
                                ? 'Saved version is published'
                                : 'Review and publish saved draft'}
                        </Button>
                        {published && (
                            <Button
                                variant="outline"
                                onClick={() => setOperation('unpublish')}
                            >
                                Withdraw template
                            </Button>
                        )}
                    </div>
                </ProvisioningSection>
                {tab === 'history' ? (
                    <ProvisioningSection title="Version history">
                        {template.versions.map((version) => (
                            <div
                                key={version.id}
                                className="flex flex-wrap items-center justify-between gap-3 border-b py-3 last:border-0"
                            >
                                <div>
                                    <p className="font-medium">
                                        Version {version.version}
                                        {version.id ===
                                        template.published_version_id
                                            ? ' · Published'
                                            : ''}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {formatDateTime(version.recorded_at)} ·{' '}
                                        {version.provenance === 'legacy_current'
                                            ? 'Imported original instructions; no human review recorded'
                                            : 'Saved author version'}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSelectedId(version.id);
                                        setTab('work');
                                    }}
                                >
                                    Preview version {version.version}
                                </Button>
                            </div>
                        ))}
                    </ProvisioningSection>
                ) : tab === 'details' ? (
                    <ProvisioningSection title="Saved version details">
                        <ProvisioningFacts
                            search={search}
                            facts={[
                                {
                                    label: 'Lifecycle',
                                    value: provisioningLabel(
                                        template.lifecycle_type,
                                    ),
                                },
                                {
                                    label: 'Publication time',
                                    value: formatDateTime(
                                        template.published_at,
                                    ),
                                },
                                {
                                    label: 'Description',
                                    value: current?.contract.description,
                                },
                                {
                                    label: 'Role match',
                                    value:
                                        current?.contract.position_role ??
                                        'Any role',
                                },
                                {
                                    label: 'Employment match',
                                    value: current?.contract.employment_type
                                        ? provisioningLabel(
                                              current.contract.employment_type,
                                          )
                                        : 'Any employment type',
                                },
                            ]}
                        />
                        <Button asChild variant="outline">
                            <Link href="/it/setup?tab=provisioning">
                                Edit the saved draft in template authoring
                            </Link>
                        </Button>
                    </ProvisioningSection>
                ) : (
                    <ProvisioningSection
                        title={
                            'Version ' +
                            (selected?.version ?? 'unavailable') +
                            ' preview'
                        }
                    >
                        {selected?.contract.tasks
                            .filter((task) =>
                                (task.title + ' ' + task.description)
                                    .toLocaleLowerCase()
                                    .includes(search.toLocaleLowerCase()),
                            )
                            .map((task) => (
                                <div
                                    key={task.task_key}
                                    className="space-y-2 border-b pb-4 last:border-0"
                                >
                                    <h3 className="text-sm font-semibold">
                                        Stage {task.stage} · {task.title}
                                    </h3>
                                    <p className="text-sm whitespace-pre-wrap">
                                        {task.description}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Target:{' '}
                                        {task.due_offset_days === 0
                                            ? 'effective date'
                                            : Math.abs(task.due_offset_days) +
                                              ' days ' +
                                              (task.due_offset_days < 0
                                                  ? 'before'
                                                  : 'after') +
                                              ' effective date'}{' '}
                                        · Approval{' '}
                                        {task.approval_required
                                            ? 'required'
                                            : 'not required'}{' '}
                                        · Evidence{' '}
                                        {task.evidence_required
                                            ? 'required'
                                            : 'requested at fulfilment'}
                                    </p>
                                    {task.dependency_task_keys.length > 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            After:{' '}
                                            {task.dependency_task_keys
                                                .map(
                                                    (key) =>
                                                        selected.contract.tasks.find(
                                                            (dependency) =>
                                                                dependency.task_key ===
                                                                key,
                                                        )?.title ?? key,
                                                )
                                                .join(', ')}
                                        </p>
                                    )}
                                </div>
                            ))}
                    </ProvisioningSection>
                )}
            </main>
            {operation && (
                <ProvisioningCommandDialog
                    key={operation}
                    context={{
                        actorId,
                        kind: 'template',
                        targetId: template.id,
                        operation,
                    }}
                    version={template.version}
                    title={template.name}
                    description={
                        operation === 'publish'
                            ? 'Publish the current saved draft for new workflows. Existing work keeps its original version.'
                            : 'Stop this template accepting new workflow launches. Existing work and every retained version remain available.'
                    }
                    fields={[
                        {
                            key: 'reason',
                            label: 'Publication decision',
                            kind: 'textarea',
                            required: true,
                        },
                    ]}
                    extraPayload={{
                        expected_published_version_id:
                            template.published_version_id,
                    }}
                    review={[
                        {
                            label: 'Saved draft version',
                            value: String(template.version),
                        },
                        {
                            label: 'Currently published',
                            value: published
                                ? String(published.version)
                                : 'None',
                        },
                        ...(operation === 'publish'
                            ? (current?.contract.tasks ?? []).map((task) => ({
                                  label:
                                      'Stage ' +
                                      task.stage +
                                      ' · ' +
                                      task.title,
                                  value:
                                      [
                                          task.description,
                                          task.approval_required
                                              ? 'Approval required'
                                              : null,
                                          task.evidence_required
                                              ? 'Evidence required'
                                              : null,
                                          task.dependency_task_keys.length
                                              ? 'After: ' +
                                                task.dependency_task_keys
                                                    .map(
                                                        (key) =>
                                                            current?.contract.tasks.find(
                                                                (dependency) =>
                                                                    dependency.task_key ===
                                                                    key,
                                                            )?.title ?? key,
                                                    )
                                                    .join(', ')
                                              : null,
                                      ]
                                          .filter(Boolean)
                                          .join(' · ') ||
                                      'Use the template instructions.',
                              }))
                            : []),
                    ]}
                    onClose={() => setOperation(null)}
                    onDenied={() => {
                        setOperation(null);
                        setVisible(false);
                    }}
                />
            )}
        </AppLayout>
    );
}

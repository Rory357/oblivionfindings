/**
 * Incident templates (approved H&S drawing pg-hsn-3, Stephan 23 Oct 2026):
 * the list header with two numbers, then one table — active first, then A–Z —
 * with Edit from the row's menu.
 */
import {
    INCIDENT_TYPE_LABELS,
    SEVERITY_LABELS,
    SEVERITY_VARIANT,
    sentenceCase,
} from '@/components/incidents/incident-stages';
import { RecordTable } from '@/components/incidents/record-table';
import {
    PageHeader,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { Ring } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileText, Pencil, Plus } from 'lucide-react';

export type IncidentTemplateRow = {
    id: number;
    name: string;
    type: string | null;
    severity: string | null;
    is_active: boolean;
};

type Props = {
    templates: IncidentTemplateRow[];
};

function typeLabel(type: string | null): string | null {
    if (!type) return null;
    return INCIDENT_TYPE_LABELS[type] ?? sentenceCase(type);
}

export default function IncidentTemplateIndex({ templates }: Props) {
    const { auth } = usePage<SharedData>().props;
    const canManage = Boolean(auth?.can?.incidents?.templatesManage);
    const activeCount = templates.filter((t) => t.is_active).length;
    const showTable = () =>
        document
            .getElementById('incident-templates')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const edit = (t: IncidentTemplateRow) =>
        router.visit(`/incidents/templates/${t.id}`);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Incidents', href: '/incidents' },
                { title: 'Templates', href: '/incidents/templates' },
            ]}
        >
            <Head title="Incident templates" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={FileText}
                        title="Templates"
                        subline="Templates that pre-fill new incident reports"
                        actions={
                            canManage ? (
                                <PageHeaderPrimaryButton asChild icon={Plus}>
                                    <Link href="/incidents/templates/create">
                                        Add template
                                    </Link>
                                </PageHeaderPrimaryButton>
                            ) : undefined
                        }
                        strip={
                            <PageHeaderNumbers>
                                <PageHeaderNumber
                                    label="Total"
                                    value={templates.length}
                                    onClick={showTable}
                                    ariaLabel="View all templates"
                                />
                                <PageHeaderNumber
                                    label="Active"
                                    value={activeCount}
                                    tone={activeCount ? 'success' : undefined}
                                    onClick={showTable}
                                    ariaLabel="View active templates"
                                />
                            </PageHeaderNumbers>
                        }
                    />
                }
            >
                <RecordTable
                    id="incident-templates"
                    title="Templates"
                    sub={
                        canManage
                            ? 'Active first, then A–Z · they pre-fill the incident form · edit from ⋮'
                            : 'Active first, then A–Z · they pre-fill the incident form'
                    }
                    ariaLabel="Incident templates"
                    rows={templates}
                    rowKey={(t) => t.id}
                    onOpen={canManage ? edit : undefined}
                    actionsFor={
                        canManage
                            ? (t) => [
                                  {
                                      label: 'Edit',
                                      icon: Pencil,
                                      onClick: () => edit(t),
                                  },
                              ]
                            : undefined
                    }
                    menuTitle={(t) => t.name}
                    menuIcon={FileText}
                    columns={[
                        {
                            key: 'name',
                            label: 'Template',
                            cell: (t) => (
                                <b className="font-semibold text-foreground">
                                    {t.name}
                                </b>
                            ),
                        },
                        {
                            key: 'type',
                            label: 'Type',
                            nowrap: true,
                            cell: (t) =>
                                typeLabel(t.type) ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ),
                        },
                        {
                            key: 'severity',
                            label: 'Default severity',
                            nowrap: true,
                            cell: (t) =>
                                t.severity ? (
                                    <StatusBadge
                                        variant={
                                            SEVERITY_VARIANT[t.severity] ??
                                            'neutral'
                                        }
                                    >
                                        {SEVERITY_LABELS[t.severity] ??
                                            sentenceCase(t.severity)}
                                    </StatusBadge>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ),
                        },
                        {
                            key: 'status',
                            label: 'Status',
                            nowrap: true,
                            cell: (t) =>
                                t.is_active ? (
                                    <StatusBadge variant="success">
                                        Active
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="neutral">
                                        Inactive
                                    </StatusBadge>
                                ),
                        },
                    ]}
                    empty={
                        <p className="sg-quiet">
                            <Ring tone="none" />
                            <span>No templates yet.</span>
                        </p>
                    }
                />
            </PageLayout>
        </AppLayout>
    );
}

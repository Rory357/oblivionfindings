import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    History,
    Info,
    Pencil,
    Plus,
    Trash2,
    X,
    type LucideIcon,
} from 'lucide-react';
import { humaniseHorizon } from './_dialogs';

type ChangeType = 'added' | 'updated' | 'removed';

interface Change {
    type: ChangeType;
    goal: string;
    detail: string;
}

interface Props {
    plan: {
        id: number;
        title: string;
        planning_horizon?: string;
        period_start?: string | null;
        period_end?: string | null;
        version_number?: number;
        supersedes?: {
            id: number;
            title: string;
            version_number: number;
        } | null;
    };
    changes: {
        has_snapshot: boolean;
        baseline_label?: string;
        changes: Change[];
    };
}

const TYPES: Record<
    ChangeType,
    { label: string; caption: string; icon: LucideIcon; variant: StatusVariant }
> = {
    added: {
        label: 'Added',
        caption: 'New goals',
        icon: Plus,
        variant: 'success',
    },
    updated: {
        label: 'Updated',
        caption: 'Changed goals',
        icon: Pencil,
        variant: 'warning',
    },
    removed: {
        label: 'Removed',
        caption: 'Goals removed',
        icon: Trash2,
        variant: 'critical',
    },
};

const ORDER: ChangeType[] = ['added', 'updated', 'removed'];
const ALL = '__all';

const dateOnly = (value: string | null | undefined) =>
    (value ?? '').slice(0, 10);

export default function StrategyChanges({ plan, changes }: Props) {
    const page = usePage();
    const typeParam = new URLSearchParams(page.url.split('?')[1] ?? '').get(
        'type',
    );
    const activeType = ORDER.includes(typeParam as ChangeType)
        ? (typeParam as ChangeType)
        : null;

    const all = changes.changes ?? [];
    const grouped = ORDER.reduce<Record<ChangeType, Change[]>>(
        (acc, type) => {
            acc[type] = all.filter((change) => change.type === type);
            return acc;
        },
        { added: [], updated: [], removed: [] },
    );
    const visibleTypes = activeType ? [activeType] : ORDER;
    const baseHref = `/governance/strategy/${plan.id}/changes`;

    const setType = (value: string) =>
        router.get(baseHref, value === ALL ? {} : { type: value }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const periodLine =
        plan.period_start && plan.period_end
            ? `${formatDateOnly(dateOnly(plan.period_start))} – ${formatDateOnly(dateOnly(plan.period_end))}`
            : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategic plans', href: '/governance/strategy' },
                { title: plan.title, href: `/governance/strategy/${plan.id}` },
                { title: 'Changes', href: baseHref },
            ]}
        >
            <Head title={`Changes — ${plan.title}`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={`/governance/strategy/${plan.id}`}
                        icon={History}
                        title={`Changes — ${plan.title}`}
                        subline={[
                            changes.has_snapshot
                                ? `Compared with ${changes.baseline_label ?? 'the last snapshot'}`
                                : 'No approved baseline to compare with',
                            plan.planning_horizon
                                ? humaniseHorizon(plan.planning_horizon)
                                : null,
                            periodLine,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        meters={
                            <>
                                {ORDER.map((type) => (
                                    <PageHeaderMeterBlock
                                        key={type}
                                        label={TYPES[type].label}
                                        href={`${baseHref}?type=${type}`}
                                        preserveScroll
                                        tone={
                                            grouped[type].length === 0
                                                ? 'brand'
                                                : type === 'added'
                                                  ? 'success'
                                                  : type === 'updated'
                                                    ? 'warning'
                                                    : 'critical'
                                        }
                                        ariaLabel={`View ${TYPES[type].label.toLowerCase()} goals`}
                                    >
                                        <PageHeaderMeterBig>
                                            {grouped[type].length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {TYPES[type].caption}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                                <PageHeaderMeterBlock
                                    label="All changes"
                                    href={baseHref}
                                    preserveScroll
                                >
                                    <PageHeaderMeterBig>
                                        {all.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Since{' '}
                                        {changes.baseline_label ??
                                            'the last snapshot'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            changes.has_snapshot ? (
                                <PageHeaderFilterSelect
                                    label="Change type"
                                    value={activeType ?? ALL}
                                    allValue={ALL}
                                    options={[
                                        { value: ALL, label: 'All changes' },
                                        ...ORDER.map((type) => ({
                                            value: type,
                                            label: TYPES[type].label,
                                        })),
                                    ]}
                                    onChange={setType}
                                />
                            ) : undefined
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {!changes.has_snapshot ? (
                        <EmptyState
                            icon={Info}
                            title="Comparison not available"
                            description="No prior approved baseline or snapshot exists for this strategic plan version."
                        />
                    ) : all.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title="No changes detected"
                            description={`Goals match ${changes.baseline_label || 'the last snapshot'}.`}
                        />
                    ) : activeType && grouped[activeType].length === 0 ? (
                        <EmptyState
                            icon={TYPES[activeType].icon}
                            title={`No ${TYPES[activeType].label.toLowerCase()} goals`}
                            description="Try another change type."
                            action={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setType(ALL)}
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Show all changes
                                </Button>
                            }
                        />
                    ) : (
                        visibleTypes.map((type) => {
                            const items = grouped[type];
                            if (items.length === 0) return null;
                            const Icon = TYPES[type].icon;

                            return (
                                <Card key={type}>
                                    <CardHeader>
                                        <CardTitle className="text-section-title flex items-center gap-2">
                                            <Icon className="h-4 w-4 text-primary" />
                                            {TYPES[type].label}
                                        </CardTitle>
                                        <CardDescription>
                                            {items.length} goal
                                            {items.length === 1 ? '' : 's'}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3">
                                        {items.map((item, index) => (
                                            <div
                                                key={`${item.goal}-${index}`}
                                                className="flex items-start gap-3 rounded-lg border border-border p-3"
                                            >
                                                <StatusBadge
                                                    variant={
                                                        TYPES[type].variant
                                                    }
                                                >
                                                    {TYPES[type].label}
                                                </StatusBadge>
                                                <div className="min-w-0">
                                                    <p className="font-medium text-foreground">
                                                        {item.goal}
                                                    </p>
                                                    {item.detail ? (
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {item.detail}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}

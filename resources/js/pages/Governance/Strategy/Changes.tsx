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
    Compass,
    History,
    Info,
    Pencil,
    Plus,
    Target,
    Trash2,
    X,
    type LucideIcon,
} from 'lucide-react';
import { humaniseHorizon } from './_dialogs';

type ChangeType = 'added' | 'updated' | 'removed';

interface Change {
    type: ChangeType;
    area?: 'goal' | 'direction';
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
        compared_version?: number | null;
        changes: Change[];
    };
}

const TYPES: Record<
    ChangeType,
    { label: string; caption: string; icon: LucideIcon; variant: StatusVariant }
> = {
    added: {
        label: 'Added',
        caption: 'New in this version',
        icon: Plus,
        variant: 'success',
    },
    updated: {
        label: 'Changed',
        caption: 'Changed in this version',
        icon: Pencil,
        variant: 'warning',
    },
    removed: {
        label: 'Removed',
        caption: 'Taken out of this version',
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
    const filtered = activeType
        ? all.filter((change) => change.type === activeType)
        : all;
    const direction = filtered.filter((change) => change.area === 'direction');
    const goals = filtered.filter((change) => change.area !== 'direction');
    const countOf = (type: ChangeType) =>
        all.filter((change) => change.type === type).length;
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

    const comparedWith = changes.has_snapshot
        ? `Compared with ${changes.baseline_label ?? 'the version the board approved'}`
        : 'Nothing to compare with yet';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategic plan', href: '/governance/strategy' },
                { title: plan.title, href: `/governance/strategy/${plan.id}` },
                { title: 'What changed', href: baseHref },
            ]}
        >
            <Head title={`What changed — ${plan.title}`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={`/governance/strategy/${plan.id}`}
                        icon={History}
                        title={`What changed — ${plan.title}`}
                        subline={[
                            comparedWith,
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
                                            countOf(type) === 0
                                                ? 'brand'
                                                : type === 'added'
                                                  ? 'success'
                                                  : type === 'updated'
                                                    ? 'warning'
                                                    : 'critical'
                                        }
                                        ariaLabel={`View ${TYPES[type].label.toLowerCase()} items`}
                                    >
                                        <PageHeaderMeterBig>
                                            {countOf(type)}
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
                                        Goals, vision, mission and values
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                label="Change"
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
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {!changes.has_snapshot ? (
                        <EmptyState
                            icon={Info}
                            title="Nothing to compare yet"
                            description="This is the first version, so there's nothing to compare yet. Changes show here once the board has approved a version."
                        />
                    ) : all.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title="No changes"
                            description={`Nothing has changed since ${changes.baseline_label ?? 'the version the board approved'}.`}
                        />
                    ) : filtered.length === 0 && activeType ? (
                        <EmptyState
                            icon={TYPES[activeType].icon}
                            title={`Nothing ${TYPES[activeType].label.toLowerCase()}`}
                            description="Try another kind of change."
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
                        <>
                            {direction.length > 0 ? (
                                <ChangeCard
                                    title="Vision, mission and values"
                                    icon={Compass}
                                    items={direction}
                                />
                            ) : null}
                            {goals.length > 0 ? (
                                <ChangeCard
                                    title="Goals"
                                    icon={Target}
                                    items={goals}
                                />
                            ) : null}
                        </>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}

function ChangeCard({
    title,
    icon: Icon,
    items,
}: {
    title: string;
    icon: LucideIcon;
    items: Change[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-section-title flex items-center gap-2">
                    <Icon className="h-4 w-4 text-primary" />
                    {title}
                </CardTitle>
                <CardDescription>
                    {items.length} change{items.length === 1 ? '' : 's'}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {items.map((item, index) => (
                    <div
                        key={`${item.type}-${item.goal}-${index}`}
                        className="flex items-start gap-3 rounded-lg border border-border p-3"
                    >
                        <StatusBadge variant={TYPES[item.type].variant}>
                            {TYPES[item.type].label}
                        </StatusBadge>
                        <div className="min-w-0">
                            <p className="font-medium text-foreground">
                                {item.goal}
                            </p>
                            {item.detail ? (
                                <p className="text-subtle mt-1">
                                    {item.detail}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

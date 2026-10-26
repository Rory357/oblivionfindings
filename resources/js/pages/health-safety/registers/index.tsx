import {
    filterSafetyDirectory,
    SafetyDirectory,
    SafetyDirectorySearch,
    type SafetyDirectoryStatus,
} from '@/components/health-safety/workspace-directory';
import { Log, type SgTone } from '@/components/signature/blocks';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderRail,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import {
    SAFETY_HUBS,
    SAFETY_REGISTER_GROUPS,
    visibleSafetyViews,
} from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ClipboardCheck, Settings } from 'lucide-react';
import { useState } from 'react';

/** One open or due item from a register the viewer can read (most urgent first). */
type RegisterAttention = {
    key: string;
    time: string | null;
    tone: SgTone;
    title: string;
    sub: string | null;
    action: { label: string; href: string };
};

export default function SafetyRegisters({
    can_manage_settings = false,
    register_status = {},
    attention = null,
}: {
    can_manage_settings?: boolean;
    /** Per-register flag and facts, keyed by the register's view key (optional; cards show descriptions without it). */
    register_status?: Record<string, SafetyDirectoryStatus>;
    /** Open and due items across the registers (optional; the list is hidden without it). */
    attention?: RegisterAttention[] | null;
}) {
    const { props, url } = usePage<SharedData>();
    const [query, setQuery] = useState('');
    const views = visibleSafetyViews(
        SAFETY_HUBS.find((hub) => hub.key === 'registers')!,
        props.auth.can,
    );
    const groups = SAFETY_REGISTER_GROUPS.filter((group) =>
        views.some((view) => view.group === group.key),
    );
    const requested = new URL(url, 'https://safety.local').searchParams.get(
        'view',
    );
    const selected =
        groups.find((group) => group.key === requested) ?? groups[0];
    const items = query.trim()
        ? views
        : views.filter((view) => view.group === selected?.key);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safety registers', href: '/health-safety/registers' },
            ]}
        >
            <Head title="Safety registers" />
            <PageLayout
                hero={
                    <PageHeader
                        title="Safety registers"
                        icon={ClipboardCheck}
                        subline="People, equipment and worker participation"
                        wrapTitle
                        frontline
                        actions={
                            <>
                                <SafetyDirectorySearch
                                    value={query}
                                    onChange={setQuery}
                                />
                                {can_manage_settings && (
                                    <PageHeaderGlassButton
                                        asChild
                                        icon={Settings}
                                    >
                                        <Link href="/health-safety/settings">
                                            Settings
                                        </Link>
                                    </PageHeaderGlassButton>
                                )}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={groups}
                                value={selected?.key ?? ''}
                                ariaLabel="Register groups"
                                onSelect={(key) => {
                                    setQuery('');
                                    router.get(
                                        '/health-safety/registers',
                                        { view: key },
                                        { preserveScroll: true },
                                    );
                                }}
                            />
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <SafetyDirectory
                        views={filterSafetyDirectory(items, query)}
                        status={register_status}
                        label={
                            query.trim()
                                ? 'Matching safety registers'
                                : (selected?.label ?? 'Safety registers')
                        }
                    />
                    {!query.trim() && attention?.length ? (
                        <Log
                            title="Needs attention in the registers"
                            sub="Most urgent first · from records you can see"
                            items={attention.map((item) => ({
                                key: item.key,
                                time: item.time ?? '',
                                tone: item.tone,
                                title: item.title,
                                sub: item.sub ?? undefined,
                                action: item.action,
                            }))}
                        />
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}

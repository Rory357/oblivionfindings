import {
    filterSafetyDirectory,
    SafetyDirectory,
    SafetyDirectorySearch,
} from '@/components/health-safety/workspace-directory';
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
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardCheck, Settings } from 'lucide-react';
import { useState } from 'react';

export default function SafetyRegisters({
    can_manage_settings = false,
}: {
    can_manage_settings?: boolean;
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
                                        href="/health-safety/settings"
                                        icon={Settings}
                                    >
                                        Settings
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
                <SafetyDirectory
                    views={filterSafetyDirectory(items, query)}
                    label={
                        query.trim()
                            ? 'Matching safety registers'
                            : (selected?.label ?? 'Safety registers')
                    }
                />
            </PageLayout>
        </AppLayout>
    );
}

import { PageHeader, PageHeaderGlassButton } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { GitBranch } from 'lucide-react';
import { GroupWizard, type WizardGroup } from './_dialogs';

export default function DeviceGroupForm({
    group,
    isEdit = false,
}: {
    group?: WizardGroup;
    isEdit?: boolean;
}) {
    const destination =
        isEdit && group
            ? `/security-devices/device-groups/${group.id}`
            : '/security-devices/device-groups';
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Device groups',
                    href: '/security-devices/device-groups',
                },
                { title: isEdit ? 'Edit device group' : 'Add device group' },
            ]}
        >
            <Head
                title={`${isEdit ? 'Edit' : 'Add'} device group - Security & Devices`}
            />
            <PageHeader
                icon={GitBranch}
                title={isEdit ? 'Edit device group' : 'Add device group'}
                subline="Group details and optional automatic membership rules."
                actions={
                    <PageHeaderGlassButton asChild>
                        <Link href={destination}>
                            Back to {isEdit ? 'group' : 'device groups'}
                        </Link>
                    </PageHeaderGlassButton>
                }
            />
            <GroupWizard
                open
                group={isEdit ? group : undefined}
                onClose={() => router.visit(destination)}
            />
        </AppLayout>
    );
}

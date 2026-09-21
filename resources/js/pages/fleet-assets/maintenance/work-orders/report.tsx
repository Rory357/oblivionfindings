import PageShell from '@/components/page-shell';
import { PageHeader, PageHeaderPrimaryButton } from '@/components/page/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { WorkOrderCreateWizard, type WizardAsset } from '@/pages/fleet-assets/maintenance/work-orders/create-wizard';
import { Head } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import { useState } from 'react';

export default function ReportMaintenance({ assets, prefill_asset_id }: { assets: WizardAsset[]; prefill_asset_id: number | null }) {
    const [open, setOpen] = useState(true);
    const close = () => setOpen(false);

    return <AppLayout breadcrumbs={[{ title: 'Fleet & Assets', href: '/fleet-assets' }, { title: 'Report a problem', href: '/fleet-assets/maintenance/work-orders/create' }]}>
        <Head title="Report a problem" />
        <PageShell>
            <PageHeader variant="profile" icon={Wrench} backHref="/fleet-assets" title="Report a problem"
                subline="Describe an asset concern for your site's approved Coordinator."
                actions={<PageHeaderPrimaryButton icon={Wrench} onClick={() => setOpen(true)}>{open ? 'Report a problem' : 'Resume report'}</PageHeaderPrimaryButton>} />
            <Card className="mx-auto mt-6 max-w-3xl"><CardHeader><CardTitle>Maintenance report</CardTitle></CardHeader>
                <CardContent className="space-y-3 text-sm text-muted-foreground">
                    <p>{open ? 'Choose an asset in your approved sites, record what you observed and add relevant evidence.' : 'Your unsent report and selected files are kept on this page. Resume the report to continue. Leaving or reloading the page discards an unsent draft.'}</p>
                    <p>The site Coordinator assesses the report. Submitting it does not complete work or release an asset.</p>
                </CardContent></Card>
            <WorkOrderCreateWizard open={open} onClose={close} assets={assets} users={[]} checklistRuns={[]}
                prefillAssetId={prefill_asset_id ? String(prefill_asset_id) : null} canLink={false} />
        </PageShell>
    </AppLayout>;
}

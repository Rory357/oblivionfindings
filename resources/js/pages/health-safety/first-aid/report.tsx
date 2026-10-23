import { FirstAidTreatmentWizard } from '@/components/health-safety/first-aid/treatment-wizard';
import type { FirstAidPickers } from '@/components/health-safety/first-aid/types';
import { PageHeader, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    FIRST_AID_REGISTER,
    firstAidReturn,
} from '@/lib/health-safety/first-aid-navigation';
import { Head, router, usePage } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import { useState } from 'react';

export default function FirstAidReport({
    pickers,
    can,
}: {
    pickers: FirstAidPickers;
    can: { create: boolean; view: boolean };
}) {
    const { url } = usePage();
    const query = new URL(url, 'https://first-aid.invalid').searchParams;
    const selectedReturn = firstAidReturn(query.get('return_to'));
    const returnTo =
        !can.view && selectedReturn.split('?')[0] === FIRST_AID_REGISTER
            ? '/dashboard'
            : selectedReturn;
    const [open, setOpen] = useState(true);
    const drafts = useSafetyWorkspaceDrafts();
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(can.view
                    ? [{ title: 'First aid', href: FIRST_AID_REGISTER }]
                    : []),
                {
                    title: 'Record first aid',
                    href: `${FIRST_AID_REGISTER}/report`,
                },
            ]}
        >
            <Head title="Record first aid" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            frontline
                            wrapTitle
                            icon={HeartPulse}
                            title="Record first aid"
                            backHref={returnTo}
                            subline="Record treatment, the person involved and what happened next."
                        />
                    }
                >
                    <Card>
                        <CardContent className="space-y-4 p-5 sm:p-6">
                            <h2 className="text-section-title">
                                Treatment report
                            </h2>
                            <p className="text-sm">
                                Follow the guided form to record the treatment.
                                A saved treatment has its own page for
                                follow-ups, evidence and connected work.
                            </p>
                            <Button
                                className="frontline-tap"
                                onClick={() => setOpen(true)}
                                disabled={!can.create}
                            >
                                Record first aid
                            </Button>
                        </CardContent>
                    </Card>
                    {open && (
                        <FirstAidTreatmentWizard
                            pickers={pickers}
                            returnTo={returnTo}
                            onClose={() =>
                                drafts.request(() => {
                                    setOpen(false);
                                    router.visit(returnTo);
                                })
                            }
                        />
                    )}
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}

/**
 * Record first aid (approved H&S drawing pg-hsn-84 — Stephan 23 Oct 2026):
 * only the header changes — the treatment wizard's five steps run in it as
 * one line. The wizard itself (its steps, fields, checks and wording) is
 * unchanged and still opens as the Record first aid pop-up.
 */
import {
    FIRST_AID_TREATMENT_STEPS,
    FirstAidTreatmentWizard,
} from '@/components/health-safety/first-aid/treatment-wizard';
import type { FirstAidPickers } from '@/components/health-safety/first-aid/types';
import {
    PageHeader,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderStages,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
import { Note } from '@/components/signature/blocks';
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
import { HeartPulse, Info, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';

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
    const [progress, setProgress] = useState({ step: 0, saved: false });
    const onStepChange = useCallback(
        (step: number, saved: boolean) => setProgress({ step, saved }),
        [],
    );
    const drafts = useSafetyWorkspaceDrafts();
    const steps: PageHeaderStage[] = FIRST_AID_TREATMENT_STEPS.map(
        (item, index) => ({
            label: item.label,
            meta: item.blurb,
            state:
                progress.saved || index < progress.step
                    ? 'done'
                    : index === progress.step
                      ? 'now'
                      : 'todo',
        }),
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...(can.view
                    ? [
                          { title: 'Health & safety', href: '/health-safety' },
                          {
                              title: 'Safety registers',
                              href: '/health-safety/registers',
                          },
                          { title: 'First aid', href: FIRST_AID_REGISTER },
                      ]
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
                            wrapTitle
                            icon={HeartPulse}
                            title="Record first aid"
                            backHref={returnTo}
                            subline="Record treatment, the person involved and what happened next"
                            actions={
                                !open ? (
                                    <PageHeaderPrimaryButton
                                        icon={Plus}
                                        onClick={() => setOpen(true)}
                                        disabled={!can.create}
                                    >
                                        Record first aid
                                    </PageHeaderPrimaryButton>
                                ) : undefined
                            }
                            strip={
                                <PageHeaderStages
                                    steps={steps}
                                    ariaLabel="Treatment report steps"
                                />
                            }
                        />
                    }
                >
                    <Note icon={Info}>
                        Follow the guided form to record the treatment. A saved
                        treatment has its own page for follow-ups, evidence and
                        connected work.
                    </Note>
                    {open && (
                        <FirstAidTreatmentWizard
                            pickers={pickers}
                            returnTo={returnTo}
                            onStepChange={onStepChange}
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

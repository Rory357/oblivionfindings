/**
 * Report workplace injury (approved H&S drawings pg-hsn-87, 87b — Stephan 23
 * Oct 2026): only the header changes — the injury wizard's three steps run in
 * it as one line. The wizard (its steps, fields, checks and wording) is
 * unchanged and still opens as the Report injury pop-up.
 */
import { InjuryCommandForm } from '@/components/health-safety/injury-command-form';
import { INJURY_WIZARD_STEPS } from '@/components/health-safety/injury-command-state';
import type { InjuryCaptureOptions } from '@/components/health-safety/injury-workspace-types';
import { PageHeader, PageHeaderPrimaryButton, PageLayout } from '@/components/page';
import { PageHeaderStages, type PageHeaderStage } from '@/components/page/page-header-strip';
import { Note } from '@/components/signature/blocks';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryReturn } from '@/lib/health-safety/injury-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, HeartPulse, Info, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';

export default function InjuryCreate({ capture, return_to }: { capture: InjuryCaptureOptions; return_to?: string | null }) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts();
    // Report-only people (injuries.report, Stephan 30 Oct) can't open the injury register: no register crumbs, and
    // Cancel / Done go back to My Day instead of a page that refuses them (found in the 30 Oct user test).
    const canSeeRegister = !!(shared.auth?.can as { hazards?: { view?: boolean } } | undefined)?.hazards?.view;
    const [open, setOpen] = useState(true); const returnTo = canSeeRegister ? injuryReturn(return_to) : '/my-day';
    const [progress, setProgress] = useState({ step: 0, saved: false });
    const onStepChange = useCallback((step: number, saved: boolean) => setProgress({ step, saved }), []);
    const leave = () => drafts.request(() => { setOpen(false); router.get(returnTo); });
    const steps: PageHeaderStage[] = INJURY_WIZARD_STEPS.map((item, index) => ({
        label: item.label, meta: item.blurb,
        state: progress.saved || index < progress.step ? 'done' : index === progress.step ? 'now' : 'todo',
    }));
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, ...(canSeeRegister ? [{ title: 'Health & safety', href: '/health-safety' }, { title: 'Injuries and care', href: '/health-safety/registers?view=care' }, { title: 'Workplace injuries', href: INJURY_REGISTER }] : []), { title: 'Report workplace injury', href: `${INJURY_REGISTER}/create` }]}>
        <Head title="Report workplace injury" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" title="Report workplace injury" backHref={returnTo} icon={HeartPulse} subline="Tell us what happened, then choose who looks after it next"
                actions={!open && capture.can.create ? <PageHeaderPrimaryButton icon={Plus} onClick={() => setOpen(true)}>Open injury form</PageHeaderPrimaryButton> : undefined}
                strip={<PageHeaderStages steps={steps} ariaLabel="Injury report steps" />} />}>
                <div className="grid min-w-0 gap-5">
                    <Note icon={Info}>{canSeeRegister ? 'Once saved, the injury gets its own page showing its owner, recovery plan, files and history.' : 'Once saved, the health and safety team takes it from here. If you were the one hurt, you can follow it in My HR, under Injuries.'}</Note>
                    {!capture.can.create && <Note tone="warning" icon={AlertTriangle}><p role="alert">Reporting is no longer available with your current access. Ask your team leader or a health and safety manager to check your access.</p></Note>}
                </div>
                {open && <InjuryCommandForm key={`capture:${shared.auth.user.id}`} detail={null} command="create" actorId={shared.auth.user.id} capture={capture} returnTo={returnTo} onStepChange={onStepChange} onCancel={leave} onDone={(href) => { setOpen(false); router.get(href && canSeeRegister ? injuryReturn(href) : returnTo); }} />}
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

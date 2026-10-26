/**
 * Record an exposure (approved H&S drawings pg-hsn-130, 130b — Stephan 23 Oct 2026): the page frame around the
 * existing Record a private exposure wizard. The wizard's two steps run as the stage line in the header and follow
 * it; the wizard (fields, checks, review and the original submission) is unchanged and stays a pop-up over the page.
 * Both access lines and the confirmation keep the code's wording; reporting never grants reading the saved record.
 */
import { PageHeader, PageHeaderGlassButton, PageLayout } from '@/components/page';
import { PageHeaderStages, type PageHeaderStage } from '@/components/page/page-header-strip';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { CHEMICAL_EXPOSURES, exposureCaptureHref, exposureReturn } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureCapture } from '@/lib/health-safety/chemical-exposure-read-contract';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { CircleCheck, Info, LockKeyhole, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import { ExposureCommandForm } from './components/exposure-command-form';
import type { ExposureReceipt } from './components/use-exposure-command';

const STEPS = ['Recorded facts', 'Review submission'];
type Props = { capture: ExposureCapture; return_to: string | null; chemical_exposure_confirmation: ExposureReceipt | null; chemical_exposure_href: string | null };
export default function ChemicalExposureCapture(props: Props) {
    const { props: shared } = usePage<SharedData>(); return <CaptureWorkspace key={`${shared.auth.user.id}:${props.capture.substance.id}`} {...props} />;
}
function CaptureWorkspace({ capture, return_to, chemical_exposure_confirmation, chemical_exposure_href }: Props) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts();
    const [open, setOpen] = useState(!chemical_exposure_confirmation); const [withdrawn, setWithdrawn] = useState(false); const [round, setRound] = useState(0);
    const [progress, setProgress] = useState({ step: 0, saved: false });
    const onStep = useCallback((step: number, saved: boolean) => setProgress((current) => (current.step === step && current.saved === saved ? current : { step, saved })), []);
    const onWithdraw = useCallback(() => setWithdrawn(true), []); const returnTo = exposureReturn(return_to);
    const confirmation = chemical_exposure_confirmation?.accepted === true && chemical_exposure_confirmation.actor_id === shared.auth.user.id
        && chemical_exposure_confirmation.command === 'create_exposure' ? chemical_exposure_confirmation : null;
    const savedHref = confirmation && chemical_exposure_href === `${CHEMICAL_EXPOSURES}/${confirmation.root_id}` ? chemical_exposure_href : null;
    const finished = progress.saved || (!!confirmation && !withdrawn);
    const stages: PageHeaderStage[] = STEPS.map((label, index) => ({ label, state: finished || index < progress.step ? 'done' : index === progress.step ? 'now' : 'todo' }));
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' },
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title: 'Record an exposure', href: exposureCaptureHref(capture.substance.id, returnTo) }]}>
        <Head title="Record an exposure" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" title="Record an exposure" icon={LockKeyhole} backHref={returnTo}
                subline={withdrawn ? 'Current capture access unavailable' : `Reported exposure to ${capture.substance.name}`}
                actions={!withdrawn && capture.can.create && !open ? <PageHeaderGlassButton icon={Plus} onClick={() => { setProgress({ step: 0, saved: false }); setOpen(true); }}>Record an exposure</PageHeaderGlassButton> : undefined}
                strip={withdrawn ? undefined : <PageHeaderStages steps={stages} ariaLabel="Record an exposure steps" />} />}>
                <div className="grid min-w-0 gap-5">
                    <Note icon={Info}><p>Opening saved identifiable exposures requires safety and private wellbeing access. Permission to report an exposure does not grant permission to read it later.</p>
                        <p className="mt-1">Include the worker, approved Site, exposure time and response. Saving does not confirm medical recovery or notification delivery.</p></Note>
                    {confirmation && !withdrawn && <div role="status"><Note tone="success" icon={CircleCheck}><b>Original submission confirmed</b> — Your original reported exposure was accepted. Saved facts and access are checked separately.
                        {savedHref && <div className="mt-3"><Button asChild size="sm"><a href={savedHref}>Open private exposure record</a></Button></div>}</Note></div>}
                    <ExposureCommandForm key={round} action="capture" actorId={shared.auth.user.id} capture={withdrawn ? { ...capture, can: { create: false, read_saved: false } } : capture}
                        active={open} onWithdraw={onWithdraw} onStep={open ? onStep : undefined} onCancel={() => drafts.request(() => { setOpen(false); setRound((current) => current + 1); })}
                        onDone={() => { setOpen(false); setRound((current) => current + 1); }} />
                </div>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

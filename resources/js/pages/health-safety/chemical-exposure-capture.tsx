import { PageHeader, PageHeaderGlassButton, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { CHEMICAL_EXPOSURES, exposureCaptureHref, exposureReturn } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureCapture } from '@/lib/health-safety/chemical-exposure-read-contract';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { LockKeyhole, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import { ExposureCommandForm } from './components/exposure-command-form';
import type { ExposureReceipt } from './components/use-exposure-command';

type Props = { capture: ExposureCapture; return_to: string | null; chemical_exposure_confirmation: ExposureReceipt | null; chemical_exposure_href: string | null };
export default function ChemicalExposureCapture(props: Props) {
    const { props: shared } = usePage<SharedData>(); return <CaptureWorkspace key={`${shared.auth.user.id}:${props.capture.substance.id}`} {...props} />;
}
function CaptureWorkspace({ capture, return_to, chemical_exposure_confirmation, chemical_exposure_href }: Props) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts();
    const [open, setOpen] = useState(!chemical_exposure_confirmation); const [withdrawn, setWithdrawn] = useState(false); const [round, setRound] = useState(0);
    const onWithdraw = useCallback(() => setWithdrawn(true), []); const returnTo = exposureReturn(return_to);
    const confirmation = chemical_exposure_confirmation?.accepted === true && chemical_exposure_confirmation.actor_id === shared.auth.user.id
        && chemical_exposure_confirmation.command === 'create_exposure' ? chemical_exposure_confirmation : null;
    const savedHref = confirmation && chemical_exposure_href === `${CHEMICAL_EXPOSURES}/${confirmation.root_id}` ? chemical_exposure_href : null;
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Private exposures', href: CHEMICAL_EXPOSURES }, { title: 'Record an exposure', href: exposureCaptureHref(capture.substance.id, returnTo) }]}>
        <Head title="Record an exposure" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" title="Record an exposure" icon={LockKeyhole} backHref={returnTo}
                subline={withdrawn ? 'Current capture access unavailable' : `Reported exposure to ${capture.substance.name}`}
                actions={!withdrawn && capture.can.create && !open ? <PageHeaderGlassButton icon={Plus} onClick={() => setOpen(true)}>Record an exposure</PageHeaderGlassButton> : undefined} />}>
                <Card><CardContent className="space-y-4 p-5"><h2 className="text-section-title">Record what was reported</h2>
                    <p className="text-subtle">Include the worker, approved Site, exposure time and response. Saving does not confirm medical recovery or notification delivery.</p>
                    <p className="text-subtle">Opening saved identifiable exposures requires safety and private wellbeing access. Permission to report an exposure does not grant permission to read it later.</p>
                    {confirmation && !withdrawn && <div role="status" className="space-y-3"><h2 className="text-section-title">Original submission confirmed</h2>
                        <p className="text-subtle">Your original reported exposure was accepted. Saved facts and access are checked separately.</p>
                        {savedHref && <Button asChild className="frontline-tap"><a href={savedHref}>Open private exposure record</a></Button>}</div>}
                    <ExposureCommandForm key={round} action="capture" actorId={shared.auth.user.id} capture={withdrawn ? { ...capture, can: { create: false, read_saved: false } } : capture}
                        active={open} onWithdraw={onWithdraw} onCancel={() => drafts.request(() => { setOpen(false); setRound((current) => current + 1); })}
                        onDone={() => { setOpen(false); setRound((current) => current + 1); }} />
                </CardContent></Card>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

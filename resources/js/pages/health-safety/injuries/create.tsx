import { InjuryCommandForm } from '@/components/health-safety/injury-command-form';
import type { InjuryCaptureOptions } from '@/components/health-safety/injury-workspace-types';
import { PageHeader, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { INJURY_REGISTER, injuryReturn } from '@/lib/health-safety/injury-navigation';
import type { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import { useState } from 'react';

export default function InjuryCreate({ capture, return_to }: { capture: InjuryCaptureOptions; return_to?: string | null }) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts();
    const [open, setOpen] = useState(true); const returnTo = injuryReturn(return_to);
    const leave = () => drafts.request(() => { setOpen(false); router.get(returnTo); });
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Workplace injuries', href: INJURY_REGISTER }, { title: 'Report injury', href: `${INJURY_REGISTER}/create` }]}>
        <Head title="Report workplace injury" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" title="Report workplace injury" backHref={returnTo} icon={HeartPulse} subline="Record what happened, then confirm the next handover" />}>
                <div className="space-y-4 rounded-xl border border-border bg-card p-5"><p className="text-subtle">The recorded injury has its own page for its owner, recovery work, evidence and history.</p>{!open && capture.can.create && <Button className="frontline-tap" onClick={() => setOpen(true)}>Open injury form</Button>}{!capture.can.create && <p role="alert">Reporting is no longer available with your current access.</p>}</div>
                {open && <InjuryCommandForm key={`capture:${shared.auth.user.id}`} detail={null} command="create" actorId={shared.auth.user.id} capture={capture} returnTo={returnTo} onCancel={leave} onDone={(href) => { setOpen(false); router.get(href ? injuryReturn(href) : returnTo); }} />}
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

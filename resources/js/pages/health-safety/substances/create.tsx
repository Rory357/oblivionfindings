/**
 * Add a chemical (approved H&S drawings pg-hsn-127, 127b — Stephan 23 Oct 2026): the page frame around the existing
 * Add chemical wizard. The wizard's three steps run as the stage line in the header and follow it; the
 * wizard itself (fields, checks, review and saving) is unchanged and stays a pop-up over the page.
 */
import { PageHeader, PageHeaderGlassButton, PageLayout } from '@/components/page';
import { PageHeaderStages, type PageHeaderStage } from '@/components/page/page-header-strip';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalCreateProps } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalReturn } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { CircleCheck, FlaskConical, Info, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import { ChemicalCommandForm } from './_command-form';

const STEPS = ['About the chemical', 'Safe use', 'Check and save'];

export default function ChemicalCreate({ capture, return_to, chemical_confirmation, chemical_href }: ChemicalCreateProps) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts(); const [open, setOpen] = useState(!chemical_confirmation);
    const [progress, setProgress] = useState({ step: 0, saved: false });
    const onStep = useCallback((step: number, saved: boolean) => setProgress((current) => (current.step === step && current.saved === saved ? current : { step, saved })), []);
    const returnTo = chemicalReturn(return_to);
    const finished = progress.saved || !!chemical_confirmation;
    const stages: PageHeaderStage[] = STEPS.map((label, index) => ({ label, state: finished || index < progress.step ? 'done' : index === progress.step ? 'now' : 'todo' }));
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & safety', href: '/health-safety' }, { title: 'Safety registers', href: '/health-safety/registers' },
        { title: 'Chemical register', href: CHEMICAL_REGISTER }, { title: 'Add chemical', href: `${CHEMICAL_REGISTER}/create` }]}>
        <Head title="Add chemical" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader variant="profile" title="Add chemical" icon={FlaskConical} backHref={returnTo} subline="Add the chemical, then its safety data sheets (SDS) and storage"
                actions={!open ? <PageHeaderGlassButton icon={Plus} onClick={() => setOpen(true)}>Add chemical</PageHeaderGlassButton> : undefined}
                strip={<PageHeaderStages steps={stages} ariaLabel="Add chemical steps" />} />}>
                <div className="grid min-w-0 gap-5">
                    <Note icon={Info}>Start with what the chemical is and how to use it safely. If someone was exposed to it, record that separately — exposures are private.</Note>
                    {chemical_confirmation && <div role="status"><Note tone="success" icon={CircleCheck}><b>Chemical saved</b> — you may need extra access to open it. Ask your health and safety manager if you can’t.
                        {chemical_href && <div className="mt-3"><Button asChild size="sm"><a href={chemical_href}>Open the chemical</a></Button></div>}</Note></div>}
                    {!open && !finished && <QuietLine title="Add chemical" text="Start with what the chemical is and how to use it safely." action={{ label: 'Add chemical', onClick: () => setOpen(true) }} />}
                    {open && <ChemicalCommandForm key={shared.auth.user.id} action="create" actorId={shared.auth.user.id} capture={capture} active onStep={onStep}
                        onCancel={() => drafts.request(() => setOpen(false))} onDone={() => setOpen(false)} />}
                </div>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

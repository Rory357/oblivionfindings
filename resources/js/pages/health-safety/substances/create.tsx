import { PageHeader, PageHeaderGlassButton, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SafetyFormDraftContext, useSafetyWorkspaceDrafts } from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import type { ChemicalCreateProps } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, chemicalReturn } from '@/lib/health-safety/chemical-navigation';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { FlaskConical, Plus } from 'lucide-react';
import { useState } from 'react';
import { ChemicalCommandForm } from './_command-form';

export default function ChemicalCreate({ capture, return_to, chemical_confirmation, chemical_href }: ChemicalCreateProps) {
    const { props: shared } = usePage<SharedData>(); const drafts = useSafetyWorkspaceDrafts(); const [open, setOpen] = useState(!chemical_confirmation);
    const returnTo = chemicalReturn(return_to);
    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health & Safety', href: '/health-safety' }, { title: 'Chemical register', href: CHEMICAL_REGISTER }, { title: 'Add reference', href: `${CHEMICAL_REGISTER}/create` }]}>
        <Head title="Add chemical reference" /><SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout hero={<PageHeader frontline variant="profile" title="Add chemical reference" icon={FlaskConical} backHref={returnTo} subline="Record the reference, then add sheets and Site storage from its permanent page"
                actions={!open ? <PageHeaderGlassButton icon={Plus} onClick={() => setOpen(true)}>Add reference</PageHeaderGlassButton> : undefined} />}>
                <Card><CardContent className="space-y-4 p-5"><p className="text-subtle">Start with the chemical identity and safe-use reference. Worker exposures are handled separately with private wellbeing access.</p>
                    {chemical_confirmation && <div role="status" className="space-y-3"><h2 className="text-section-title">Original submission confirmed</h2>
                        <p className="text-subtle">The recorded submission was accepted. Opening its saved record requires separate current access.</p>
                        {chemical_href && <Button asChild className="frontline-tap"><a href={chemical_href}>Open chemical record</a></Button>}</div>}
                    {open && <ChemicalCommandForm key={shared.auth.user.id} action="create" actorId={shared.auth.user.id} capture={capture} active onCancel={() => drafts.request(() => setOpen(false))}
                        onDone={() => setOpen(false)} />}
                </CardContent></Card>
            </PageLayout>{drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    </AppLayout>;
}

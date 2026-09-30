/* Reports & audit › Report builder (Main, Q9) — the REAL shared report
 * builder workspace (pages/reporting/workspace.tsx), unchanged, drawn once
 * with the proposed `medication` domain. Its purpose prompt, versions,
 * sharing and exports are the builder’s own. Scope and controlled-medicine
 * rules are the domain’s (build note 9); it is built after P01’s dose-slot
 * projection. The preview answers the builder’s requests with synthetic
 * results (builder-stub.ts). */
import { Card } from '@/components/ui/card';
import { ReportWorkspace } from '@/pages/reporting/workspace';
import { Lock } from 'lucide-react';
import { installBuilderStub, medicationSources, setBuilderViewer } from '../builder-stub';
import { has } from '../data';
import { canReports, cdView, peopleOf } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote } from '../ui';

installBuilderStub();

export function BuilderPage() {
    const s = useStore();
    const p = s.route.persona;
    const crumbs = [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: hrefFor('/emar/reports', {}, s.route) }, { title: 'Reports & audit', href: hrefFor('/emar/reports', {}, s.route) }, { title: 'Report builder' }];
    if (!canReports(p))
        return (
            <Shell crumbs={crumbs}>
                <Card className="items-center gap-3 p-10 text-center">
                    <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h2 className="text-section-title">The report builder is for people who view medication reports</h2>
                    <p className="text-subtle">House leads, clinical leads, coordinators, managers and auditors. Ask your manager if you need it for your work.</p>
                </Card>
            </Shell>
        );
    setBuilderViewer({ pids: peopleOf(p), cd: cdView(p) });
    return (
        <Shell crumbs={crumbs}>
            <ReportWorkspace key={p} initialView={s.route.q.get('view') ?? 'builder'} domain="medication" sources={medicationSources(cdView(p))} templates={[]} saved={[]} viewerId={10} canExport={has(p, 'reports.export')} exportFormats={['csv', 'xlsx', 'pdf']} />
            <DesignNote title="Design note — the medication domain in the shared report builder (Main, Q9)">
                <p>
                    This is the real workspace, unchanged, with a proposed <code>medication</code> domain: scheduled doses, rounds, as-needed doses, medication errors (facts only), stock and — with controlled-medicine access only — the controlled register. Scope is the reader’s people through the governance scope service; the builder’s own purpose prompt, audit, versions and ≤31-day window apply; exports need permission to export medication reports. It’s built after P01’s dose-slot projection, so its numbers equal the standard reports’. The library view’s title for this domain is a build change (build note 9).
                </p>
            </DesignNote>
        </Shell>
    );
}

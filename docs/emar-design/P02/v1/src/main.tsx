/* eMAR P02 v1 — "Person medication record". Synthetic design preview built with
 * the app's real components (vite.config.mjs aliases @ to resources/js).
 * P01 v1's recording files are reused unchanged from src/p01/ (byte-identical,
 * see VERSION.txt), so recording from the chart opens P01's approved dialog.
 * Nothing here calls an application API. */
import './p01/clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { Card } from '@/components/ui/card';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { P02DialogHost } from './dialogs';
import { ContractPage } from './pages/contract';
import { ClientProfilePage } from './pages/client-profile';
import { RecordPage } from './pages/record';
import { DialogHost as P01DialogHost } from './p01/doses';
import { StoreProvider as P01StoreProvider } from './p01/store';
import { Shell } from './shell';
import { P02Provider, useP02 } from './store';

const LATER: Record<string, [string, string]> = {
    '/emar/mar-hub': ['MAR & medicines (the cross-person hub)', 'The hub’s views (MAR charts board, Medicines, As-needed history, Support & self-administration) are not in P02 — its rows open this record.'],
    '/meds/today': ['Meds today', 'Designed in P01 (v1, awaiting approval). Its rows’ “Open … medication record” lands on this record.'],
    '/emar/prescriptions': ['Orders & reviews', 'Designed in P04. Orders are added, changed, checked and stopped there — never on the medication record.'],
    '/operations/clients': ['Clients', 'The clients list is the Clients module’s and unchanged.'],
    '/dashboard': ['Home', 'Outside this preview.'],
};
function Later() {
    const s = useP02();
    const [what, why] = LATER[s.route.path] ?? ['This page', 'Outside this preview.'];
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: what }]}>
            <Card className="gap-2 p-6">
                <h1 className="text-section-title">{what} is outside P02</h1>
                <p className="text-subtle">{why}</p>
            </Card>
        </Shell>
    );
}

function Router() {
    const s = useP02();
    const p = s.route.path;
    let page;
    if (p === '/emar/mar') page = <RecordPage />;
    else if (/^\/operations\/clients\/\d+$/.test(p)) page = <ClientProfilePage />;
    else if (p === '/p02/contract') page = <ContractPage />;
    else page = <Later />;
    return (
        <>
            {page}
            <P01DialogHost />
            <P02DialogHost />
        </>
    );
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <TooltipProvider>
            <P01StoreProvider>
                <P02Provider>
                    <Router />
                </P02Provider>
            </P01StoreProvider>
        </TooltipProvider>
    </StrictMode>,
);

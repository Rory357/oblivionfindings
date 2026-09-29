/* eMAR P01 v1 — "Record a dose (all entry points)". Synthetic design preview.
 * Built with the app's real components (vite.config.mjs aliases @ to
 * resources/js); nothing here calls an application API. */
import './clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Lock } from 'lucide-react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { isFrontline } from './data';
import { DialogHost } from './doses';
import { ContractPage } from './pages/contract';
import { ClientProfilePage, MarPage, TransportPage } from './pages/entry-points';
import { MedsTodayPage } from './pages/meds-today';
import { MyCalendarPage } from './pages/my-calendar';
import { MyDayPage } from './pages/my-day';
import { TasksPage } from './pages/tasks';
import { Shell } from './shell';
import { StoreProvider, useStore } from './store';

function NoAccess({ what }: { what: string }) {
    const s = useStore();
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }]}>
            <Card className="items-center gap-3 p-10 text-center">
                <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                <h1 className="text-section-title">You don’t have access to {what}</h1>
                <p className="text-subtle">Ask your manager if you need it for your work.</p>
                <Button variant="outline" onClick={() => s.go('/meds/today')}>
                    Go to Meds today
                </Button>
            </Card>
        </Shell>
    );
}

function LaterPackage({ what, pkg }: { what: string; pkg: string }) {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/meds/today' }, { title: what }]}>
            <Card className="gap-2 p-6">
                <h1 className="text-section-title">{what} is designed in {pkg}</h1>
                <p className="text-subtle">Outside P01. The app keeps today’s screen until that package ships.</p>
            </Card>
        </Shell>
    );
}

function Router() {
    const s = useStore();
    const p = s.route.path;
    let page;
    if (p.startsWith('/meds/today')) page = <MedsTodayPage />;
    else if (p === '/my-day') page = <MyDayPage />;
    else if (p === '/tasks') page = <TasksPage />;
    else if (p === '/my-calendar') page = <MyCalendarPage />;
    else if (p === '/emar/mar') page = <MarPage />;
    else if (p.startsWith('/operations/clients')) page = <ClientProfilePage />;
    else if (p.startsWith('/fleet-assets/transports')) page = <TransportPage />;
    else if (p === '/p01/contract') page = <ContractPage />;
    else if (p === '/emar/prescriptions') page = isFrontline(s.route.persona) ? <NoAccess what="Orders & reviews" /> : <LaterPackage what="Orders & reviews" pkg="P04" />;
    else page = <MedsTodayPage />;
    return (
        <>
            {page}
            <DialogHost />
        </>
    );
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <TooltipProvider>
            <StoreProvider>
                <Router />
            </StoreProvider>
        </TooltipProvider>
    </StrictMode>,
);

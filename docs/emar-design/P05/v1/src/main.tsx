/* eMAR P05 v1 — "Medication review". Synthetic design preview. Built with the
 * app's real components (vite.config.mjs aliases @ to resources/js); nothing
 * here calls an application API. */
import './clock';
import './styles.css';
import { Card } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { ActionsPage } from './pages/actions';
import { ContractPage } from './pages/contract';
import { RecordPage } from './pages/record';
import { ReviewsPage } from './pages/reviews';
import { SettingsPage } from './pages/settings';
import { Shell } from './shell';
import { StoreProvider, useStore } from './store';

function Outside() {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Outside this preview' }]}>
            <Card className="p-8 text-center">
                <p className="text-section-title">Outside this preview</p>
                <p className="text-subtle mt-1">That screen belongs to another package or to the client profile. Go back to carry on.</p>
                <p className="mt-4">
                    <a className="text-primary underline-offset-2 hover:underline" href="#" onClick={(e) => (e.preventDefault(), window.history.back())}>
                        Go back
                    </a>
                </p>
            </Card>
        </Shell>
    );
}

function Router() {
    const s = useStore();
    const path = s.route.path;
    const page =
        path === '/p05/contract' ? <ContractPage /> : path === '/emar/mar' ? <RecordPage /> : path === '/clients/profile' ? <ActionsPage /> : path === '/emar/settings' ? <SettingsPage /> : path.startsWith('/outside/') ? <Outside /> : <ReviewsPage />;
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

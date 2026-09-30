/* eMAR P09 v1 — "Reports & audit". Synthetic design preview. Built with the
 * app's real components (vite.config.mjs aliases @ to resources/js); nothing
 * here calls an application API. */
import './clock';
import './styles.css';
import { Card } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { BuilderPage } from './pages/builder';
import { ContractPage } from './pages/contract';
import { ErrorsFrame } from './pages/errors-frame';
import { ReportsHub } from './pages/hub';
import { SettingsPage } from './pages/settings';
import { Shell } from './shell';
import { StoreProvider, useStore } from './store';

function Outside() {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Outside this preview' }]}>
            <Card className="p-8 text-center">
                <p className="text-section-title">Outside this preview</p>
                <p className="text-subtle mt-1">That screen belongs to another package or module. Go back to carry on.</p>
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
        path === '/p09/contract' ? <ContractPage /> : path === '/emar/reports/builder' ? <BuilderPage /> : path === '/emar/errors' ? <ErrorsFrame /> : path === '/emar/settings' ? <SettingsPage /> : path.startsWith('/outside/') ? <Outside /> : <ReportsHub />;
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

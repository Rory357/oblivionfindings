/* eMAR P06 v1 — "Stock & pharmacy". Synthetic design preview.
 * Built with the app's real components (vite.config.mjs aliases @ to
 * resources/js); nothing here calls an application API. */
import './clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { ContractPage } from './pages/contract';
import { HubPage } from './pages/hub';
import { MedsTodayPage } from './pages/meds-today';
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const path = s.route.path;
    const page = path === '/p06/contract' ? <ContractPage /> : path === '/meds/today' ? <MedsTodayPage /> : <HubPage />;
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

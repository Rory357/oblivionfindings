/* eMAR P07a v1 — "Controlled checks (frontline)". Synthetic design preview.
 * Built with the app's real components (vite.config.mjs aliases @ to
 * resources/js); nothing here calls an application API. */
import './clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { ContractPage } from './pages/contract';
import { MedsTodayPage } from './pages/meds-today';
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const p = s.route.path;
    const page = p === '/p07a/contract' ? <ContractPage /> : <MedsTodayPage />;
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

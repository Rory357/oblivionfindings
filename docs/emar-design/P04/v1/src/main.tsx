/* eMAR P04 v1 — "Orders, changes & reconciliation". Synthetic design preview.
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
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const page = s.route.path === '/p04/contract' ? <ContractPage /> : <HubPage />;
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

/* eMAR P03 v1 — "Support & self-administration". Synthetic design preview.
 * Built with the app's real components (vite.config.mjs aliases @ to
 * resources/js); nothing here calls an application API. */
import './clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { ContractPage } from './pages/contract';
import { RecordPage } from './pages/record';
import { RegisterPage } from './pages/register';
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const p = s.route.path;
    const page = p === '/p03/contract' ? <ContractPage /> : p === '/emar/mar' ? <RecordPage /> : <RegisterPage />;
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

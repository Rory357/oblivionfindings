/* eMAR P07b v1 — "Controlled register, loss & destruction". Synthetic design
 * preview. Built with the app's real components (vite.config.mjs aliases @ to
 * resources/js); nothing here calls an application API. */
import './clock';
import './styles.css';
import { TooltipProvider } from '@/components/ui/tooltip';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DialogHost } from './host';
import { ContractPage } from './pages/contract';
import { RegisterPage } from './pages/register';
import { SafetyPage } from './pages/safety';
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const path = s.route.path;
    const page = path === '/p07b/contract' ? <ContractPage /> : path === '/emar/safety' ? <SafetyPage /> : path === '/emar/destructions' ? <RegisterPage forceView="destructions" /> : <RegisterPage />;
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

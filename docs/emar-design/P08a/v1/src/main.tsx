/* eMAR P08a v1 — "Follow-ups & handover". Synthetic design preview.
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
import { SafetyPage } from './pages/safety';
import { TasksPage } from './pages/tasks';
import { StoreProvider, useStore } from './store';

function Router() {
    const s = useStore();
    const p = s.route.path;
    const page = p === '/p08a/contract' ? <ContractPage /> : p === '/emar/safety' ? <SafetyPage /> : p === '/tasks' ? <TasksPage /> : <MedsTodayPage />;
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

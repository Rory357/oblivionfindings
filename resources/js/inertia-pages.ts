import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PageLoadError from './components/page-load-error';

const pageModules = import.meta.glob([
    './pages/**/*.tsx',
    '!./pages/**/*.test.tsx',
    '!./pages/**/*.spec.tsx',
]);

export async function resolveInertiaPage(name: string) {
    try {
        return await resolvePageComponent(`./pages/${name}.tsx`, pageModules);
    } catch (error) {
        // SSR failures must still reach the server's error reporting. In the
        // browser, a rejected dynamic import must not leave a blank workspace.
        if (typeof window === 'undefined') throw error;
        console.error('Unable to load this page module.', error);
        return { default: PageLoadError };
    }
}

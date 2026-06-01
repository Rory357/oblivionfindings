import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const pageModules = import.meta.glob([
    './pages/**/*.tsx',
    '!./pages/**/*.test.tsx',
    '!./pages/**/*.spec.tsx',
]);

export function resolveInertiaPage(name: string) {
    return resolvePageComponent(`./pages/${name}.tsx`, pageModules);
}

import base from '../../../../vite.config';
import path from 'node:path';
import type { PluginOption } from 'vite';

// Build-only inputs: another running task can regenerate the shared route tree.
// The frozen copies are byte-checked against generated source before the build.
const keepPlugin = (plugin: PluginOption): PluginOption => {
    if (Array.isArray(plugin)) return plugin.map(keepPlugin);
    return plugin && typeof plugin === 'object' && 'name' in plugin && plugin.name === '@laravel/vite-plugin-wayfinder' ? false : plugin;
};
export default {
    ...base,
    plugins: base.plugins?.map(keepPlugin),
    resolve: {
        ...base.resolve,
        alias: {
            '@/routes': path.resolve('storage/framework/myday-agenda-build-inputs/routes'),
            '@/actions': path.resolve('storage/framework/myday-agenda-build-inputs/actions'),
            ...base.resolve?.alias,
        },
    },
};

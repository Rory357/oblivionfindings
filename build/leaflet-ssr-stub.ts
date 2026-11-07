import type { Plugin } from 'vite';

const STUB_ID = '\0leaflet-ssr-stub';

export const LEAFLET_SSR_MESSAGE =
    'Leaflet only runs in the browser: call it from an effect or an event handler, not while rendering.';

/**
 * Leaflet 1.x reads `window` as soon as it is imported, so the server render
 * of every page with a map threw "window is not defined" and fell back to the
 * browser. Our map components only call Leaflet in effects and event handlers,
 * which never run on the server, so the server bundle gets a stand-in that
 * throws only if something calls it. The browser bundle is unchanged.
 */
export function leafletSsrStub(): Plugin {
    return {
        name: 'oblivion:leaflet-ssr-stub',
        enforce: 'pre',
        apply: (_config, env) => env.command === 'build' && !!env.isSsrBuild,
        resolveId: (id) => (id === 'leaflet' ? STUB_ID : null),
        load: (id) =>
            id === STUB_ID
                ? `const message = ${JSON.stringify(LEAFLET_SSR_MESSAGE)};
export default new Proxy({}, { get() { throw new Error(message); } });`
                : null,
    };
}

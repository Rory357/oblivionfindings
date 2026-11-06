import type { ConfigEnv } from 'vite';
import { describe, expect, it } from 'vitest';
import {
    LEAFLET_SSR_MESSAGE,
    leafletSsrStub,
} from '../../../build/leaflet-ssr-stub';

const plugin = leafletSsrStub();
const apply = plugin.apply as (config: object, env: ConfigEnv) => boolean;
const resolveId = plugin.resolveId as (id: string) => string | null;
const load = plugin.load as (id: string) => string | null;

describe('Leaflet stand-in for the server bundle', () => {
    it('applies only to the server build', () => {
        expect(
            apply(
                {},
                { command: 'build', mode: 'production', isSsrBuild: true },
            ),
        ).toBe(true);
        expect(apply({}, { command: 'build', mode: 'production' })).toBe(false);
        expect(apply({}, { command: 'serve', mode: 'development' })).toBe(
            false,
        );
    });

    it('replaces leaflet itself and leaves its stylesheet alone', () => {
        expect(resolveId('leaflet')).not.toBeNull();
        expect(resolveId('leaflet/dist/leaflet.css')).toBeNull();
        expect(load('leaflet')).toBeNull();
    });

    it('loads without a window and explains itself if something calls it', () => {
        const code = load(resolveId('leaflet')!)!;
        const stub = new Function(code.replace('export default', 'return'))();

        expect(() => stub.map).toThrow(LEAFLET_SSR_MESSAGE);
    });
});

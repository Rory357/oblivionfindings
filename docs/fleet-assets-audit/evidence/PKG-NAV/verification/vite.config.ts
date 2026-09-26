import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const fullCan = { fleet: { viewAny: true, reportsView: true }, assets: { viewAny: true, alertsView: true, telemetryView: true, trackersManage: true, geofencesManage: true }, hr: { assets: { view: true }, driver: { view: true } }, clients: { viewAny: true }, controlRoom: { viewAny: true }, securityDevices: { devicesView: true } };
export default defineConfig({
    root, cacheDir: '.fleet/pkg-nav/vite-cache',
    resolve: { alias: { '@': path.resolve(root, 'resources/js') } },
    server: { host: '127.0.0.1', port: 8876, strictPort: true, fs: { allow: [root, 'C:/Users/steph/Herd/oblivionfindings/node_modules'] } },
    plugins: [react(), tailwindcss(), { name: 'pkg-nav-read-only-fixture', configureServer(server) {
        server.middlewares.use(async (req, res, next) => {
            const url = req.url || '/';
            if (!/^\/(fleet-assets(?:[/?]|$)|dashboard|my-day|hr\/assets|operations\/clients|control-room|security-devices)/.test(url)) return next();
            if (req.method !== 'GET') { res.statusCode = 405; return res.end('Read-only fixture'); }
            const role = new URL(url, 'http://localhost').searchParams.get('fixture_role') || (req.headers.cookie || '').match(/pkg_nav_role=([^;]+)/)?.[1] || 'admin';
            res.setHeader('Set-Cookie', `pkg_nav_role=${role}; Path=/; SameSite=Lax`);
            const reportRoles: Record<string, unknown> = { 'report-only': { fleet: { reportsView: true } }, 'reports-assigned': { fleet: { reportsView: true }, assets: { viewAssigned: true } }, 'reports-assets': { fleet: { reportsView: true }, assets: { viewAny: true } }, 'global-reports': { reports: { viewAny: true } } };
            const can = reportRoles[role] ?? (role === 'assigned' ? { assets: { viewAssigned: true } } : role === 'staff' ? {} : role === 'assets' ? { assets: { viewAny: true } } : role === 'no-telemetry' ? { fleet: { viewAny: true } } : fullCan);
            const page = { component: 'NavigationFixture', url, version: 'pkg-nav-v1', props: { auth: { user: { id: 42, name: 'Navigation tester', email: 'fixture@example.test', role: 'Admin' }, can, portalClients: [] }, branding: { name: 'Oblivion Care' }, sidebarOpen: true } };
            if (req.headers['x-inertia']) { res.setHeader('X-Inertia', 'true'); res.setHeader('Content-Type', 'application/json'); return res.end(JSON.stringify(page)); }
            let html = fs.readFileSync(path.join(root, '.fleet/pkg-nav/index.html'), 'utf8').replace('<div id="app"></div>', `<div id="app"></div><script>window.__NAV_PAGE__=${JSON.stringify(page).replace(/</g, '\\u003c')}</script>`);
            html = await server.transformIndexHtml(url, html);
            res.setHeader('Content-Type', 'text/html'); res.end(html);
        });
    } }],
});

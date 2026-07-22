import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const tabs = resolve(root, 'resources/js/pages/sites/tabs');
const siteClients = resolve(root, 'resources/js/pages/sites/clients');

describe('site profile client workflow ownership', () => {
    it('uses the canonical full client wizard for creation', () => {
        const clientsTab = resolve(tabs, 'clients.tsx');
        const canonicalWizard = resolve(
            root,
            'resources/js/components/clients/add-client-dialog.tsx',
        );

        expect(existsSync(clientsTab)).toBe(true);
        const clientsSource = readFileSync(clientsTab, 'utf8');
        expect(clientsSource).toContain(
            '@/components/clients/add-client-dialog',
        );
        expect(clientsSource).toContain("only: ['clientsData']");
        expect(readFileSync(canonicalWizard, 'utf8')).toContain(
            'onSaved?.(savedClientId)',
        );
    });

    it('keeps existing-client placement in one focused dialog', () => {
        const placementDialog = resolve(siteClients, 'link-client-dialog.tsx');

        expect(existsSync(placementDialog)).toBe(true);
        const source = readFileSync(placementDialog, 'utf8');
        expect(source).toContain('service_context_id');
        expect(source).toContain('room_id');
        expect(source).toContain('key_worker_id');
        expect(source).toContain('/clients/link');
    });

    it('removes the duplicate quick-create form and endpoint', () => {
        const legacyDialogs = resolve(siteClients, '_dialogs.tsx');
        const routes = readFileSync(resolve(root, 'routes/assets.php'), 'utf8');

        expect(existsSync(legacyDialogs)).toBe(false);
        expect(routes).not.toContain("name('sites.clients.store')");
    });

    it('keeps room placement in one room assignment dialog', () => {
        const source = readFileSync(
            resolve(root, 'resources/js/pages/sites/rooms/_dialogs.tsx'),
            'utf8',
        );

        expect(source).toContain('export function AssignClientToRoomDialog');
        expect(source).not.toContain('AssignRoomToClientDialog');
        expect(source).not.toContain(
            'assigned_client_id: room.assigned_client',
        );
    });
});

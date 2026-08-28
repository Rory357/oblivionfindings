import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { StreamItem } from '../lib/stream-grouping';
import { StreamContextMenu } from './stream-context-menu';

function medicationItem(emarUrl: string | null): StreamItem {
    return {
        kind: 'med',
        at: '09:00',
        hr: 9,
        clientId: 12,
        data: {
            id: '34:2026-08-27T09:00:00+12:00',
            medication_id: 34,
            client_id: 12,
            client_name: 'Resident One',
            medication_name: 'Paracetamol',
            dose: '500 mg',
            route: 'Oral',
            is_controlled: false,
            can_record: true,
            can_give: true,
            scheduled_for: '2026-08-27T09:00:00+12:00',
            status: 'due',
            emar_url: emarUrl,
        },
    };
}

function renderMenu(canOpenEmar: boolean, emarUrl: string | null) {
    return render(
        <StreamContextMenu
            menu={{ item: medicationItem(emarUrl), x: 20, y: 20 }}
            canOpenEmar={canOpenEmar}
            onClose={vi.fn()}
            onAction={vi.fn()}
        />,
    );
}

describe('My Day medication context menu capability links', () => {
    it('shows the admin eMAR action only when capability and URL are both present', () => {
        const { unmount } = renderMenu(true, '/emar/mar?client_id=12');
        expect(screen.getByText('Open in eMAR')).toBeInTheDocument();
        unmount();

        renderMenu(false, null);
        expect(screen.queryByText('Open in eMAR')).not.toBeInTheDocument();
    });

    it('fails closed when the server omits the eMAR URL', () => {
        renderMenu(true, null);
        expect(screen.queryByText('Open in eMAR')).not.toBeInTheDocument();
    });
});

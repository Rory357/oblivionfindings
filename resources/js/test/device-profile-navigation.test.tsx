import type {
    DeviceProfileSection,
    DeviceProfileSectionKey,
} from '@/pages/security-devices/devices/device-profile';
import {
    DeviceProfileGroupNavigation,
    DeviceProfileNavigation,
} from '@/pages/security-devices/devices/device-profile-navigation';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

const allSections: DeviceProfileSection[] = [
    { key: 'health', label: 'Health', group: 'status' },
    { key: 'monitors', label: 'Monitors', group: 'status', count: 2 },
    { key: 'topology', label: 'Topology', group: 'technical' },
    {
        key: 'interfaces-sensors',
        label: 'Interfaces & sensors',
        group: 'technical',
    },
    { key: 'configuration', label: 'Configuration', group: 'technical' },
    { key: 'assignments', label: 'Assignments', group: 'operations' },
    { key: 'tickets', label: 'Tickets', group: 'operations', count: 1 },
    { key: 'events', label: 'Events', group: 'operations' },
    { key: 'maintenance', label: 'Maintenance', group: 'operations' },
    { key: 'documents', label: 'Documents', group: 'records' },
    { key: 'audit', label: 'Audit', group: 'records' },
];

function NavigationHarness({ sections }: { sections: DeviceProfileSection[] }) {
    const [active, setActive] = useState<DeviceProfileSectionKey>('health');

    return (
        <>
            <DeviceProfileGroupNavigation
                sections={sections}
                activeSection={active}
                onSectionChange={setActive}
                onSearch={() => undefined}
            />
            <DeviceProfileNavigation
                sections={sections}
                activeSection={active}
                onSectionChange={setActive}
                searchOpen={false}
                onSearchClose={() => undefined}
            />
        </>
    );
}

describe('DeviceProfileNavigation', () => {
    it('switches between four clear groups and selects the first section', () => {
        render(<NavigationHarness sections={allSections} />);

        expect(
            screen.getByRole('toolbar', { name: 'Device profile groups' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Health' })).toHaveAttribute(
            'aria-selected',
            'true',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Technology' }));
        expect(screen.getByRole('tab', { name: 'Topology' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByRole('tab', { name: 'Interfaces & sensors' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Operations' }));
        expect(
            screen.getByRole('tab', { name: 'Assignments' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(screen.getAllByRole('tab')).toHaveLength(4);
    });

    it('does not invent sections omitted by the server permission contract', () => {
        const restricted = allSections.filter((section) =>
            [
                'health',
                'topology',
                'configuration',
                'assignments',
                'documents',
            ].includes(section.key),
        );
        render(<NavigationHarness sections={restricted} />);

        expect(
            screen.queryByRole('tab', { name: /Monitors/ }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Technology' }));
        expect(
            screen.queryByRole('tab', { name: 'Interfaces & sensors' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Operations' }));
        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(
            screen.queryByRole('tab', { name: /Tickets/ }),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Records & governance' }),
        );
        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(
            screen.queryByRole('tab', { name: /Audit/ }),
        ).not.toBeInTheDocument();
    });
});

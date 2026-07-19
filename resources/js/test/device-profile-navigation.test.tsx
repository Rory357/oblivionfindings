import type {
    DeviceProfileSection,
    DeviceProfileSectionKey,
} from '@/pages/security-devices/devices/device-profile';
import { DeviceProfileNavigation } from '@/pages/security-devices/devices/device-profile-navigation';
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
        <DeviceProfileNavigation
            sections={sections}
            activeSection={active}
            onSectionChange={setActive}
        />
    );
}

describe('DeviceProfileNavigation', () => {
    it('switches between four clear groups and selects the first section', () => {
        render(<NavigationHarness sections={allSections} />);

        expect(
            screen.getByTestId('device-profile-mobile-select'),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('device-profile-section-health'),
        ).toHaveAttribute('aria-current', 'page');

        fireEvent.click(screen.getByTestId('device-profile-group-technical'));
        expect(
            screen.getByTestId('device-profile-section-topology'),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            screen.getByTestId('device-profile-section-interfaces-sensors'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('device-profile-group-operations'));
        expect(
            screen.getByTestId('device-profile-section-assignments'),
        ).toHaveAttribute('aria-current', 'page');
        expect(screen.getAllByTestId(/^device-profile-section-/)).toHaveLength(
            4,
        );
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
            screen.queryByTestId('device-profile-section-monitors'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('device-profile-group-technical'));
        expect(
            screen.queryByTestId('device-profile-section-interfaces-sensors'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('device-profile-group-operations'));
        expect(screen.getAllByTestId(/^device-profile-section-/)).toHaveLength(
            1,
        );
        expect(
            screen.queryByTestId('device-profile-section-tickets'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('device-profile-group-records'));
        expect(screen.getAllByTestId(/^device-profile-section-/)).toHaveLength(
            1,
        );
        expect(
            screen.queryByTestId('device-profile-section-audit'),
        ).not.toBeInTheDocument();
    });
});

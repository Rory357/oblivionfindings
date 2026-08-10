import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    ClientProfileAccessRequired,
    ControlRoomAlertAccessRequired,
    ControlRoomDestination,
    DeviceProfileAccessRequired,
    FleetTechnologyAccessRequired,
    HrEmployeeEquipmentAccessRequired,
    ItChangeDestination,
    ItWorkspaceAccessRequired,
    SiteProfileDestination,
} from './permission-destinations';

describe('Security & Devices permission destinations', () => {
    it('renders inaccessible alert context as icon and text without a link', () => {
        render(<ControlRoomAlertAccessRequired />);

        expect(screen.getByRole('note')).toBeInTheDocument();
        expect(
            screen.getByText('Control Room alert access required'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('links to Control Room only when its exact destination permission is present', () => {
        const { rerender } = render(<ControlRoomDestination canView={false} />);

        expect(
            screen.queryByRole('link', { name: /open control room/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Control Room access required'),
        ).toBeInTheDocument();

        rerender(<ControlRoomDestination canView />);

        expect(
            screen.getByRole('link', { name: /open control room/i }),
        ).toHaveAttribute('href', '/control-room');
    });

    it('links to the Site profile only when exact Site visibility is present', () => {
        const { rerender } = render(
            <SiteProfileDestination siteId={42} canView={false} />,
        );

        expect(
            screen.queryByRole('link', { name: /open site profile/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Site profile access required'),
        ).toBeInTheDocument();

        rerender(<SiteProfileDestination siteId={42} canView />);

        expect(
            screen.getByRole('link', { name: /open site profile/i }),
        ).toHaveAttribute('href', '/sites/42');

        rerender(
            <SiteProfileDestination siteId={42} canView tab="technology" />,
        );

        expect(
            screen.getByRole('link', { name: /open site profile/i }),
        ).toHaveAttribute('href', '/sites/42?tab=technology');
    });

    it('renders missing Client Profile access as an icon-and-text state without a link', () => {
        render(<ClientProfileAccessRequired />);

        expect(screen.getByRole('note')).toHaveTextContent(
            'Client Profile access required',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders missing Device Profile access as an icon-and-text state without a link', () => {
        render(<DeviceProfileAccessRequired />);

        expect(screen.getByRole('note')).toHaveTextContent(
            'Device Profile access required',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders missing HR equipment access as an icon-and-text state without a link', () => {
        render(<HrEmployeeEquipmentAccessRequired />);

        expect(screen.getByRole('note')).toHaveTextContent(
            'HR employee equipment access required',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders missing Fleet technology access as an icon-and-text state without a link', () => {
        render(<FleetTechnologyAccessRequired />);

        expect(screen.getByRole('note')).toHaveTextContent(
            'Fleet technology access required',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders missing IT workspace access as an icon-and-text state without a link', () => {
        render(<ItWorkspaceAccessRequired />);

        expect(screen.getByRole('note')).toHaveTextContent(
            'IT workspace access required',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('omits the IT Change link when the destination-safe record is absent', () => {
        const { rerender } = render(<ItChangeDestination change={null} />);

        expect(screen.queryByText(/IT Change:/i)).not.toBeInTheDocument();

        rerender(
            <ItChangeDestination
                change={{
                    id: 73,
                    reference: 'CHG-0073',
                    title: 'Upgrade the gateway',
                }}
            />,
        );

        expect(
            screen.getByRole('link', {
                name: /CHG-0073.*Upgrade the gateway/i,
            }),
        ).toHaveAttribute('href', '/it/changes/73');
    });
});

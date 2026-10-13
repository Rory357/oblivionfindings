import type { CctvSourceSetup } from './_source-contract';
export function sourceFixture(): CctvSourceSetup {
    return {
        actor_id: 7,
        camera_id: 42,
        camera: {
            id: 42,
            name: 'Main entrance',
            site: { id: 9, name: 'Harbour House' },
        },
        revision: 2,
        source: {
            revision: 2,
            enabled: true,
            recorder_device_id: 48,
            recorder_name: 'Main recorder',
            collector_id: 51,
            collector_name: 'Harbour collector',
            discovery_scope_id: 61,
            discovery_scope_name: 'Camera network',
            console_id: 'console-1',
            provider_camera_id: 'camera-1',
            stream_address: '10.20.30.5',
            stream_port: 7441,
            tls_server_name: 'recorder.example.test',
            tls_pin: 'a'.repeat(64),
            application_version: '7.3.53',
            verified_at: '2026-10-08T07:00:00Z',
            stream_state: 'unavailable',
            stream_verified_at: null,
        },
        choices: {
            recorders: {
                items: [{ id: 48, name: 'Main recorder' }],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
            collectors: {
                items: [
                    { id: 51, name: 'Harbour collector' },
                    { id: 52, name: 'Second collector' },
                ],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
            scopes: {
                items: [
                    { id: 61, name: 'Camera network', collector_id: 51 },
                    { id: 62, name: 'Second network', collector_id: 52 },
                ],
                limit: 20,
                next_href: null,
                previous_href: null,
            },
        },
        choice: null,
        q: '',
        collector_id: null,
    };
}

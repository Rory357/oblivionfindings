<?php

use App\Domain\SecurityDevices\Screens\DeviceKind;

// IT infrastructure files its recorders under "recording" (NVR, DVR, video server): they are
// recorders on the new pages, not "Other device".
it('counts IT infrastructure recorders as recorders', function (): void {
    foreach (['nvr_infrastructure', 'dvr_infrastructure', 'video_server'] as $subcategory) {
        expect(DeviceKind::from_('it_infrastructure', 'recording', $subcategory))->toBe(DeviceKind::Recorder);
    }
    expect(DeviceKind::from_('security', 'cctv', 'nvr'))->toBe(DeviceKind::Recorder)
        ->and(DeviceKind::from_('it_infrastructure', 'rack', 'patch_panel'))->toBe(DeviceKind::Other);
});

/**
 * The camera's Settings tab (drawing pg-sdr-4c): privacy (privacy hours, and the reason rule that is
 * always on), the camera's details, who can see its pictures, and Remove.
 *
 * Not built, on purpose: the privacy mask (no camera driver can send one to a camera yet) and
 * changing the recorder here (a camera's recorder comes from its camera source).
 */
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { PopupField } from '@/components/popup';
import { AlwaysOn, OnOff, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { FactRows, Note, QuietLine, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { privacyTime, rangesWords } from '@/lib/security-devices/camera-privacy';
import { router } from '@inertiajs/react';
import { Info, ShieldCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { CameraPageProps, CameraSettings } from './_camera-page-types';
import { MainSide } from './_device-page-parts';

type CameraForm = { name: string; siteId: string; location: string; address: string };

const formOf = (camera: CameraSettings['camera']): CameraForm => ({
    name: camera.name,
    siteId: camera.site_id ? String(camera.site_id) : '',
    location: camera.location,
    address: camera.address,
});

/** "Every day, 10:00 pm – 6:00 am · on now, until 6:00 am". */
export function privacyHint(privacy: CameraPageProps['privacy'], now: Date): string {
    if (!privacy.enabled || privacy.ranges.length === 0) {
        return privacy.ranges.length > 0
            ? `Off · kept for next time: ${rangesWords(privacy.ranges)}`
            : 'Turns the picture off on a schedule, so no picture is shown then';
    }
    const when = privacy.active && privacy.until
        ? ` · on now, until ${privacyTime(privacy.until, now)}`
        : privacy.next_start
          ? ` · next from ${privacyTime(privacy.next_start, now)}`
          : '';
    return `${rangesWords(privacy.ranges)}${when}`;
}

export function CameraSettingsTab({
    props,
    now,
    onEditPrivacy,
    onPrivacyOff,
    onRemove,
}: {
    props: CameraPageProps;
    now: Date;
    onEditPrivacy: () => void;
    onPrivacyOff: () => void;
    onRemove: () => void;
}) {
    const { device, can, privacy } = props;
    const settings = props.settings;
    const { form: options, load } = useDeviceFormOptions();
    const [form, setForm] = useState<CameraForm>(() => (settings ? formOf(settings.camera) : { name: '', siteId: '', location: '', address: '' }));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [turningOn, setTurningOn] = useState(false);

    const original = useMemo(() => (settings ? formOf(settings.camera) : null), [settings]);
    useEffect(() => {
        if (original) setForm(original);
    }, [original]);
    useEffect(() => {
        if (can.edit && can.assign) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [can.edit, can.assign]);

    if (!settings || !original) return null;
    const dirty = JSON.stringify(form) !== JSON.stringify(original);
    const set = <K extends keyof CameraForm>(key: K, value: CameraForm[K]) => {
        setForm((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: '' }));
    };

    const save = () => {
        if (!form.name.trim()) {
            setErrors({ name: 'Give it the name staff call it.' });
            return;
        }
        setSaving(true);
        const camera = settings.camera;
        router.put(
            `/security-devices/devices/${device.id}`,
            {
                _modal: true,
                name: form.name.trim(),
                domain: camera.domain,
                category: camera.category,
                subcategory: camera.subcategory,
                manufacturer: camera.manufacturer,
                model: camera.model,
                ip_address: form.address.trim() || null,
                location_description: form.location.trim() || null,
                notes: camera.notes,
                ...(can.assign ? { site_id: form.siteId ? Number(form.siteId) : null } : {}),
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => {
                    const mapped: Record<string, string> = {};
                    for (const [key, message] of Object.entries(serverErrors)) {
                        const field = key === 'ip_address' ? 'address' : key === 'site_id' ? 'siteId' : key === 'location_description' ? 'location' : key;
                        mapped[['name', 'address', 'siteId', 'location'].includes(field) ? field : 'form'] = String(message);
                    }
                    setErrors(mapped);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const togglePrivacy = (on: boolean) => {
        if (!on) {
            onPrivacyOff();
            return;
        }
        if (privacy.ranges.length === 0) {
            onEditPrivacy();
            return;
        }
        setTurningOn(true);
        router.put(
            `/security-devices/devices/${device.id}/camera/privacy-hours`,
            { enabled: true, ranges: privacy.ranges },
            { preserveScroll: true, onFinish: () => setTurningOn(false) },
        );
    };

    const main = (
        <>
            <Note icon={Info}>Cameras are never put in bedrooms or bathrooms. Inside shared spaces, use privacy hours.</Note>
            <SettingGroup icon={ShieldCheck} title="Privacy" caption="Applies to every picture Oblivion shows: camera tiles, the latest picture and live">
                <SettingRow
                    label="Privacy hours"
                    hint={privacyHint(privacy, now)}
                    control={
                        <span className="inline-flex items-center gap-3">
                            {can.privacy && privacy.ranges.length > 0 ? (
                                <Button type="button" variant="link" className="h-auto p-0" onClick={onEditPrivacy}>
                                    Edit times
                                </Button>
                            ) : null}
                            <OnOff label="Privacy hours" checked={privacy.enabled} disabled={!can.privacy || turningOn} onChange={togglePrivacy} />
                        </span>
                    }
                />
                <SettingRow
                    label="Ask for a reason before every live view"
                    hint="Always on for cameras at a home · kept with the viewer’s name"
                    control={<AlwaysOn />}
                />
            </SettingGroup>

            <Surface title="Camera">
                <div className="px-[18px] pt-3 pb-4">
                    <div className="grid gap-x-[18px] gap-y-3 sm:grid-cols-2">
                        <PopupField label="Name" htmlFor="sd-camera-name" error={errors.name}>
                            <Input id="sd-camera-name" value={form.name} disabled={!can.edit} onChange={(event) => set('name', event.target.value)} />
                        </PopupField>
                        <PopupField label="Site" error={errors.siteId}>
                            {can.edit && can.assign ? (
                                <VehicleSearchSelect
                                    label="Site"
                                    value={form.siteId}
                                    options={(options?.sites ?? []).map((site) => ({ value: String(site.id), label: site.name }))}
                                    onChange={(value) => set('siteId', value)}
                                    disabled={!options}
                                />
                            ) : (
                                <p className="flex h-9 items-center text-sm">{settings.camera.site ?? 'No Site'}</p>
                            )}
                        </PopupField>
                        <PopupField label="Spot" htmlFor="sd-camera-spot" error={errors.location}>
                            <Input
                                id="sd-camera-spot"
                                value={form.location}
                                placeholder="e.g. Front porch, above the door"
                                disabled={!can.edit}
                                onChange={(event) => set('location', event.target.value)}
                            />
                        </PopupField>
                        <PopupField label="Address" htmlFor="sd-camera-address" hint="IP address or hostname" error={errors.address}>
                            <Input id="sd-camera-address" value={form.address} disabled={!can.edit} onChange={(event) => set('address', event.target.value)} />
                        </PopupField>
                        <PopupField label="Recorder">
                            <p className="flex h-9 items-center text-sm">{settings.recorder ?? 'Not linked'}</p>
                        </PopupField>
                        <PopupField label="Login">
                            <p className="flex min-h-9 items-center text-sm">{settings.login}</p>
                        </PopupField>
                    </div>
                    {errors.form ? <p className="mt-3 text-sm text-status-critical">{errors.form}</p> : null}
                    {can.edit ? (
                        <div className="mt-4 flex justify-end gap-2.5">
                            {dirty ? (
                                <Button type="button" variant="outline" onClick={() => setForm(original)} disabled={saving}>
                                    Discard changes
                                </Button>
                            ) : null}
                            <Button type="button" onClick={save} disabled={!dirty || saving}>
                                {saving ? 'Saving…' : 'Save camera details'}
                            </Button>
                        </div>
                    ) : null}
                </div>
            </Surface>
        </>
    );

    const side = (
        <>
            <FactRows title="Who can view" rows={settings.who} />
            <QuietLine
                title="Remove camera"
                text="Its clip references and who watched it are kept."
                action={can.retire ? { label: 'Remove device', onClick: onRemove } : null}
            />
        </>
    );

    return <MainSide main={main} side={side} />;
}

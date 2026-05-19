<?php

namespace App\Http\Requests\Queclink;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('securityDevices.integrations.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command' => ['nullable', 'string', Rule::in([
                'server', 'sri', 'tracking', 'global', 'cfg', 'pin', 'dog',
                'time', 'tma', 'non_movement', 'nmd', 'power', 'pds',
                'wifi', 'wfi', 'geo', 'bluetooth', 'bt', 'bts', 'beacons',
                'bid', 'allowlist', 'wlt', 'firmware_update', 'upc',
                'firmware_version', 'fvr', 'cfg_alarm',
            ])],
            'auto_unlock_pin' => ['nullable', 'integer', 'between:0,1'],
            'pin' => ['nullable', 'string', 'max:8'],
            'mode' => ['nullable'],
            'reboot_interval' => ['nullable', 'integer', 'between:1,30'],
            'reboot_time' => ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3])[0-5][0-9]$/'],
            'report_before_reboot' => ['nullable', 'integer', 'between:0,1'],
            'unit' => ['nullable', 'integer', 'between:0,1'],
            'send_failure_timeout' => ['nullable', 'integer', 'between:0,1440'],
            'sign' => ['nullable', 'string', 'in:+,-'],
            'hour_offset' => ['nullable', 'integer', 'between:0,12'],
            'minute_offset' => ['nullable', 'integer', 'between:0,59'],
            'daylight_saving' => ['nullable', 'integer', 'between:0,1'],
            'utc_time' => ['nullable', 'string', 'regex:/^[0-9]{14}$/'],
            'sensor_enable' => ['nullable', 'integer', 'between:0,1'],
            'non_movement_duration' => ['nullable', 'integer', 'between:1,200'],
            'movement_duration' => ['nullable', 'integer', 'between:3,120'],
            'movement_threshold' => ['nullable', 'integer', 'between:2,9'],
            'rest_send_interval' => ['nullable', 'integer', 'between:5,1440'],
            'report_mode' => ['nullable', 'integer', 'between:0,7'],
            'safe_check' => ['nullable', 'integer', 'between:0,2'],
            'location_ignore' => ['nullable', 'integer', 'between:0,5'],
            'mask' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{1,8}$/'],
            'slot' => ['nullable', 'integer', 'between:0,19'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'radius' => ['nullable', 'integer', 'between:50,6000000'],
            'bluetooth_name' => ['nullable', 'string', 'max:26'],
            'discoverable_mode' => ['nullable', 'integer', 'in:0,8,9'],
            'discoverable_time' => ['nullable', 'integer', 'between:0,1440'],
            'advertising_interval' => ['nullable', 'integer', 'between:20,10000'],
            'advertising_data_type' => ['nullable', 'integer', 'between:0,1'],
            'enable' => ['nullable', 'integer', 'between:0,1'],
            'beacon_id_model' => ['nullable', 'integer', 'in:4,10'],
            'append_mask' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{1,4}$/'],
            'scan_interval' => ['nullable', 'integer', 'between:1,600'],
            'beacon_accessory_model' => ['nullable', 'string', 'max:12'],
            'mac_list' => ['nullable', 'array', 'max:4'],
            'mac_list.*' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{12}$/'],
            'send_interval' => ['nullable', 'integer', 'between:0,1440'],
            'lost_times' => ['nullable', 'integer', 'between:1,10'],
            'alarm_scan_interval' => ['nullable', 'integer', 'between:1,1440'],
            'start_index' => ['nullable', 'integer', 'between:1,20'],
            'end_index' => ['nullable', 'integer', 'between:1,20'],
            'entries' => ['nullable', 'array', 'max:20'],
            'entries.*' => ['nullable', 'string', 'max:32'],
            'phone_numbers' => ['nullable', 'array', 'max:10'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20'],
            'number_filter' => ['nullable', 'integer', 'between:0,1'],
            'phone_number_start' => ['nullable', 'integer', 'between:1,10'],
            'phone_number_end' => ['nullable', 'integer', 'between:1,10'],
            'max_download_retry' => ['nullable', 'integer', 'between:0,3'],
            'download_timeout_minutes' => ['nullable', 'integer', 'between:5,30'],
            'download_protocol' => ['nullable', 'integer', 'in:0,2'],
            'report_enable' => ['nullable', 'integer', 'between:0,1'],
            'update_interval_hours' => ['nullable', 'integer', 'between:0,8760'],
            'download_url' => ['nullable', 'url', 'max:100'],
            'extended_status_report' => ['nullable', 'integer', 'between:0,1'],
            'identifier_number' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{0,8}$/'],
            'configuration_name' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]*$/'],
            'configuration_version' => ['nullable', 'string', 'regex:/^[0-9]{0,4}$/'],
            'digital_signature' => ['nullable', 'string', 'max:32'],
        ];
    }
}

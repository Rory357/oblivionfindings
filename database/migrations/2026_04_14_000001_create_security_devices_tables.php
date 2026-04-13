<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────
        // Devices — Canonical hardware registry.
        // Every physical device in the organisation gets exactly one row.
        // Other modules (Sites, Fleet, Control Room, Client) consume this
        // table via device_assignments and device_asset_links.
        // ────────────────────────────────────────────────────────────────
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // ── Identity ──
            // Human-readable UID generated on creation (e.g. CAM-AKL-042).
            // Must be unique within a tenant for operational reference.
            $table->string('device_uid')->unique();
            $table->string('name');

            // ── Classification ──
            // domain + category + subcategory form the three-level taxonomy.
            // Strings (not enums) for extensibility — new subcategories
            // should not require a migration.
            $table->string('domain');       // security, tracking, iot_healthcare, it_infrastructure, facilities
            $table->string('category');     // alarm, cctv, access_control, vehicle_tracker, network, server, ...
            $table->string('subcategory')->nullable(); // pir_motion, dome_camera, wireless_ap, ...

            // ── Hardware descriptors ──
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('imei')->nullable();         // For cellular/GPS devices
            $table->string('asset_tag')->nullable();     // Organisation's physical label
            $table->string('firmware_version')->nullable();
            $table->string('ip_address')->nullable();    // For networked devices

            // ── Operational state ──
            // status = lifecycle state. health_status = current posture.
            $table->string('status')->default('active');        // active, offline, degraded, maintenance, decommissioned, in_stock, lost
            $table->string('health_status')->default('unknown'); // healthy, warning, critical, unknown
            $table->timestamp('last_seen_at')->nullable();      // Last heartbeat / check-in
            $table->timestamp('last_signal_at')->nullable();    // Last operational signal (distinct from heartbeat)
            $table->unsignedTinyInteger('battery_level')->nullable(); // 0–100, null if not battery-powered
            $table->timestamp('battery_updated_at')->nullable();

            // ── Lifecycle ──
            $table->date('commissioned_at')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->unsignedSmallInteger('expected_lifespan_months')->nullable();
            $table->decimal('purchase_price', 10, 2)->nullable(); // NZD — informational only, not the finance-authoritative value

            // ── Integration / provider ──
            // provider = integration adapter slug (unifi, queclink, hikvision, iot, manual).
            // external_ref = provider-specific identifiers for sync matching.
            $table->string('provider')->nullable();
            $table->json('external_ref')->nullable();

            // ── Flexible storage ──
            $table->json('config')->nullable(); // Device-specific configuration
            $table->json('meta')->nullable();   // Arbitrary metadata

            // ── Geo (optional, for devices with a fixed install position) ──
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_description')->nullable();

            $table->text('notes')->nullable();

            // ── Legacy bridge columns ──
            // Temporary FKs back to the three legacy tables for migration
            // traceability. Will be dropped after all consumers migrate.
            $table->unsignedBigInteger('legacy_location_hardware_id')->nullable();
            $table->unsignedBigInteger('legacy_control_room_device_id')->nullable();
            $table->unsignedBigInteger('legacy_asset_tracker_id')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            // Primary filter paths for listing pages and category pages.
            $table->index(['tenant_id', 'domain', 'status'], 'devices_tenant_domain_status_idx');
            $table->index(['tenant_id', 'category', 'status'], 'devices_tenant_category_status_idx');
            $table->index(['tenant_id', 'provider'], 'devices_tenant_provider_idx');
            $table->index(['tenant_id', 'health_status'], 'devices_tenant_health_idx');
            $table->index('serial_number');
            $table->index('mac_address');
            $table->index('imei');
            $table->index('legacy_location_hardware_id');
            $table->index('legacy_control_room_device_id');
            $table->index('legacy_asset_tracker_id');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Assignments — Who or where a device is assigned to.
        // A device has at most ONE active assignment (released_at IS NULL).
        // Historical assignments are kept for audit trail.
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();

            // Polymorphic target: site, room, vehicle, staff, client, pool.
            // Using strings (not a morphs() helper) for explicit control.
            $table->string('assignable_type');   // site, room, vehicle, staff, client
            $table->unsignedBigInteger('assignable_id');

            $table->string('assignment_type')->default('permanent'); // permanent, temporary, loan, shared

            $table->timestamp('assigned_at');
            $table->timestamp('expected_return_at')->nullable(); // For loans / temporary assignments
            $table->timestamp('released_at')->nullable();        // NULL = currently active

            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Required when assignable_type=client for tracking devices (NZ privacy).
            $table->foreignId('consent_id')->nullable()->constrained('client_consents')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ──
            // "Active assignment for this device" — the most common lookup.
            $table->index(['device_id', 'released_at'], 'dev_assign_device_active_idx');

            // "All devices assigned to this entity" — used by Sites, Fleet, Client modules.
            $table->index(['assignable_type', 'assignable_id', 'released_at'], 'dev_assign_target_active_idx');

            // Overdue loan detection.
            $table->index(['assignment_type', 'expected_return_at', 'released_at'], 'dev_assign_loan_overdue_idx');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Asset Links — Pivot between devices and assets.
        // A device can be linked to an asset (link_type=primary means the
        // device IS that asset; installed_in means it's attached to it).
        // Supports many devices per asset (e.g. vehicle with tracker +
        // dashcam + tablet) and optional linking (many devices have no
        // asset, many assets have no device).
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_asset_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // primary    = the device IS this asset (server, camera, UPS)
            // installed_in = the device is installed in/on this asset (tracker in vehicle)
            // accessory  = the device is a peripheral/accessory of this asset
            $table->string('link_type')->default('primary');

            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable(); // NULL = active link

            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Only one active link per device↔asset pair.
            // Composite unique allows re-linking after unlinking (unlinked_at changes).
            // Note: MySQL treats NULLs as distinct in unique indexes, so
            // (device_id=1, asset_id=1, unlinked_at=NULL) is allowed alongside
            // (device_id=1, asset_id=1, unlinked_at=2026-04-01). This is correct.
            $table->unique(['device_id', 'asset_id', 'unlinked_at'], 'dev_asset_link_unique');

            // "All devices linked to this asset" — used by Fleet and Asset Management.
            $table->index(['asset_id', 'unlinked_at'], 'dev_asset_link_asset_active_idx');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Relationships — Device-to-device topology.
        // Models physical/logical connections: camera→NVR, AP→switch,
        // device→UPS, panel→sensors, switch→router.
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('child_device_id')->constrained('devices')->cascadeOnDelete();

            // Relationship semantics (child is [type] parent):
            // records_to   → camera records to NVR
            // powered_by   → device powered by UPS/PDU
            // connected_to → AP/camera connected to switch
            // mounted_in   → device mounted in rack
            // controls     → alarm panel controls sensor/siren
            // uplinks_to   → switch uplinks to router/firewall
            // backs_up_to  → server backs up to NAS/appliance
            $table->string('relationship_type');

            $table->string('port')->nullable();  // Physical port/channel (e.g. "PoE Port 12", "HDMI 1")
            $table->text('notes')->nullable();
            $table->timestamps();

            // One relationship of each type between a pair of devices.
            $table->unique(
                ['parent_device_id', 'child_device_id', 'relationship_type'],
                'dev_rel_pair_type_unique'
            );

            // "What is connected to this device?" — topology traversal.
            $table->index('child_device_id', 'dev_rel_child_idx');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Groups — Logical grouping of devices.
        // Used for bulk operations, reporting, and organisational views.
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('type')->default('custom'); // location, functional, vendor, maintenance, custom
            $table->text('description')->nullable();
            $table->json('auto_rules')->nullable(); // For future auto-membership (deferred)
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name'], 'dev_groups_tenant_name_unique');
        });

        Schema::create('device_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_group_id')->constrained('device_groups')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['device_group_id', 'device_id'], 'dev_group_member_unique');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Events — Raw event log for all device activity.
        // Append-only. Feeds into the Control Room signal pipeline but
        // is owned by Security & Devices for device-centric reporting.
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();

            // Free-form event type for extensibility. Common values:
            // heartbeat, alarm_trigger, motion_detected, door_opened,
            // door_closed, battery_low, offline, online, tamper,
            // firmware_updated, maintenance_due, signal, config_changed.
            $table->string('event_type');
            $table->string('severity')->default('info'); // info, warning, critical

            $table->json('payload')->nullable();   // Event-specific data
            $table->string('source')->nullable();  // Integration provider slug or 'manual'

            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable(); // When signal pipeline consumed it

            // No updated_at — events are immutable after creation.
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ──
            // Device event timeline.
            $table->index(['device_id', 'occurred_at'], 'dev_events_device_time_idx');

            // Event type queries (e.g. "all alarm_trigger events in last 24h").
            $table->index(['event_type', 'occurred_at'], 'dev_events_type_time_idx');

            // Unprocessed events for signal pipeline consumption.
            $table->index(['processed_at', 'occurred_at'], 'dev_events_unprocessed_idx');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Maintenance Records — Device-specific maintenance.
        // This is for DEVICE operations: firmware updates, calibration,
        // connectivity checks, sensor recalibration.
        // Physical asset repairs go in asset_maintenance_logs (Asset Mgmt).
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();

            $table->string('type');    // scheduled_service, repair, firmware_update, inspection, replacement, calibration, connectivity_check
            $table->string('status')->default('scheduled'); // scheduled, in_progress, completed, cancelled

            $table->text('description');
            $table->date('scheduled_for')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor_reference')->nullable(); // External work order number

            // Cost is informational here. The authoritative financial
            // record flows through FinFinancialEvent → FinCostAllocation.
            $table->decimal('cost', 10, 2)->nullable(); // NZD
            $table->text('notes')->nullable();
            $table->timestamps();

            // Upcoming/overdue maintenance queries.
            $table->index(['status', 'scheduled_for'], 'dev_maint_status_sched_idx');

            // Device maintenance history.
            $table->index(['device_id', 'completed_at'], 'dev_maint_device_completed_idx');
        });

        // ────────────────────────────────────────────────────────────────
        // Device Documents — Technical documentation for devices.
        // Manuals, install photos, compliance certificates, firmware notes.
        // Financial documents (invoices, warranties, contracts) belong to
        // asset_documents in Asset Management.
        // ────────────────────────────────────────────────────────────────
        Schema::create('device_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('category');  // manual, install_photo, compliance_cert, firmware_notes, configuration, network_diagram, other
            $table->string('version')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable(); // For compliance certs that expire

            // File storage (matches asset_documents pattern).
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');

            $table->text('notes')->nullable();
            $table->timestamps();

            // "All documents for this device" — detail page documents tab.
            $table->index('device_id', 'dev_docs_device_idx');

            // Expiring compliance certs.
            $table->index(['category', 'expiry_date'], 'dev_docs_expiry_idx');
        });
    }

    public function down(): void
    {
        // Drop in reverse dependency order.
        Schema::dropIfExists('device_documents');
        Schema::dropIfExists('device_maintenance_records');
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('device_group_members');
        Schema::dropIfExists('device_groups');
        Schema::dropIfExists('device_relationships');
        Schema::dropIfExists('device_asset_links');
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('devices');
    }
};

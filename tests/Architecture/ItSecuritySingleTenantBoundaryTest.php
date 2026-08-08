<?php

it('keeps active IT Security Devices and Monitoring partition behavior at absolute zero', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $actual = itSecurityTenantDebtSnapshot($root);

    expect($actual)->toBe([])
        ->and(itSecurityApprovedTenantDebt())->toBe([]);
});

it('keeps the Fleet dashboard on canonical Site and Security Devices projections', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $contents = file_get_contents($root.'/app/Http/Controllers/FleetAssets/DashboardController.php');

    expect($contents)->toBeString()
        ->and($contents)->toContain('SecurityDevicesAccessService')
        ->and($contents)->toContain('visibleDevices($user)')
        ->and($contents)->not->toMatch('/\b(?:tenant_id|organization_id|organisation_id)\b/u')
        ->and($contents)->not->toContain("'payload' => \$s->payload")
        ->and($contents)->not->toContain("'trackers' =>");
});

it('keeps native monitoring to IT independent of the Control Room Device projection', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $observer = file_get_contents($root.'/app/Observers/DeviceEventObserver.php');
    $links = file_get_contents($root.'/app/Domain/It/Services/ItTicketLinkService.php');
    $signals = file_get_contents($root.'/app/Services/ControlRoom/SignalProcessingService.php');

    expect($observer)->toBeString()
        ->and($observer)->toContain('CanonicalDeviceSiteResolver')
        ->and($observer)->toContain("'site_id' => \$siteId")
        ->and($links)->toBeString()
        ->and($links)->toContain('authoritativeCanonicalDeviceId')
        ->and($links)->not->toContain('\\App\\Models\\ControlRoom\\Device::query()')
        ->and($signals)->toBeString()
        ->and($signals)->toContain('context->normalized_data->canonical_device_id');
});

it('keeps IT report Device metrics behind canonical Security and Devices visibility', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItReportsController.php');

    expect($controller)->toBeString()
        ->and($controller)->toContain("canDo('securityDevices.devices.view')")
        ->and($controller)->toContain('visibleDevices($user)')
        ->and($controller)->toContain("where('work_type', 'incident')")
        ->and($controller)->toContain("distinct('ticket_id')")
        ->and($controller)->not->toContain('Device::query()');
});

it('keeps Device health monitoring out of the retained Control Room projection', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $consoleSchedule = file_get_contents($root.'/routes/console.php');
    $facilitySignals = file_get_contents($root.'/app/Services/Facility/FacilitySignalService.php');
    $projectionModel = file_get_contents($root.'/app/Models/ControlRoom/Device.php');

    expect($consoleSchedule)
        ->not->toContain('DetectCrDeviceOfflineJob')
        ->and($facilitySignals)
        ->not->toContain('App\\Models\\ControlRoom\\Device')
        ->not->toContain('emitDeviceOffline')
        ->not->toContain('cr_device_offline')
        ->and($projectionModel)
        ->not->toContain('DetectCrDeviceOfflineJob monitors this table')
        ->not->toMatch('/function scope(?:Online|Offline|Stale|LowBattery)\\b/')
        ->not->toMatch('/function (?:markOnline|markOffline|updateBattery|isOnline|isStale|hasLowBattery)\\b/')
        ->not->toContain("'status' => 'online'");
});

it('keeps Control Room Device pages focused on signal activity instead of duplicate Device health', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/ControlRoom/ControlRoomDeviceController.php');
    $presenter = file_get_contents($root.'/app/Services/ControlRoom/ControlRoomDevicePresenter.php');
    $sidebar = file_get_contents($root.'/resources/js/components/app-sidebar.tsx');
    $index = file_get_contents($root.'/resources/js/pages/control-room/devices/index.tsx');

    expect($controller)
        ->not->toContain("where('control_room_devices.status'")
        ->not->toContain('->lowBattery(')
        ->not->toContain("'online' =>")
        ->not->toContain("'offline' =>")
        ->not->toContain("'low_battery' =>")
        ->and($presenter)
        ->not->toContain("'status' => \$projection->status")
        ->not->toContain('isStale(')
        ->and($sidebar)
        ->toContain("title: 'Device signals'")
        ->and($index)
        ->toContain('Open Device registry')
        ->not->toContain('Signal offline')
        ->not->toContain('Low Battery');
});

it('keeps Security and Devices command batch navigation free of inert hash targets', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $batchPage = file_get_contents($root.'/resources/js/pages/security-devices/command-batches/show.tsx');

    expect($batchPage)
        ->not->toContain("href: '#'")
        ->not->toContain('href="#"');
});

it('keeps Device assignment history in the canonical Device profile payload only', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $routes = file_get_contents($root.'/routes/security-devices.php');
    $assignmentController = file_get_contents($root.'/app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php');
    $deviceController = file_get_contents($root.'/app/Domain/SecurityDevices/Http/Controllers/DeviceController.php');
    $deviceProfile = file_get_contents($root.'/resources/js/pages/security-devices/devices/show.tsx');

    expect($routes)
        ->not->toContain("Route::get('/devices/{device}/assignments'")
        ->and($assignmentController)
        ->not->toContain('function history(')
        ->and($deviceController)
        ->toContain("'assignmentHistory' =>")
        ->and($deviceProfile)
        ->toContain('assignmentHistory.map(');
});

it('keeps active IT and Security Devices surfaces free of known inert interaction patterns', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $directories = [
        $root.'/app/Http/Controllers/It',
        $root.'/app/Domain/It',
        $root.'/app/Domain/SecurityDevices',
        $root.'/resources/js/pages/it',
        $root.'/resources/js/pages/security-devices',
        $root.'/resources/js/components/it',
        $root.'/resources/js/components/security-devices',
    ];
    $patterns = [
        'hash JSX target' => '/href\s*=\s*[\'\"]#[\'\"]/u',
        'hash object target' => '/href\s*:\s*[\'\"]#[\'\"]/u',
        'empty click handler' => '/onClick\s*=\s*\{\s*\(\s*\)\s*=>\s*\{\s*\}\s*\}/u',
        'browser-native dialog' => '/\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/u',
        'coming-soon control' => '/\bcoming\s+soon\b/iu',
        'mock-only data' => '/\b(?:mock|sample|demo)\s+(?:data|dashboard)\b/iu',
        'unimplemented control' => '/\bnot\s+implemented\b/iu',
        'deferred implementation marker' => '/\b(?:TODO|FIXME)\b/u',
    ];
    $findings = [];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (! $file->isFile()
                || ! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)
                || preg_match('/\.(?:test|spec)\.[^.]+$/u', $file->getFilename()) === 1) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $scannable = preg_replace('/\/\*.*?\*\//su', '', (string) $contents) ?? (string) $contents;
            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $scannable) === 1) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $findings[] = "{$relative}: {$label}";
                }
            }
        }
    }

    expect($findings)->toBe([]);
});

it('keeps Queclink governed actions safe when no tracker is paired', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $hub = file_get_contents($root.'/resources/js/pages/security-devices/integrations/queclink-hub.tsx');

    expect($hub)
        ->toContain('if (!target) return;')
        ->not->toContain('target!.id');
});

it('keeps IT provisioning lifecycle mutations serialized and centrally audited', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $lifecycle = file_get_contents($root.'/app/Domain/It/Services/ItProvisioningRequestLifecycleService.php');

    expect($controller)
        ->not->toContain("\$provisioning->update(['status' => 'cancelled'])")
        ->not->toContain('bulkAssignProvisioning(')
        ->and($lifecycle)
        ->toContain('lockForUpdate()')
        ->toContain('it.provisioning.request.assigned')
        ->toContain('it.provisioning.request.cancelled');
});

it('keeps ticket approvals serialized and centrally audited', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $servicePath = $root.'/app/Domain/It/Services/ItTicketApprovalService.php';
    $service = is_file($servicePath) ? file_get_contents($servicePath) : '';
    $ticketPage = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');
    $ticketPageNormalized = preg_replace('/\s+/', ' ', (string) $ticketPage);
    $approvalControls = file_get_contents($root.'/resources/js/components/it/ticket-approval-controls.tsx');

    expect($controller)
        ->not->toContain('$ticket->approvals()->create(')
        ->not->toContain('$approval->forceFill(')
        ->and($service)
        ->toContain('lockForUpdate()')
        ->toContain('it.ticket.approval.requested')
        ->toContain('it.ticket.approval.approved')
        ->toContain('it.ticket.approval.rejected')
        ->and($ticketPageNormalized)
        ->toContain('Back to IT &amp; Support')
        ->not->toContain('Back to IT &amp; Provisioning')
        ->and($approvalControls)
        ->toContain('Request manager approval')
        ->toContain('Reason for rejection')
        ->toContain('min-h-11');
});

it('keeps ticket merges private serialized and centrally audited', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $request = file_get_contents($root.'/app/Http/Requests/It/MergeTicketRequest.php');
    $servicePath = $root.'/app/Domain/It/Services/ItTicketMergeService.php';
    $service = is_file($servicePath) ? file_get_contents($servicePath) : '';

    expect($controller)
        ->not->toContain('$ticket->comments()->update(')
        ->toContain("where('requester_user_id', \$ticket->requester_user_id)")
        ->and($request)
        ->toContain("'reason' => ['required'")
        ->and($service)
        ->toContain("orderBy('id')")
        ->toContain('lockForUpdate()')
        ->toContain('Tickets with different requesters cannot be merged')
        ->toContain('it.ticket.merged');
});

it('keeps ticket triage serialized approval safe and reasoned', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $ticketController = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $provisioningController = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $updateRequestPath = $root.'/app/Http/Requests/It/UpdateTicketRequest.php';
    $closeRequestPath = $root.'/app/Http/Requests/It/CloseTicketRequest.php';
    $servicePath = $root.'/app/Domain/It/Services/ItTicketTriageService.php';
    $closeDialogPath = $root.'/resources/js/components/it/ticket-close-dialog.tsx';
    $updateRequest = is_file($updateRequestPath) ? file_get_contents($updateRequestPath) : '';
    $closeRequest = is_file($closeRequestPath) ? file_get_contents($closeRequestPath) : '';
    $service = is_file($servicePath) ? file_get_contents($servicePath) : '';
    $closeDialog = is_file($closeDialogPath) ? file_get_contents($closeDialogPath) : '';

    expect($ticketController)
        ->not->toContain('private function bulkAssign(')
        ->not->toContain('private function bulkPriority(')
        ->and($provisioningController)
        ->not->toContain('$ticket->fill($update)')
        ->and($updateRequest)
        ->toContain("'category' => ['sometimes'")
        ->and($closeRequest)
        ->toContain("'reason' => ['required'")
        ->and($service)
        ->toContain('lockForUpdate()')
        ->toContain('categoryNeedsApproval')
        ->toContain('it.ticket.triage.updated')
        ->toContain('it.ticket.closed')
        ->and($closeDialog)
        ->toContain('Reason for closing')
        ->toContain('reason appears on every ticket timeline')
        ->toContain('min-h-11');
});

it('keeps ticket conversations feedback and watchers serialized and centrally audited', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $servicePath = $root.'/app/Domain/It/Services/ItTicketInteractionService.php';
    $service = is_file($servicePath) ? file_get_contents($servicePath) : '';
    $ticketThread = file_get_contents($root.'/resources/js/components/it/ticket-thread.tsx');
    $reopenRequest = file_get_contents($root.'/app/Http/Requests/It/ReopenTicketRequest.php');
    $reopenDialog = file_get_contents($root.'/resources/js/components/it/ticket-reopen-dialog.tsx');
    $queue = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $workspace = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');

    expect($controller)
        ->not->toContain('$ticket->comments()->create(')
        ->not->toContain('$ticket->csat_score =')
        ->not->toContain('$ticket->watchers()->syncWithoutDetaching(')
        ->not->toContain('$ticket->watchers()->detach(')
        ->and($service)
        ->toContain('lockForUpdate()')
        ->toContain('it.ticket.comment.added')
        ->toContain('it.ticket.csat.submitted')
        ->toContain('it.ticket.csat.updated')
        ->toContain('it.ticket.watcher.added')
        ->toContain('it.ticket.watcher.removed')
        ->toContain('reopenWithReason')
        ->toContain("AuditLogger::logOrFail('it.ticket.reopened'")
        ->toContain("'is_internal' => ! \$isRequester")
        ->and($ticketThread)
        ->toContain('This conversation is read-only')
        ->toContain('replyUnavailableReason')
        ->and($reopenRequest)
        ->toContain("'reason' => ['required', 'string', 'min:5', 'max:2000']")
        ->and($reopenDialog)
        ->toContain('What still needs attention?')
        ->toContain('recorded as an internal note')
        ->toContain('min-h-11')
        ->not->toContain('window.confirm(')
        ->not->toContain('window.alert(')
        ->and($queue)
        ->toContain('<TicketReopenDialog')
        ->toContain("label: 'Close ticket…'")
        ->not->toContain("act('post', `/it/tickets/\${t.id}/reopen`)")
        ->not->toContain("act('post', `/it/tickets/\${t.id}/close`)")
        ->and($workspace)
        ->toContain('<TicketReopenDialog')
        ->not->toContain('`/it/tickets/${ticket.id}/reopen`');
});

it('keeps ticket intake resolution and lifecycle evidence centrally governed', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $intakePath = $root.'/app/Domain/It/Services/ItTicketIntakeService.php';
    $intake = is_file($intakePath) ? file_get_contents($intakePath) : '';
    $attachmentsPath = $root.'/app/Domain/It/Services/ItAttachmentStorageService.php';
    $attachments = is_file($attachmentsPath) ? file_get_contents($attachmentsPath) : '';
    $interaction = file_get_contents($root.'/app/Domain/It/Services/ItTicketInteractionService.php');
    $transitions = file_get_contents($root.'/app/Domain/It/Services/ItWorkTransitionService.php');
    $requestPath = $root.'/app/Http/Requests/It/StoreItTicketRequest.php';
    $request = is_file($requestPath) ? file_get_contents($requestPath) : '';

    expect($controller)
        ->not->toContain('ItTicket::createWithReference([')
        ->not->toContain('$ticket->watchers()->syncWithoutDetaching(')
        ->not->toContain('$transitioned->comments()->create([')
        ->and($intake)
        ->toContain('DB::transaction(')
        ->toContain('lockForUpdate()')
        ->toContain('it.ticket.created')
        ->and($attachments)
        ->toContain('Storage::disk(ItAttachment::DISK)->delete')
        ->and($interaction)
        ->toContain('resolveWithPublicNote')
        ->toContain('it.ticket.resolved')
        ->and($transitions)
        ->toContain('it.work.transitioned')
        ->and($request)
        ->toContain("'attachments.*'")
        ->toContain('ItAttachment::ALLOWED_MIMES');
});

it('keeps ordinary ticket intake linked to canonical Security and Devices records', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $intake = file_get_contents($root.'/app/Domain/It/Services/ItTicketIntakeService.php');
    $deviceContext = file_get_contents($root.'/app/Domain/It/Services/ItTicketDeviceContextService.php');
    $linkedOptions = file_get_contents($root.'/app/Domain/It/Services/ItLinkedContextOptions.php');
    $ticketController = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $request = file_get_contents($root.'/app/Http/Requests/It/StoreItTicketRequest.php');
    $wizard = file_get_contents($root.'/resources/js/components/it/it-wizards.tsx');
    $linkedContext = file_get_contents($root.'/resources/js/components/it/ticket-linked-context.tsx');
    $page = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $routes = file_get_contents($root.'/routes/web.php');

    expect($controller)
        ->toContain("'siteOptions' =>")
        ->toContain("'deviceOptions' =>")
        ->and($request)
        ->toContain("'device_id' => ['nullable'")
        ->and($intake)
        ->toContain('assertAvailableInScope')
        ->toContain('linkAtIntake')
        ->not->toContain('->links()->create(')
        ->and($deviceContext)
        ->toContain("'affected_device'")
        ->toContain("'canonical_owner' => 'security_devices'")
        ->toContain('visibleDevices($actor)')
        ->toContain('resolveForContext')
        ->toContain('context_linked')
        ->toContain('context_unlinked')
        ->toContain('MONITORING_PRINCIPAL')
        ->toContain('it.ticket.device.linked')
        ->toContain('it.ticket.device.unlinked')
        ->and($linkedOptions)
        ->toContain("'site_id' => \$siteId")
        ->toContain('resolveLoadedForContext')
        ->and($ticketController)
        ->toContain('ItTicketDeviceContextService')
        ->not->toContain('->links()->create(')
        ->and($routes)
        ->toContain("name('it.tickets.devices.store')")
        ->toContain("name('it.tickets.devices.destroy')")
        ->and($wizard)
        ->toContain('Affected Device')
        ->toContain('Ticket Site')
        ->toContain('site_id')
        ->toContain('candidate.site_id === Number(form.data.site_id)')
        ->toContain('form.data.site_id !== UNASSIGNED')
        ->toContain('canonical Security & Devices record')
        ->toContain('siteOptions={siteOptions}')
        ->toContain('deviceOptions={deviceOptions}')
        ->and($linkedContext)
        ->toContain('Add affected Device')
        ->toContain('Monitoring evidence')
        ->toContain('Remove link')
        ->and($page)
        ->toContain('siteOptions?: SiteOption[]')
        ->toContain('siteOptions={siteOptions}')
        ->toContain('deviceOptions?: DeviceOption[]')
        ->toContain('deviceOptions={deviceOptions}');
});

it('keeps ticket work tasks visible and governed from the canonical workspace', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $routes = file_get_contents($root.'/routes/web.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItWorkTaskController.php');
    $service = file_get_contents($root.'/app/Domain/It/Services/ItWorkTaskService.php');
    $presenter = file_get_contents($root.'/app/Domain/It/Presenters/ItTicketContextPresenter.php');
    $workspace = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');
    $tasks = file_get_contents($root.'/resources/js/components/it/ticket-work-tasks.tsx');

    expect($routes)
        ->toContain("name('it.tickets.tasks.store')")
        ->toContain("name('it.tickets.tasks.update')")
        ->toContain("name('it.tickets.tasks.complete')")
        ->toContain("name('it.tickets.tasks.reopen')")
        ->and($controller)
        ->toContain('ItWorkTaskService')
        ->not->toContain('ItWorkTask::create(')
        ->not->toContain('->forceFill(')
        ->and($service)
        ->toContain('lockForUpdate()')
        ->toContain('Reopen this ticket before changing its work tasks.')
        ->toContain('Required tasks cannot be cancelled.')
        ->toContain('it.ticket.task.created')
        ->toContain('it.ticket.task.updated')
        ->toContain('it.ticket.task.completed')
        ->toContain('it.ticket.task.reopened')
        ->and($presenter)
        ->toContain("'tasks' => \$this->presentTasks(\$ticket, \$viewer)")
        ->toContain("'dependencies' => \$task->dependencies")
        ->and($workspace)
        ->toContain('tasks: TicketWorkTask[]')
        ->toContain('<TicketWorkTasks')
        ->toContain('tasks={linked_context.tasks}')
        ->and($tasks)
        ->toContain('Work tasks')
        ->toContain('Required before settlement')
        ->toContain('Evidence references')
        ->toContain('/it/tickets/${ticketId}/tasks')
        ->not->toContain('window.confirm(')
        ->not->toContain('window.alert(');
});

it('keeps specialised IT work auditable and requester activity minimum necessary', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $activity = file_get_contents($root.'/app/Domain/It/Presenters/ItTicketActivityPresenter.php');
    $problem = file_get_contents($root.'/app/Domain/It/Services/ItProblemService.php');
    $change = file_get_contents($root.'/app/Domain/It/Services/ItChangeService.php');
    $majorIncident = file_get_contents($root.'/app/Domain/It/Services/ItMajorIncidentService.php');
    $thread = file_get_contents($root.'/resources/js/components/it/ticket-thread.tsx');

    expect($controller)
        ->toContain('ItTicketActivityPresenter')
        ->toContain("'events' => \$this->activityPresenter->present(\$ticket, \$user)")
        ->not->toContain('$events = $ticket->events()')
        ->and($activity)
        ->toContain('REQUESTER_VISIBLE_TYPES')
        ->toContain('canWork($viewer, $ticket)')
        ->toContain('publicPayload')
        ->not->toContain("'work_task_created'")
        ->not->toContain("'routing_applied'")
        ->not->toContain("'context_linked'")
        ->and($problem)
        ->toContain("AuditLogger::logOrFail('it.problem.created'")
        ->toContain("AuditLogger::logOrFail('it.problem.updated'")
        ->and($change)
        ->toContain("AuditLogger::logOrFail('it.change.created'")
        ->toContain("AuditLogger::logOrFail('it.change.updated'")
        ->and($majorIncident)
        ->toContain("AuditLogger::logOrFail('it.major_incident.created'")
        ->toContain("AuditLogger::logOrFail('it.major_incident.updated'")
        ->toContain("AuditLogger::logOrFail('it.major_incident.update.published'")
        ->and($thread)
        ->toContain("case 'workflow_transitioned':")
        ->toContain("case 'problem_updated':")
        ->toContain("case 'change_updated':")
        ->toContain("case 'major_incident_update_published':")
        ->toContain("case 'approval_requested':")
        ->toContain("case 'routing_applied':")
        ->toContain("case 'merged':");
});

it('keeps waiting ownership explicit governed and requester safe', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $queueController = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $transition = file_get_contents($root.'/app/Domain/It/Services/ItWorkTransitionService.php');
    $triage = file_get_contents($root.'/app/Domain/It/Services/ItTicketTriageService.php');
    $bulkRequest = file_get_contents($root.'/app/Http/Requests/It/BulkTicketActionRequest.php');
    $updateRequest = file_get_contents($root.'/app/Http/Requests/It/UpdateTicketRequest.php');
    $workspace = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');
    $queue = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $drawer = file_get_contents($root.'/resources/js/components/it/ticket-drawer.tsx');
    $dialog = file_get_contents($root.'/resources/js/components/it/ticket-waiting-dialog.tsx');

    expect($controller)
        ->toContain("'waiting' => \$this->waitingPayload(\$ticket, \$canManage)")
        ->toContain('private function waitingPayload(ItTicket $ticket, bool $canManage): ?array')
        ->toContain("\$ticket->waiting_party === 'requester' ? 'requester' : 'other'")
        ->and($queueController)
        ->toContain("'waiting_party' => \$t->waiting_party")
        ->toContain("\$t->waiting_party === 'requester' ? 'requester' : 'other'")
        ->and($transition)
        ->toContain("ItTicketEvent::record(\$ticket, 'waiting_updated'")
        ->toContain("AuditLogger::logOrFail('it.work.waiting.updated'")
        ->and($triage)
        ->toContain("array_key_exists('waiting_party', \$data)")
        ->toContain("array_key_exists('waiting_reason', \$data)")
        ->and($bulkRequest)
        ->toContain("'waiting_party' => ['required_if:status,waiting'")
        ->toContain("'waiting_reason' => ['required_if:status,waiting'")
        ->and($updateRequest)
        ->toContain("'waiting_party' => ['required_if:status,waiting'")
        ->toContain("'waiting_reason' => ['required_if:status,waiting'")
        ->and($workspace)
        ->toContain('<TicketWaitingDialog')
        ->toContain('Edit waiting details')
        ->not->toContain("? 'Waiting on requester'")
        ->and($queue)
        ->toContain('All waiting work')
        ->toContain('<TicketWaitingDialog')
        ->not->toContain("{ key: 'waiting', label: 'Waiting on requester' }")
        ->not->toContain("? 'Waiting on you'")
        ->and($drawer)
        ->toContain('waitingStatusLabel')
        ->not->toContain("? 'Waiting on requester'")
        ->and($dialog)
        ->toContain('Who or what is IT waiting for?')
        ->toContain('Reason for waiting')
        ->toContain('Next action')
        ->not->toContain('window.confirm(')
        ->not->toContain('window.alert(');
});

it('keeps canonical routed ownership visible to technicians and private from requesters', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $queueController = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $savedFilters = file_get_contents($root.'/app/Domain/It/Services/ItSavedTicketFilterService.php');
    $ticketController = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $routing = file_get_contents($root.'/app/Domain/It/Services/ItTicketRoutingService.php');
    $presenter = file_get_contents($root.'/app/Domain/It/Presenters/ItTicketRoutingPresenter.php');
    $queue = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $workspace = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');
    $drawer = file_get_contents($root.'/resources/js/components/it/ticket-drawer.tsx');
    $summary = file_get_contents($root.'/resources/js/components/it/ticket-routing-summary.tsx');

    expect($routing)
        ->toContain('$ticket->queue_id = $queue?->id')
        ->toContain('$ticket->team_id = $queue?->team_id')
        ->toContain('$ticket->owner_user_id = null')
        ->and($savedFilters)
        ->toContain("'owned_by_me' => 'Owned by me'")
        ->toContain("'my_team' => \"My team's work\"")
        ->and($queueController)
        ->toContain("'routing' => \$this->routingPresenter->present(\$t)")
        ->and($ticketController)
        ->toContain("...(\$isAgent ? ['routing' => \$this->routingPresenter->present(\$ticket)] : [])")
        ->and($presenter)
        ->toContain("'queue:id,name'")
        ->toContain("'team:id,name'")
        ->toContain("'owner:id,name'")
        ->and($queue)
        ->toContain("{ key: 'owned_by_me', label: 'Owned by me' }")
        ->toContain("{ key: 'my_team', label: \"My team's work\" }")
        ->toContain('<TicketRoutingSummary')
        ->and($workspace)
        ->toContain('Routed ownership')
        ->toContain('<TicketRoutingSummary')
        ->and($drawer)
        ->toContain('<TicketRoutingSummary')
        ->and($summary)
        ->toContain('Queue not configured')
        ->toContain('Accountable owner')
        ->not->toContain('window.confirm(')
        ->not->toContain('window.alert(');
});

it('keeps ordinary ticket classification connected to service routing and the working UI', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $ticketController = file_get_contents($root.'/app/Http/Controllers/It/ItTicketController.php');
    $request = file_get_contents($root.'/app/Http/Requests/It/UpdateTicketRequest.php');
    $triage = file_get_contents($root.'/app/Domain/It/Services/ItTicketTriageService.php');
    $wizard = file_get_contents($root.'/resources/js/components/it/it-wizards.tsx');
    $page = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $workspace = file_get_contents($root.'/resources/js/pages/it/tickets/show.tsx');

    expect($controller)
        ->toContain("'serviceOptions' =>")
        ->toContain("'work_type' => \$t->work_type")
        ->toContain("'service' => \$t->service")
        ->and($ticketController)
        ->toContain("'serviceOptions' =>")
        ->toContain("'siteOptions' =>")
        ->toContain("'assignApplicationWide' =>")
        ->toContain("'work_type' => \$ticket->work_type")
        ->and($request)
        ->toContain('ItTicket::INTAKE_WORK_TYPES')
        ->toContain("'it_service_id' => ['sometimes'")
        ->and($triage)
        ->toContain('ItTicketRoutingService $routing')
        ->toContain("['work_type', 'it_service_id', 'category', 'site_id', 'is_organisation_wide']")
        ->toContain('releaseIneligibleAssigneeAfterScopeChange')
        ->toContain('Remove or change the linked Asset before changing the ticket Site.')
        ->toContain('$this->routing->route($locked, $actor->id)')
        ->and($wizard)
        ->toContain('Work type')
        ->toContain('Affected service')
        ->toContain('serviceOptions={serviceOptions}')
        ->and($page)
        ->toContain('<TicketAdvancedFilters')
        ->toContain('services={serviceOptions}')
        ->and($workspace)
        ->toContain('aria-label="Work type"')
        ->toContain('aria-label="Affected service"')
        ->toContain('aria-label="Ticket Site"')
        ->toContain('All Sites');
});

it('keeps every server-backed ticket queue filter visible in the desktop queue', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $page = file_get_contents($root.'/resources/js/pages/it/index.tsx');
    $advancedFilters = file_get_contents($root.'/resources/js/components/it/ticket-advanced-filters.tsx');

    foreach ([
        'source',
        'work_type',
        'service',
        'age',
        'missing',
        'reopened',
        'first_contact',
        'open_only',
        'device_linked',
        'resolved_from',
        'resolved_to',
    ] as $filter) {
        expect($controller)->toContain("'{$filter}' =>");
        expect($advancedFilters)->toContain("'{$filter}'");
    }

    expect($page)
        ->toContain('<TicketAdvancedFilters')
        ->toContain('onClear={clearAdvancedTicketFilters}')
        ->and($advancedFilters)
        ->toContain('Classification')
        ->toContain('Queue health')
        ->toContain('Outcomes')
        ->toContain('Linked to a Device')
        ->toContain('Resolved on first contact')
        ->toContain('Clear more filters');
});

it('keeps knowledge lifecycle deletion and feedback serialized and centrally owned', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItKbController.php');
    $service = file_get_contents($root.'/app/Domain/It/Services/ItKbLifecycleService.php');
    $deleteRequestPath = $root.'/app/Http/Requests/It/DeleteKbArticleRequest.php';
    $deleteRequest = is_file($deleteRequestPath) ? file_get_contents($deleteRequestPath) : '';
    $deleteDialogPath = $root.'/resources/js/components/it/knowledge-draft-delete-dialog.tsx';
    $deleteDialog = is_file($deleteDialogPath) ? file_get_contents($deleteDialogPath) : '';
    $workspace = file_get_contents($root.'/resources/js/pages/it/index.tsx');

    expect($controller)
        ->not->toContain('ItKbArticle::query()->create(')
        ->not->toContain('$article->delete()')
        ->not->toContain('$article->increment(')
        ->not->toContain('ItKbInteraction::query()->create(')
        ->and($service)
        ->toContain('lockForUpdate()')
        ->toContain('DB::transaction(')
        ->toContain('it.knowledge.created')
        ->toContain('it.knowledge.draft.deleted')
        ->toContain('recordHelpful')
        ->and($deleteRequest)
        ->toContain("'reason' => ['required'")
        ->and($deleteDialog)
        ->toContain('Only draft articles can be deleted')
        ->toContain('Reason for deleting this draft')
        ->toContain('min-h-11')
        ->and($workspace)
        ->toContain('page.props.kbPublished')
        ->toContain('canonicalVote')
        ->toContain('submittingKbVoteFor');
});

it('keeps catalogue authoring publishing and entity choices governed end to end', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $routes = file_get_contents($root.'/routes/web.php');
    $managementPath = $root.'/app/Domain/It/Services/ItCatalogManagementService.php';
    $management = is_file($managementPath) ? file_get_contents($managementPath) : '';
    $optionsPath = $root.'/app/Domain/It/Services/ItCatalogFieldOptionService.php';
    $options = is_file($optionsPath) ? file_get_contents($optionsPath) : '';
    $setupPath = $root.'/resources/js/components/it/it-catalogue-management.tsx';
    $setup = is_file($setupPath) ? file_get_contents($setupPath) : '';
    $requester = file_get_contents($root.'/resources/js/components/it/it-service-catalogue.tsx');

    expect($routes)
        ->toContain('/it/setup/catalogue-items/{catalogItem}/publish')
        ->toContain('/it/setup/catalogue-items/{catalogItem}/unpublish')
        ->and($management)
        ->toContain('lockForUpdate()')
        ->toContain('it.catalogue.item.created')
        ->toContain('it.catalogue.item.updated')
        ->toContain('it.catalogue.item.published')
        ->toContain('it.catalogue.item.unpublished')
        ->and($options)
        ->toContain("'employee'")
        ->toContain("'user'")
        ->toContain("'asset'")
        ->toContain("whereHas('assignments'")
        ->and($setup)
        ->toContain('Form fields')
        ->toContain('Publish request')
        ->toContain('Reason for unpublishing')
        ->toContain('min-h-11')
        ->and($requester)
        ->toContain('fieldOptions')
        ->toContain("['employee', 'user', 'asset'].includes")
        ->toContain("const numeric = ['integer', 'number']");
});

it('keeps provisioning creation cancellation and HR handover centrally governed', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $controller = file_get_contents($root.'/app/Http/Controllers/It/ItProvisioningController.php');
    $service = file_get_contents($root.'/app/Domain/It/Services/ItProvisioningRequestLifecycleService.php');
    $cancelRequest = file_get_contents($root.'/app/Http/Requests/It/CancelProvisioningRequestRequest.php');
    $dialogPath = $root.'/resources/js/components/it/provisioning-cancel-dialog.tsx';
    $dialog = is_file($dialogPath) ? file_get_contents($dialogPath) : '';
    $workspace = file_get_contents($root.'/resources/js/pages/it/index.tsx');

    expect($controller)
        ->not->toContain('ItProvisioningRequest::query()->create([')
        ->not->toContain("\$task->update(['notes'")
        ->and($service)
        ->toContain('createManual')
        ->toContain('it.provisioning.request.created')
        ->toContain("'source' => 'manual'")
        ->toContain('HrOnboardingTask::query()')
        ->toContain('lockForUpdate()')
        ->toContain("'reason' => \$reason")
        ->and($cancelRequest)
        ->toContain("'reason' => ['required'")
        ->and($dialog)
        ->toContain('Reason for cancelling')
        ->toContain('linked onboarding task remains open')
        ->toContain('min-h-11')
        ->and($workspace)
        ->toContain('ProvisioningCancelDialog')
        ->toContain('setCancelRequest(r)');
});

it('detects an injected tenant authorization shortcut in a new file', function () {
    $violations = itSecurityScanTenantSource(
        'app/Domain/It/Services/NewTenantShortcut.php',
        <<<'PHP'
            <?php
            $tenantId = $request->user()->tenant_id;
            return Ticket::forTenant($tenantId)->get();
            LegacyTicket::forTenantOrSystem($tenantId)->count();
            PHP,
    );

    expect(array_keys($violations))
        ->toContain('tenant_parameter')
        ->toContain('tenant_storage_or_usage')
        ->toContain('tenant_query_scope');
    expect($violations['tenant_query_scope'])->toHaveCount(2);
});

it('detects legacy storage values laundered back into an active query', function () {
    $violations = itSecurityScanTenantSource(
        'app/Domain/It/Services/InjectedLegacyRead.php',
        <<<'PHP'
            <?php
            $storageId = LegacyStorageContext::id();
            Ticket::query()->where(...LegacyStorageContext::attributes())->get();
            $storageAttributes = LegacyStorageContext::attributes();
            Ticket::query()->where($storageAttributes)->get();
            PHP,
    );

    expect($violations)->toHaveKey('legacy_storage_read')
        ->and($violations['legacy_storage_read'])->toHaveCount(3);

    $aliasedAndDistant = <<<'PHP'
        <?php
        $storage = LegacyStorageContext::attributes();
        $criteria = $storage;
        PHP;
    $aliasedAndDistant .= str_repeat("// unrelated application work\n", 100);
    $aliasedAndDistant .= 'Ticket::query()->where($criteria)->get();';

    expect(itSecurityScanTenantSource(
        'app/Domain/It/Services/InjectedDistantLegacyRead.php',
        $aliasedAndDistant,
    ))->toHaveKey('legacy_storage_read');
});

it('rejects direct compatibility helpers and record derived compatibility writes', function () {
    $violations = itSecurityScanTenantSource(
        'app/Services/Queclink/InjectedCompatibilityWriter.php',
        <<<'PHP'
            <?php
            QueclinkPendingCommand::create([
                ...LegacyStorageContext::attributes(),
                'tenant_id' => $device->tenant_id,
            ]);
            PHP,
    );

    expect($violations)->toHaveKey('legacy_storage_read')
        ->and($violations)->toHaveKey('tenant_storage_or_usage');
});

it('allows only the fingerprinted model write compatibility helper', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $path = 'app/Models/Concerns/WritesLegacyStorageContext.php';
    $contents = file_get_contents($root.'/'.$path);

    expect($contents)->toBeString()
        ->and(itSecurityScanTenantSource($path, $contents))->not->toHaveKey('legacy_storage_read')
        ->and(itSecurityScanTenantSource(
            $path,
            str_replace('LegacyStorageContext::attributes()', 'LegacyStorageContext::id()', $contents),
        ))->toHaveKey('legacy_storage_read');
});

it('pins the exact compatibility evidence files excluded from active source scanning', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $expectedPaths = [
        'tests/Feature/It/ItProvisioningWorkflowTest.php',
        'tests/Feature/It/ItServiceManagementSchemaTest.php',
        'tests/Feature/Monitoring/MonitoringFoundationMigrationTest.php',
        'tests/Feature/Monitoring/MonitoringObservationProvenanceReconciliationTest.php',
        'tests/Feature/Monitoring/MonitoringSchemaTest.php',
        'tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php',
    ];

    expect(array_keys(itSecurityCompatibilityEvidenceFingerprints()))->toBe($expectedPaths);
    foreach (itSecurityCompatibilityEvidenceFingerprints() as $relativePath => $expectedHash) {
        $contents = file_get_contents($root.'/'.$relativePath);

        expect($contents)->toBeString()
            ->and(hash('sha256', $contents))->toBe($expectedHash)
            ->and(itSecurityScopedFiles($root))->toContain($root.'/'.$relativePath);
    }
});

it('detects plain product terminology and tenant shaped runtime contracts', function () {
    $violations = itSecurityScanTenantSource(
        'resources/js/pages/security-devices/injected.tsx',
        <<<'TSX'
            const copy = 'Large tenants can export the current tenant inventory.';
            const status = 'tenant_only';
            const permission = 'integrations.manage_tenant_secrets';
            const canManage = page.props.can.manageTenantSecrets;
            const siteOptions = pickerOptionsForTenant(organisationId);
            const canSkip = canSkipTenantScope(user);
            TSX,
    );

    expect(array_keys($violations))
        ->toContain('tenant_product_word')
        ->toContain('tenant_runtime_identifier')
        ->toContain('tenant_permission_contract')
        ->toContain('organisation_parameter')
        ->toContain('tenant_query_or_bypass');
});

it('changes the debt fingerprint when equal-count tenant shortcut semantics change', function () {
    $before = itSecurityScanTenantSource(
        'app/Domain/It/Services/ExistingShortcut.php',
        <<<'PHP'
            <?php
            return Ticket::forTenant($tenantId)
                ->where('status', 'open')
                ->get();
            PHP,
    )['tenant_query_scope'];
    $replacement = itSecurityScanTenantSource(
        'app/Domain/It/Services/ExistingShortcut.php',
        <<<'PHP'
            <?php
            return Ticket::forTenant($tenantId)
                ->where('status', 'closed')
                ->delete();
            PHP,
    )['tenant_query_scope'];

    expect($before)->toHaveCount(1)
        ->and($replacement)->toHaveCount(1)
        ->and($replacement)->not->toBe($before)
        ->and(itSecurityTenantRuleFingerprint($replacement))
        ->not->toBe(itSecurityTenantRuleFingerprint($before));
});

it('keeps legacy storage compatibility narrow and rejects the same field in a new model', function () {
    $storageDeclaration = <<<'PHP'
        <?php
        class Device {
            protected $fillable = [
                'tenant_id',
            ];
        }
        PHP;

    expect(itSecurityScanTenantSource('app/Domain/SecurityDevices/Models/Device.php', $storageDeclaration))
        ->not->toHaveKey('tenant_storage_or_usage')
        ->and(itSecurityScanTenantSource('app/Domain/SecurityDevices/Models/NewDevice.php', $storageDeclaration))
        ->toHaveKey('tenant_storage_or_usage')
        ->and(itSecurityScanTenantSource('app/Domain/SecurityDevices/Models/DeviceEvent.php', $storageDeclaration))
        ->toHaveKey('tenant_storage_or_usage');

    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    expect(itSecurityLegacyStorageDeclarationDriftSnapshot($root))->toBe([])
        ->and(itSecurityLegacyStorageWriterDriftSnapshot($root))->toBe([]);
});

it('protects every converted IT Security Devices and integration storage writer model', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $expected = [
        'app/Domain/Hr/Models/HrApprovalChain.php',
        'app/Domain/Hr/Models/HrBenefitEnrollment.php',
        'app/Domain/Hr/Models/HrBenefitPlan.php',
        'app/Domain/Hr/Models/HrCalendarEvent.php',
        'app/Domain/Hr/Models/HrCalendarEventAttachment.php',
        'app/Domain/Hr/Models/HrCalendarEventCategory.php',
        'app/Domain/Hr/Models/HrCandidate.php',
        'app/Domain/Hr/Models/HrCompetency.php',
        'app/Domain/Hr/Models/HrCompetencyAssessment.php',
        'app/Domain/Hr/Models/HrCustomFieldDefinition.php',
        'app/Domain/Hr/Models/HrDevelopmentGoal.php',
        'app/Domain/Hr/Models/HrEmployeeProfile.php',
        'app/Domain/Hr/Models/HrFeedbackRequest.php',
        'app/Domain/Hr/Models/HrFeedbackTemplate.php',
        'app/Domain/Hr/Models/HrGoal.php',
        'app/Domain/Hr/Models/HrGoalCycle.php',
        'app/Domain/Hr/Models/HrGoalTemplate.php',
        'app/Domain/Hr/Models/HrKeyResult.php',
        'app/Domain/Hr/Models/HrLeaveApprovalChain.php',
        'app/Domain/Hr/Models/HrLeaveBalance.php',
        'app/Domain/Hr/Models/HrLeaveBalanceLedger.php',
        'app/Domain/Hr/Models/HrLeaveRequest.php',
        'app/Domain/Hr/Models/HrOnboardingChecklist.php',
        'app/Domain/Hr/Models/HrOnboardingTemplate.php',
        'app/Domain/Hr/Models/HrPayrollRun.php',
        'app/Domain/Hr/Models/HrPayslip.php',
        'app/Domain/Hr/Models/HrPolicy.php',
        'app/Domain/Hr/Models/HrPolicyAttestation.php',
        'app/Domain/Hr/Models/HrPublicHoliday.php',
        'app/Domain/Hr/Models/HrSalaryBand.php',
        'app/Domain/SecurityDevices/Models/Device.php',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php',
        'app/Models/CalendarSyncBusyBlock.php',
        'app/Models/CalendarSyncConnection.php',
        'app/Models/CalendarSyncEventLink.php',
        'app/Models/CalendarSyncMapping.php',
        'app/Models/CredentialType.php',
        'app/Models/Integration/Integration.php',
        'app/Models/Integration/IntegrationEvent.php',
        'app/Models/Integration/IntegrationProviderConnection.php',
        'app/Models/Integration/IntegrationSiteConfig.php',
        'app/Models/Integration/IntegrationSiteSecret.php',
        'app/Models/Integration/IntegrationSyncLog.php',
        'app/Models/LocationHardware.php',
        'app/Models/ItApiRequest.php',
        'app/Models/ItAttachment.php',
        'app/Models/ItAutomationRun.php',
        'app/Models/ItCatalogItem.php',
        'app/Models/ItCatalogSubmission.php',
        'app/Models/ItChange.php',
        'app/Models/ItEmailDelivery.php',
        'app/Models/ItInboundEmail.php',
        'app/Models/ItKbArticle.php',
        'app/Models/ItKbInteraction.php',
        'app/Models/ItMailboxConnection.php',
        'app/Models/ItMajorIncident.php',
        'app/Models/ItMajorIncidentUpdate.php',
        'app/Models/ItProblem.php',
        'app/Models/ItProvisioningRequest.php',
        'app/Models/ItProvisioningTemplate.php',
        'app/Models/ItProvisioningWorkflow.php',
        'app/Models/ItQueue.php',
        'app/Models/ItService.php',
        'app/Models/ItServiceIdentity.php',
        'app/Models/ItSlaPolicy.php',
        'app/Models/ItTeam.php',
        'app/Models/ItTicket.php',
        'app/Models/ItTicketApproval.php',
        'app/Models/ItTicketComment.php',
        'app/Models/ItTicketEvent.php',
        'app/Models/ItTicketLink.php',
        'app/Models/ItWorkTask.php',
        'app/Models/Queclink/QueclinkAuditEvent.php',
        'app/Models/Queclink/QueclinkDevice.php',
        'app/Models/Queclink/QueclinkPendingCommand.php',
        'app/Models/Queclink/QueclinkPreset.php',
        'app/Models/Queclink/QueclinkRawFrame.php',
        'app/Models/SiteCredential.php',
        'app/Models/SiteCredentialAuditLog.php',
        'app/Models/SiteFacilityZone.php',
        'app/Models/SiteHouseRoom.php',
        'app/Models/SiteHouseRoomHistory.php',
        'app/Models/SiteHoResource.php',
        'app/Models/SiteRoom.php',
        'app/Models/SiteTypePlan.php',
        'app/Models/SiteTypePlanPin.php',
        'app/Models/SiteVendor.php',
        'app/Models/StaffTimeOff.php',
    ];

    expect(itSecurityLegacyStorageWriterModels())->toEqualCanonicalizing($expected)
        ->and(itSecurityLegacyStorageWriterDriftSnapshot($root))->toBe([]);
});

it('includes Security and Devices compatibility support that lives outside the domain folder', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $scopedFiles = itSecurityScopedFiles($root);

    expect($scopedFiles)
        ->toContain($root.'/app/Models/SiteRoom.php')
        ->toContain($root.'/app/Models/LocationHardware.php')
        ->toContain($root.'/app/Support/SafeOperationalData.php')
        ->toContain($root.'/app/Services/Audit/AuditLogViewService.php')
        ->toContain($root.'/app/Services/Operations/OpsMessageVisibilityService.php')
        ->toContain($root.'/app/Services/Clients/ClientPortalMembershipService.php');
});

it('covers active IT Security Devices integration Site UI and browser dependencies', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $scopedFiles = itSecurityScopedFiles($root);

    expect($scopedFiles)
        ->toContain($root.'/app/Http/Requests/It/StoreProvisioningRequestRequest.php')
        ->toContain($root.'/app/Notifications/It/TicketCreatedNotification.php')
        ->toContain($root.'/app/Jobs/Integration/SyncIntegrationDevicesJob.php')
        ->toContain($root.'/app/Services/Integration/IntegrationContextProvider.php')
        ->toContain($root.'/app/Services/Queclink/Listener/FrameRouter.php')
        ->toContain($root.'/app/Http/Controllers/Api/WebhookReceiverController.php')
        ->toContain($root.'/app/Http/Controllers/Sites/SiteComplianceController.php')
        ->toContain($root.'/app/Http/Controllers/Sites/SiteHardwareController.php')
        ->toContain($root.'/app/Http/Controllers/Sites/SiteIntegrationController.php')
        ->toContain($root.'/resources/js/components/it/it-module-shell.tsx')
        ->toContain($root.'/resources/js/components/security-devices/security-devices-module-shell.tsx')
        ->toContain($root.'/resources/js/pages/sites/compliance/Index.tsx')
        ->toContain($root.'/resources/js/pages/sites/feedback/Index.tsx')
        ->toContain($root.'/resources/js/pages/sites/show.tsx')
        ->toContain($root.'/tests/Feature/Sites/SiteComplianceWorkflowTest.php')
        ->toContain($root.'/tests/e2e/device-profile-fixtures.ts');
});

it('does not retain the obsolete HR organisation context concern', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    expect(itSecurityScopedFiles($root))
        ->not->toContain($root.'/app/Http/Controllers/Hr/Concerns/ResolvesHrOrganisationContext.php');
});

it('keeps visible IT and Security Devices copy on application and Site language', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $copyContracts = [
        'resources/js/pages/security-devices/sites/index.tsx' => [
            'required' => ['No active Sites are available within your approved Site access.'],
            'rejected' => ['No active sites are available within your current organisation and site access.'],
        ],
        'resources/js/components/it/it-api-identities.tsx' => [
            'required' => [
                'Access explicit application-wide work',
                'Application-wide scope marker',
                'Explicit application-wide work also needs its separate operation and scope marker.',
            ],
            'rejected' => [
                'Access explicit organisation-wide work',
                'Organisation-wide scope marker',
                'Explicit organisation-wide work also needs its separate operation and scope marker.',
            ],
        ],
        'resources/js/pages/it/setup/index.tsx' => [
            'required' => ['Managers and members must be approved IT agents in this application.'],
            'rejected' => ['Managers and members must be IT agents in this organisation.'],
        ],
        'app/Domain/SecurityDevices/Config/CategoryPageConfig.php' => [
            'required' => ['Register infrastructure devices to build the application-wide technology estate.'],
            'rejected' => ['Register infrastructure devices to build the organisation-wide technology estate.'],
        ],
        'app/Http/Controllers/It/ItChangeController.php' => [
            'required' => ['Application-wide work cannot also have a Site.'],
            'rejected' => ['Organisation-wide work cannot also have a Site.'],
        ],
        'app/Http/Controllers/It/ItProblemController.php' => [
            'required' => ['Application-wide work cannot also have a Site.'],
            'rejected' => ['Organisation-wide work cannot also have a Site.'],
        ],
        'app/Http/Controllers/It/ItMajorIncidentController.php' => [
            'required' => ['Application-wide work cannot also have a Site.'],
            'rejected' => ['Organisation-wide work cannot also have a Site.'],
        ],
    ];

    foreach ($copyContracts as $relativePath => $contract) {
        $contents = file_get_contents($root.'/'.$relativePath);
        $normalized = preg_replace('/\s+/', ' ', (string) $contents);

        expect($contents)->toBeString();
        foreach ($contract['required'] as $requiredCopy) {
            expect($normalized)->toContain($requiredCopy);
        }
        foreach ($contract['rejected'] as $rejectedCopy) {
            expect($normalized)->not->toContain($rejectedCopy);
        }
    }
});

it('uses an application shaped integration management permission across active dependencies', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $legacyMatches = [];

    foreach (itSecurityScopedFiles($root) as $absolutePath) {
        $contents = file_get_contents($absolutePath);
        if (is_string($contents)
            && preg_match('/integrations\.manage_tenant_secrets|manageTenantSecrets/u', $contents) === 1
        ) {
            $legacyMatches[] = ltrim(substr($absolutePath, strlen($root)), '/');
        }
    }

    expect($legacyMatches)->toBe([]);
});

it('rejects a new tenant partition migration while excluding exact historical migrations', function () {
    $source = <<<'PHP'
        <?php
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->index();
        });
        PHP;

    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000001_add_tenant_partition_to_it_tickets.php',
        $source,
    ))->toBeTrue()
        ->and(itSecurityScanTenantMigration(
            'database/migrations/2026_07_02_100001_create_it_provisioning_tables.php',
            $source,
        ))->toBeTrue();

    $removal = <<<'PHP'
        <?php
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropIndex('it_tickets_tenant_status_idx');
            $table->dropColumn('tenant_id');
        });
        PHP;
    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000002_remove_legacy_tenant_partition.php',
        $removal,
    ))->toBeFalse();

    $additionalPartitionForms = [
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignUuid('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignUlid('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->unsignedSmallInteger('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->tinyInteger('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->char('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->id('tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->morphs('tenant'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->nullableMorphs('tenant'));",
        "DB::statement('ALTER TABLE it_tickets ADD COLUMN tenant_id BIGINT')",
        "Schema::table('monitor_observations', fn (Blueprint \$table) => \$table->uuid('tenant_id'));",
        'Schema::table("device_assignments", fn (Blueprint $table) => $table->foreignId("tenant_id"));',
        "Schema::table('site_rooms', fn (Blueprint \$table) => \$table->foreignId('tenant_id'));",
        "Schema::table('location_hardware', fn (Blueprint \$table) => \$table->uuid('tenant_id'));",
        "DB::statement('ALTER TABLE site_rooms ADD COLUMN tenant_id BIGINT')",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignIdFor(Tenant::class));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->renameColumn('account_id', 'tenant_id'));",
        "DB::statement('CREATE TABLE it_ticket_partitions (id BIGINT, tenant_id BIGINT)')",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->decimal('tenant_id', 12, 2));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignId(column: 'tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->renameColumn(from: 'account_id', to: 'tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignIdFor(model: Tenant::class));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignIdFor(User::class, 'tenant_id'));",
        "Schema::table('it_tickets', fn (Blueprint \$table) => \$table->foreignIdFor(model: User::class, column: 'tenant_id'));",
    ];
    foreach ($additionalPartitionForms as $index => $partitionSource) {
        expect(itSecurityScanTenantMigration(
            "database/migrations/2026_07_22_0001{$index}_add_partition_variant.php",
            "<?php\n{$partitionSource}",
        ))->toBeTrue();
    }

    $additionalPartitionConstraints = [
        "Schema::table('sites', fn (Blueprint \$table) => \$table->foreignId('organization_id'));",
        "Schema::table('devices', fn (Blueprint \$table) => \$table->unique(['tenant_id', 'external_ref']));",
        "Schema::table('hr_employee_profiles', fn (Blueprint \$table) => \$table->index(['organization_id', 'is_active']));",
        "DB::statement('ALTER TABLE integration_events ADD UNIQUE INDEX integration_events_tenant_source_uq (tenant_id, source_id)')",
        "Schema::table('devices', function (Blueprint \$table) { \$table->unique([\n    'tenant_id',\n    'external_ref',\n]); });",
        "DB::statement('ALTER TABLE integration_events ADD UNIQUE INDEX integration_events_tenant_source_uq (\n tenant_id,\n source_id\n)')",
    ];
    foreach ($additionalPartitionConstraints as $index => $partitionSource) {
        expect(itSecurityScanTenantMigration(
            "database/migrations/2026_07_22_0002{$index}_add_partition_constraint.php",
            "<?php\n{$partitionSource}",
        ))->toBeTrue();
    }

    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000299_partition_calendar_sync.php',
        "<?php\nSchema::table('calendar_sync_connections', fn (Blueprint \$table) => \$table->foreignId('tenant_id'));",
    ))->toBeTrue();

    expect(itSecurityScanTenantMigration(
        'database/migrations/2026_07_22_000099_drop_raw_tenant_partition.php',
        "<?php\nDB::statement('ALTER TABLE it_tickets DROP COLUMN tenant_id')",
    ))->toBeFalse()
        ->and(itSecurityScanTenantMigration(
            'database/migrations/2026_07_22_000100_rename_legacy_tenant_partition.php',
            "<?php\nSchema::table('it_tickets', fn (Blueprint \$table) => \$table->renameColumn('tenant_id', 'legacy_context_id'));",
        ))->toBeFalse();

    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    expect(itSecurityHistoricalMigrationDriftSnapshot($root))->toBe([]);
});

/** @return list<string> */
function itSecurityTenantDebtSnapshot(string $root): array
{
    $snapshot = [];
    $compatibilityEvidence = itSecurityCompatibilityEvidenceFingerprints();

    foreach (itSecurityScopedFiles($root) as $absolutePath) {
        $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read scoped boundary file {$relativePath}.");
        }

        $expectedEvidenceHash = $compatibilityEvidence[$relativePath] ?? null;
        if ($expectedEvidenceHash !== null
            && hash_equals($expectedEvidenceHash, hash('sha256', $contents))
        ) {
            continue;
        }

        foreach (itSecurityScanTenantSource($relativePath, $contents) as $rule => $matches) {
            $snapshot[] = implode('|', [
                $relativePath,
                $rule,
                count($matches),
                itSecurityTenantRuleFingerprint($matches),
            ]);
        }
    }

    foreach (itSecurityMigrationFiles($root) as $absolutePath) {
        $relativePath = ltrim(substr($absolutePath, strlen($root)), '/');
        $contents = file_get_contents($absolutePath);
        if ($contents !== false && itSecurityScanTenantMigration($relativePath, $contents)) {
            $snapshot[] = $relativePath.'|new_tenant_partition_migration|1|'.substr(hash('sha256', 'tenant_id'), 0, 16);
        }
    }

    sort($snapshot, SORT_STRING);

    return $snapshot;
}

/** @return array<string, string> */
function itSecurityCompatibilityEvidenceFingerprints(): array
{
    return [
        'tests/Feature/It/ItProvisioningWorkflowTest.php' => 'b03da30cdfb02dfb4c1dbb1524a5c0fcb3a3ee24987d2debce5a1e92af4d333b',
        'tests/Feature/It/ItServiceManagementSchemaTest.php' => 'e54dc9941b442fa4600dfc033bf203b1d9d0c7abef73f40b7755b2e40a5fd372',
        'tests/Feature/Monitoring/MonitoringFoundationMigrationTest.php' => '887bf76dbd5ba516321b4e41dfeac6150b7ac9d66cf803a1da079a09b0bd3b28',
        'tests/Feature/Monitoring/MonitoringObservationProvenanceReconciliationTest.php' => 'ccf15093cf6293e76084781da1142d0621162d8b5fc78e8710f2855bad664e69',
        'tests/Feature/Monitoring/MonitoringSchemaTest.php' => 'f755c57573d32e70a1746f7558147b3741be022e092d9e3f31498c823185b16e',
        'tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php' => 'af83c6c0b9e9cd1dcd91aa27740df2a2ebaad85462926a9a49b3878751aeada2',
    ];
}

/**
 * Return a line-independent normalized statement-context snapshot grouped by
 * rule. Existing active debt is ratcheted by exact path + rule + count +
 * bounded context hash; Task 9 must reduce the approved snapshot to empty.
 *
 * @return array<string, list<string>>
 */
function itSecurityScanTenantSource(string $relativePath, string $contents): array
{
    $patterns = [
        'tenant_query_scope' => '/\b(?:scopeForTenant(?:OrSystem)?|forTenant(?:OrSystem)?)\b/u',
        'tenant_resolver' => '/\b(?:Resolves(?:Hr|Device)?Tenant|resolve(?:Hr|Device)?TenantId(?:ForUser)?|resolveTenantId)\b/u',
        'tenant_parameter' => '/\btenantId\b/u',
        'tenant_storage_or_usage' => '/\btenant_id\b/u',
        'organisation_comparison' => '/\b(?:organization_id|organisation_id)\b/iu',
        'all_tenant_sites_bypass' => '/\bcanViewAllTenantSites\b/u',
        'tenant_matcher' => '/\b[A-Za-z][A-Za-z0-9_]*MatchesTenant\b/u',
        'tenant_secret_contract' => '/\b(?:tenantSecret|IntegrationTenantSecret)\b/u',
        'tenant_product_copy' => '/\b(?:same|foreign|cross|other)\s+tenant\b|\btenant[- ](?:wide|scoped|scope)\b|\bmulti[- ]tenant\b/iu',
        'tenant_product_word' => '/(?<![A-Za-z0-9_])tenants?(?:[\'’]s)?(?![A-Za-z0-9_])/iu',
        'tenant_runtime_identifier' => '/(?<![A-Za-z0-9])tenant_(?!id\b)[A-Za-z0-9_]+|\b(?:[A-Za-z][A-Za-z0-9]*Tenant[A-Za-z0-9]*|tenant[A-Z][A-Za-z0-9]*)\b/u',
        'tenant_permission_contract' => '/\bintegrations\.manage_tenant_secrets\b|\bmanageTenantSecrets\b/u',
        'organisation_parameter' => '/\b(?:organization|organisation)Id\b/u',
        'tenant_query_or_bypass' => '/\b(?:scopeForTenant(?:OrSystem)?|forTenant(?:OrSystem)?|[A-Za-z0-9_]+ForTenant|can(?:Skip|View)[A-Za-z0-9_]*Tenant[A-Za-z0-9_]*)\b/u',
        'hr_partition_laundering' => '/\b(?:hrApplicationStorageContextId|assertHrOrganisationAccess|applicationRecipientRule)\b/u',
        'legacy_storage_read' => '/\bLegacyStorageContext::(?:column|id|attributes)\s*\(/u',
    ];
    $violations = [];

    foreach ($patterns as $rule => $pattern) {
        if ($rule === 'tenant_product_word' && str_ends_with(strtolower($relativePath), '.md')) {
            continue;
        }

        preg_match_all($pattern, $contents, $rawMatches, PREG_OFFSET_CAPTURE);
        $tokens = [];

        foreach ($rawMatches[0] ?? [] as [$token, $offset]) {
            if ($rule === 'tenant_storage_or_usage'
                && itSecurityIsAllowedLegacyStorageOccurrence($relativePath, $contents, (int) $offset)
            ) {
                continue;
            }

            if ($rule === 'legacy_storage_read'
                && itSecurityIsAllowedLegacyStorageWriter($relativePath, $contents)
            ) {
                continue;
            }

            if ($rule === 'tenant_runtime_identifier'
                && itSecurityIsAllowedCompatibilityIdentifierOccurrence($relativePath, $contents, (int) $offset)
            ) {
                continue;
            }

            $tokens[] = itSecurityNormalizedTenantTokenContext(
                $contents,
                (int) $offset,
                (string) $token,
            );
        }

        if ($tokens !== []) {
            sort($tokens, SORT_STRING);
            $violations[$rule] = $tokens;
        }
    }

    ksort($violations, SORT_STRING);

    return $violations;
}

/** @param list<string> $matches */
function itSecurityTenantRuleFingerprint(array $matches): string
{
    return substr(hash('sha256', json_encode($matches, JSON_THROW_ON_ERROR)), 0, 16);
}

/**
 * Fingerprint the bounded logical statement around a token. All whitespace is
 * removed so formatting and line movement do not churn the baseline, while an
 * equal-count semantic replacement in the same file produces a different hash.
 */
function itSecurityNormalizedTenantTokenContext(string $contents, int $offset, string $token): string
{
    $statementStart = 0;
    $prefix = substr($contents, 0, $offset);
    foreach (["\n\n", ';', '{', '}'] as $delimiter) {
        $position = strrpos($prefix, $delimiter);
        if ($position !== false) {
            $statementStart = max($statementStart, $position + strlen($delimiter));
        }
    }

    $tokenEnd = $offset + strlen($token);
    $statementEnd = strlen($contents);
    foreach (["\n\n", ';', '{', '}'] as $delimiter) {
        $position = strpos($contents, $delimiter, $tokenEnd);
        if ($position !== false) {
            $statementEnd = min($statementEnd, $position + strlen($delimiter));
        }
    }

    $maximumContext = 640;
    if ($statementEnd - $statementStart > $maximumContext) {
        $half = intdiv($maximumContext, 2);
        $statementStart = max($statementStart, $offset - $half);
        $statementEnd = min($statementEnd, $tokenEnd + $half);
    }

    $statement = substr($contents, $statementStart, $statementEnd - $statementStart);
    $normalized = preg_replace('/\s+/u', '', $statement);
    if (! is_string($normalized) || $normalized === '') {
        $normalized = $token;
    }

    return strtolower($token).'@'.substr(hash('sha256', $normalized), 0, 24);
}

function itSecurityIsAllowedLegacyStorageOccurrence(string $relativePath, string $contents, int $offset): bool
{
    if ($relativePath === 'app/Support/LegacyStorageContext.php') {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($contents, "\n", $offset);

        return trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart))
            === "return 'tenant_id';";
    }

    if (! in_array($relativePath, itSecurityLegacyStorageFiles(), true)) {
        return false;
    }

    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $line = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));
    if (preg_match('/^[\'\"]tenant_id[\'\"]\s*(?:=>\s*[\'\"](?:int|integer|string)[\'\"])?\s*,?$/', $line) !== 1) {
        return false;
    }

    $prefix = substr($contents, 0, $offset);
    $fillable = strrpos($prefix, 'protected $fillable = [');
    $casts = strrpos($prefix, 'protected $casts = [');
    $start = max($fillable === false ? -1 : $fillable, $casts === false ? -1 : $casts);
    $close = strrpos($prefix, '];');

    return $start >= 0 && ($close === false || $start > $close);
}

function itSecurityIsAllowedLegacyStorageWriter(string $relativePath, string $contents): bool
{
    return $relativePath === 'app/Models/Concerns/WritesLegacyStorageContext.php'
        && hash_equals(
            '2812597f6a8ab6880cb60678a9d94bd3c22beaa07c6bc545ffeafc27c947800e',
            hash('sha256', $contents),
        );
}

function itSecurityIsAllowedCompatibilityIdentifierOccurrence(string $relativePath, string $contents, int $offset): bool
{
    $lineStart = strrpos(substr($contents, 0, $offset), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $offset);
    $line = trim(substr($contents, $lineStart, $lineEnd === false ? null : $lineEnd - $lineStart));

    return ($relativePath === 'app/Models/Integration/IntegrationProviderConnection.php'
            && $line === "protected \$table = 'integration_tenant_secrets';")
        || ($relativePath === 'app/Models/ItTicket.php'
            && $line === "|| str_contains(\$exception->getMessage(), 'it_tickets_tenant_reference_uq');");
}

/** @return list<string> */
function itSecurityScopedFiles(string $root): array
{
    $files = [];
    foreach ([
        'app/Domain/It',
        'app/Domain/Monitoring',
        'app/Domain/SecurityDevices',
        'app/Http/Controllers/It',
        'app/Http/Requests/It',
        'app/Jobs/Integration',
        'app/Listeners/It',
        'app/Notifications/It',
        'app/Services/Integration',
        'app/Services/Queclink',
        'app/Support/It',
        'resources/js/components/it',
        'resources/js/components/security-devices',
        'resources/js/pages/it',
        'resources/js/pages/security-devices',
        'tests/Feature/It',
        'tests/Feature/Monitoring',
        'tests/Feature/SecurityDevices',
        'tests/Unit/It',
        'tests/Unit/Monitoring',
        'tests/Unit/SecurityDevices',
        'tests/Feature/Sites/Calendar',
        'tests/e2e',
    ] as $directory) {
        $files = [...$files, ...itSecurityRecursiveFiles($root.'/'.$directory)];
    }

    foreach ([
        'app/Http/Controllers/Api/ItApiWorkItemController.php',
        'app/Http/Controllers/Api/WebhookReceiverController.php',
        'app/Http/Controllers/AuditExportController.php',
        'app/Http/Controllers/AuditLogController.php',
        'app/Http/Controllers/ClientPortalUserController.php',
        'app/Http/Controllers/FleetAssets/LiveMapController.php',
        'app/Http/Controllers/Hr/AuditController.php',
        'app/Http/Controllers/Operations/MessageController.php',
        'app/Http/Controllers/Portal/PortalMessageController.php',
        'app/Http/Controllers/Sites/SiteComplianceController.php',
        'app/Http/Controllers/Sites/SiteHardwareController.php',
        'app/Http/Controllers/Sites/SiteIntegrationController.php',
        'app/Http/Controllers/Sites/SiteCalendarController.php',
        'app/Http/Controllers/Sites/SiteTypePlanController.php',
        'app/Http/Controllers/Sites/SiteTypePlanPinController.php',
        'app/Http/Controllers/Settings/ApiSettingsController.php',
        'app/Http/Controllers/Settings/CalendarSyncOAuthController.php',
        'app/Http/Controllers/Settings/CalendarSyncSettingsController.php',
        'app/Http/Controllers/Settings/AuditLogSettingsController.php',
        'app/Console/Commands/ReconcileDeviceDocumentStorage.php',
        'app/Http/Middleware/AuthenticateItServiceIdentity.php',
        'app/Http/Middleware/EnsureItApiAbility.php',
        'app/Http/Middleware/HandleInertiaRequests.php',
        'app/Http/Middleware/RecordItApiRequest.php',
        'app/Http/Controllers/Settings/ItMailboxOAuthController.php',
        'app/Http/Controllers/Settings/ItMailboxSettingsController.php',
        'app/Http/Requests/Settings/UpdateItMailboxRequest.php',
        'app/Http/Resources/ItApiWorkItemResource.php',
        'app/Jobs/PollItMailboxJob.php',
        'app/Jobs/SyncResourceCalendarsJob.php',
        'app/Models/CalendarSyncBusyBlock.php',
        'app/Models/CalendarSyncConnection.php',
        'app/Models/CalendarSyncEventLink.php',
        'app/Models/CalendarSyncMapping.php',
        'app/Models/CredentialType.php',
        'app/Models/SiteCertification.php',
        'app/Models/SiteComplianceCheck.php',
        'app/Models/SiteCoverageRequirement.php',
        'app/Models/SiteCredential.php',
        'app/Models/SiteCredentialAuditLog.php',
        'app/Models/SiteFacilityZone.php',
        'app/Models/SiteHouseRoom.php',
        'app/Models/SiteHouseRoomHistory.php',
        'app/Models/SiteHoResource.php',
        'app/Models/SiteRoom.php',
        'app/Models/SiteFeedback.php',
        'app/Models/SiteStaffRequirement.php',
        'app/Models/SiteTypePlan.php',
        'app/Models/SiteTypePlanPin.php',
        'app/Models/SiteVendor.php',
        'app/Models/LocationHardware.php',
        'app/Models/FleetKeyLog.php',
        'app/Models/Concerns/WritesLegacyStorageContext.php',
        'app/Observers/DeviceEventObserver.php',
        'app/Providers/AppServiceProvider.php',
        'app/Providers/EventServiceProvider.php',
        'app/Support/LegacyStorageContext.php',
        'app/Support/SafeOperationalData.php',
        'app/Http/Controllers/ControlRoom/ControlRoomAlertController.php',
        'app/Http/Controllers/FleetAssets/AlertController.php',
        'app/Http/Controllers/FleetAssets/DriverController.php',
        'app/Http/Controllers/FleetAssets/KeyController.php',
        'app/Http/Controllers/FleetAssets/ResidentTrackingController.php',
        'app/Services/ControlRoom/ControlRoomAlertProvenanceService.php',
        'app/Services/ControlRoom/ControlRoomAlertLifecycleService.php',
        'app/Services/Audit/AuditLogViewService.php',
        'app/Services/Clients/ClientPortalMembershipService.php',
        'app/Services/Clients/ClientFamilyCommunicationAccess.php',
        'app/Services/Fleet/FleetDeviceRuntimeService.php',
        'app/Services/Fleet/FleetTelemetryIngestService.php',
        'app/Services/Operations/OpsMessageVisibilityService.php',
        'app/Services/Sites/Calendar/CalendarSyncService.php',
        'app/Services/Sites/SiteTypePlanPinPayloadValidator.php',
        'app/Services/Sites/SiteTypePlanService.php',
        'database/seeders/DuskDatabaseSeeder.php',
        'database/seeders/RbacSeeder.php',
        'resources/js/pages/settings/api.tsx',
        'resources/js/pages/hr/settings/audit-log.tsx',
        'resources/js/layouts/settings/layout.tsx',
        'resources/js/pages/sites/calendar/SiteCalendar.tsx',
        'resources/js/pages/sites/compliance/Index.tsx',
        'resources/js/pages/sites/feedback/Index.tsx',
        'resources/js/pages/sites/show.tsx',
        'routes/api-hr.php',
        'routes/console.php',
        'routes/integrations.php',
        'routes/security-devices.php',
        'routes/settings.php',
        'routes/sites.php',
        'routes/web.php',
        'tests/Feature/Settings/CalendarSyncSettingsTest.php',
        'tests/Feature/Settings/CalendarSyncOAuthTest.php',
        'tests/Feature/Audit/AuditLogSurfaceTest.php',
        'tests/Feature/ClientPortalUserControllerTest.php',
        'tests/Feature/Operations/OpsConversationParticipantAccessTest.php',
        'tests/Feature/Portal/PortalMessageMediaSecurityTest.php',
        'tests/Feature/FleetAssets/DriverWorkspaceSiteAccessTest.php',
        'tests/Feature/FleetAssets/FleetKeyLedgerSiteAccessTest.php',
        'tests/Feature/Sites/SiteComplianceWorkflowTest.php',
        'docs/it-support-security-devices-completion-goal.md',
        'docs/it-support-service-api-v1.md',
        'docs/security-devices-restructure-plan.md',
        'docs/security-devices-next-session.md',
        'docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md',
        'docs/superpowers/plans/2026-07-21-native-monitoring-runtime.md',
        'docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md',
    ] as $relativePath) {
        $absolutePath = $root.'/'.$relativePath;
        if (is_file($absolutePath)) {
            $files[] = $absolutePath;
        }
    }

    foreach (['app/Models', 'app/Policies'] as $directory) {
        foreach (itSecurityRecursiveFiles($root.'/'.$directory) as $file) {
            $relativePath = ltrim(substr($file, strlen($root)), '/');
            if (preg_match('#^app/(?:Models|Policies)/It[^/]*\.php$#', $relativePath) === 1
                || preg_match('#^app/Models/(?:Integration|Queclink)/#', $relativePath) === 1
            ) {
                $files[] = $file;
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);

    return $files;
}

/** @return list<string> */
function itSecurityRecursiveFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'ts', 'tsx', 'md'], true)) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    return $files;
}

/** @return list<string> */
function itSecurityMigrationFiles(string $root): array
{
    return itSecurityRecursiveFiles($root.'/database/migrations');
}

function itSecurityScanTenantMigration(string $relativePath, string $contents): bool
{
    $historicalFingerprints = itSecurityHistoricalTenantMigrationFingerprints();
    if (isset($historicalFingerprints[$relativePath])
        && hash('sha256', $contents) === $historicalFingerprints[$relativePath]
    ) {
        return false;
    }

    $targetsAuditedTables = preg_match(
        '/Schema::(?:create|table)\(\s*[\'\"](?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|calendar_sync_|sites|site_|users|clients|hr_|assets|fleet_|location_hardware)/',
        $contents,
    ) === 1
        || preg_match(
            '/ALTER\s+TABLE\s+[`\'\"]?(?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|calendar_sync_|sites|site_|users|clients|hr_|assets|fleet_|location_hardware)/i',
            $contents,
        ) === 1
        || preg_match(
            '/CREATE\s+TABLE\s+[`\'\"]?(?:it_|monitor_|monitoring_|monitors|devices|device_|integration_|integrations|queclink_|calendar_sync_|sites|site_|users|clients|hr_|assets|fleet_|location_hardware)/i',
            $contents,
        ) === 1;
    $partitionField = '(?:tenant_id|organization_id|organisation_id)';
    $addsPartition = preg_match(
        '/->(?!(?:drop[A-Za-z0-9_]*|renameColumn)\b)[A-Za-z_][A-Za-z0-9_]*\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?[\'\"]'.$partitionField.'[\'\"]/',
        $contents,
    ) === 1
        || preg_match(
            '/->renameColumn\s*\(\s*(?:from\s*:\s*)?[\'\"][^\'\"]+[\'\"]\s*,\s*(?:to\s*:\s*)?[\'\"]'.$partitionField.'[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/->foreignIdFor\s*\(\s*(?:model\s*:\s*)?(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\)*(?:Tenant|Organization|Organisation)::class\b/',
            $contents,
        ) === 1
        || preg_match(
            '/->foreignIdFor\s*\(\s*(?:model\s*:\s*)?[^,;]+::class\s*,\s*(?:column\s*:\s*)?[\'\"]'.$partitionField.'[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/->(?:morphs|nullableMorphs|uuidMorphs|nullableUuidMorphs|ulidMorphs|nullableUlidMorphs)\s*\(\s*[\'\"]tenant[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/->addColumn\s*\(\s*[\'\"][^\'\"]+[\'\"]\s*,\s*[\'\"]'.$partitionField.'[\'\"]/',
            $contents,
        ) === 1
        || preg_match(
            '/(?:ALTER\s+TABLE[^;]{0,2000}\s+ADD(?:\s+COLUMN)?|CREATE\s+TABLE[^;]{0,2000}[,(])\s*[`\'\"]?'.$partitionField.'\b/is',
            $contents,
        ) === 1
        || preg_match(
            '/->(?:index|unique)\s*\([^;]{0,1000}(?:tenant_id|organization_id|organisation_id)/is',
            $contents,
        ) === 1
        || preg_match(
            '/ALTER\s+TABLE[^;]{0,2000}ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY|CONSTRAINT)[^;]{0,2000}(?:tenant_id|organization_id|organisation_id)/is',
            $contents,
        ) === 1;

    return $targetsAuditedTables && $addsPartition;
}

/** @return list<string> */
function itSecurityLegacyStorageFiles(): array
{
    return [
        'app/Domain/Hr/Models/HrApprovalChain.php',
        'app/Domain/Hr/Models/HrBenefitEnrollment.php',
        'app/Domain/Hr/Models/HrBenefitPlan.php',
        'app/Domain/Hr/Models/HrCalendarEvent.php',
        'app/Domain/Hr/Models/HrCalendarEventAttachment.php',
        'app/Domain/Hr/Models/HrCalendarEventCategory.php',
        'app/Domain/Hr/Models/HrCandidate.php',
        'app/Domain/Hr/Models/HrCompetency.php',
        'app/Domain/Hr/Models/HrCompetencyAssessment.php',
        'app/Domain/Hr/Models/HrCustomFieldDefinition.php',
        'app/Domain/Hr/Models/HrDevelopmentGoal.php',
        'app/Domain/Hr/Models/HrEmployeeProfile.php',
        'app/Domain/Hr/Models/HrFeedbackRequest.php',
        'app/Domain/Hr/Models/HrFeedbackTemplate.php',
        'app/Domain/Hr/Models/HrGoal.php',
        'app/Domain/Hr/Models/HrGoalCycle.php',
        'app/Domain/Hr/Models/HrGoalTemplate.php',
        'app/Domain/Hr/Models/HrKeyResult.php',
        'app/Domain/Hr/Models/HrLeaveApprovalChain.php',
        'app/Domain/Hr/Models/HrLeaveBalance.php',
        'app/Domain/Hr/Models/HrLeaveBalanceLedger.php',
        'app/Domain/Hr/Models/HrLeaveRequest.php',
        'app/Domain/Hr/Models/HrOnboardingChecklist.php',
        'app/Domain/Hr/Models/HrOnboardingTemplate.php',
        'app/Domain/Hr/Models/HrPayrollRun.php',
        'app/Domain/Hr/Models/HrPayslip.php',
        'app/Domain/Hr/Models/HrPolicy.php',
        'app/Domain/Hr/Models/HrPolicyAttestation.php',
        'app/Domain/Hr/Models/HrPublicHoliday.php',
        'app/Domain/Hr/Models/HrSalaryBand.php',
        'app/Domain/SecurityDevices/Models/Device.php',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php',
        'app/Models/CalendarSyncBusyBlock.php',
        'app/Models/CalendarSyncConnection.php',
        'app/Models/CalendarSyncEventLink.php',
        'app/Models/CalendarSyncMapping.php',
        'app/Models/Integration/Integration.php',
        'app/Models/Integration/IntegrationEvent.php',
        'app/Models/Integration/IntegrationSiteConfig.php',
        'app/Models/Integration/IntegrationSiteSecret.php',
        'app/Models/Integration/IntegrationSyncLog.php',
        'app/Models/Integration/IntegrationProviderConnection.php',
        'app/Models/SiteRoom.php',
        'app/Models/LocationHardware.php',
        'app/Models/Queclink/QueclinkAuditEvent.php',
        'app/Models/Queclink/QueclinkDevice.php',
        'app/Models/Queclink/QueclinkPendingCommand.php',
        'app/Models/Queclink/QueclinkPreset.php',
        'app/Models/Queclink/QueclinkRawFrame.php',
        'app/Models/ItApiRequest.php',
        'app/Models/ItAttachment.php',
        'app/Models/ItAutomationRun.php',
        'app/Models/ItCatalogItem.php',
        'app/Models/ItCatalogSubmission.php',
        'app/Models/ItChange.php',
        'app/Models/ItEmailDelivery.php',
        'app/Models/ItInboundEmail.php',
        'app/Models/ItKbArticle.php',
        'app/Models/ItKbInteraction.php',
        'app/Models/ItMailboxConnection.php',
        'app/Models/ItMajorIncident.php',
        'app/Models/ItMajorIncidentUpdate.php',
        'app/Models/ItProblem.php',
        'app/Models/ItProvisioningRequest.php',
        'app/Models/ItProvisioningTemplate.php',
        'app/Models/ItProvisioningWorkflow.php',
        'app/Models/ItQueue.php',
        'app/Models/ItService.php',
        'app/Models/ItServiceIdentity.php',
        'app/Models/ItSlaPolicy.php',
        'app/Models/ItTeam.php',
        'app/Models/ItTicket.php',
        'app/Models/ItTicketComment.php',
        'app/Models/ItTicketEvent.php',
        'app/Models/ItTicketLink.php',
        'app/Models/ItWorkTask.php',
        'app/Models/CredentialType.php',
        'app/Models/SiteCredential.php',
        'app/Models/SiteCredentialAuditLog.php',
        'app/Models/SiteFacilityZone.php',
        'app/Models/SiteHouseRoom.php',
        'app/Models/SiteHouseRoomHistory.php',
        'app/Models/SiteHoResource.php',
        'app/Models/SiteVendor.php',
        'app/Models/SiteTypePlan.php',
        'app/Models/SiteTypePlanPin.php',
        'app/Models/StaffTimeOff.php',
    ];
}

/** @return list<string> */
function itSecurityLegacyStorageWriterModels(): array
{
    return [
        'app/Domain/Hr/Models/HrApprovalChain.php',
        'app/Domain/Hr/Models/HrBenefitEnrollment.php',
        'app/Domain/Hr/Models/HrBenefitPlan.php',
        'app/Domain/Hr/Models/HrCalendarEvent.php',
        'app/Domain/Hr/Models/HrCalendarEventAttachment.php',
        'app/Domain/Hr/Models/HrCalendarEventCategory.php',
        'app/Domain/Hr/Models/HrCandidate.php',
        'app/Domain/Hr/Models/HrCompetency.php',
        'app/Domain/Hr/Models/HrCompetencyAssessment.php',
        'app/Domain/Hr/Models/HrCustomFieldDefinition.php',
        'app/Domain/Hr/Models/HrDevelopmentGoal.php',
        'app/Domain/Hr/Models/HrEmployeeProfile.php',
        'app/Domain/Hr/Models/HrFeedbackRequest.php',
        'app/Domain/Hr/Models/HrFeedbackTemplate.php',
        'app/Domain/Hr/Models/HrGoal.php',
        'app/Domain/Hr/Models/HrGoalCycle.php',
        'app/Domain/Hr/Models/HrGoalTemplate.php',
        'app/Domain/Hr/Models/HrKeyResult.php',
        'app/Domain/Hr/Models/HrLeaveApprovalChain.php',
        'app/Domain/Hr/Models/HrLeaveBalance.php',
        'app/Domain/Hr/Models/HrLeaveBalanceLedger.php',
        'app/Domain/Hr/Models/HrLeaveRequest.php',
        'app/Domain/Hr/Models/HrOnboardingChecklist.php',
        'app/Domain/Hr/Models/HrOnboardingTemplate.php',
        'app/Domain/Hr/Models/HrPayrollRun.php',
        'app/Domain/Hr/Models/HrPayslip.php',
        'app/Domain/Hr/Models/HrPolicy.php',
        'app/Domain/Hr/Models/HrPolicyAttestation.php',
        'app/Domain/Hr/Models/HrPublicHoliday.php',
        'app/Domain/Hr/Models/HrSalaryBand.php',
        'app/Domain/SecurityDevices/Models/Device.php',
        'app/Domain/SecurityDevices/Models/DeviceGroup.php',
        'app/Models/CalendarSyncBusyBlock.php',
        'app/Models/CalendarSyncConnection.php',
        'app/Models/CalendarSyncEventLink.php',
        'app/Models/CalendarSyncMapping.php',
        'app/Models/Integration/Integration.php',
        'app/Models/Integration/IntegrationEvent.php',
        'app/Models/Integration/IntegrationProviderConnection.php',
        'app/Models/Integration/IntegrationSiteConfig.php',
        'app/Models/Integration/IntegrationSiteSecret.php',
        'app/Models/Integration/IntegrationSyncLog.php',
        'app/Models/LocationHardware.php',
        'app/Models/ItApiRequest.php',
        'app/Models/ItAttachment.php',
        'app/Models/ItAutomationRun.php',
        'app/Models/ItCatalogItem.php',
        'app/Models/ItCatalogSubmission.php',
        'app/Models/ItChange.php',
        'app/Models/ItEmailDelivery.php',
        'app/Models/ItInboundEmail.php',
        'app/Models/ItKbArticle.php',
        'app/Models/ItKbInteraction.php',
        'app/Models/ItMailboxConnection.php',
        'app/Models/ItMajorIncident.php',
        'app/Models/ItMajorIncidentUpdate.php',
        'app/Models/ItProblem.php',
        'app/Models/ItProvisioningRequest.php',
        'app/Models/ItProvisioningTemplate.php',
        'app/Models/ItProvisioningWorkflow.php',
        'app/Models/ItQueue.php',
        'app/Models/ItService.php',
        'app/Models/ItServiceIdentity.php',
        'app/Models/ItSlaPolicy.php',
        'app/Models/ItTeam.php',
        'app/Models/ItTicket.php',
        'app/Models/ItTicketApproval.php',
        'app/Models/ItTicketComment.php',
        'app/Models/ItTicketEvent.php',
        'app/Models/ItTicketLink.php',
        'app/Models/ItWorkTask.php',
        'app/Models/Queclink/QueclinkAuditEvent.php',
        'app/Models/Queclink/QueclinkDevice.php',
        'app/Models/Queclink/QueclinkPendingCommand.php',
        'app/Models/Queclink/QueclinkPreset.php',
        'app/Models/Queclink/QueclinkRawFrame.php',
        'app/Models/CredentialType.php',
        'app/Models/SiteCredential.php',
        'app/Models/SiteCredentialAuditLog.php',
        'app/Models/SiteFacilityZone.php',
        'app/Models/SiteHouseRoom.php',
        'app/Models/SiteHouseRoomHistory.php',
        'app/Models/SiteHoResource.php',
        'app/Models/SiteRoom.php',
        'app/Models/SiteTypePlan.php',
        'app/Models/SiteTypePlanPin.php',
        'app/Models/SiteVendor.php',
        'app/Models/StaffTimeOff.php',
    ];
}

/** @return array<string, string> */
function itSecurityHistoricalTenantMigrationFingerprints(): array
{
    return [
        'database/migrations/2026_02_08_000001_extend_sites_for_types_and_flags.php' => '6cabbca0d5870992a6052d2e2f40361b9f76deba32484fda0170c09447bd134a',
        'database/migrations/2026_02_08_000002_create_site_calendar_tables.php' => '70a821e7e4ce9767040f6ba41462fe885df7d384e4f4ee55ac0653e37ad713a9',
        'database/migrations/2026_02_08_000003_create_site_hazards_tables.php' => '9193f481f14a72f0aeddfe0e2a7b18425b6c77c55f1f8d0b2f09a5450527428f',
        'database/migrations/2026_02_09_000002_add_tenant_columns_to_sites_domain_tables.php' => '695e8fd798514b9ea24d16ed922f656c9a894c92257c9012f3917813bc0e7208',
        'database/migrations/2026_02_12_000001_create_integration_framework_tables.php' => 'a2f502b857a2a9f0d2f656cc530db040fc47070597960e8896a69d7dfac6b943',
        'database/migrations/2026_02_12_000002_create_location_hardware_tables.php' => '4ab4c773eba0440f4dec3568501a38370568fcee276592874e85765ef2a4bd47',
        'database/migrations/2026_02_12_000003_create_integration_events_and_alerts_tables.php' => '8e9b77d79dbd94308d4235129f387ab47b102fae75f57567633961c12758582b',
        'database/migrations/2026_02_12_100001_create_hr_recruitment_tables.php' => '8c2f8b02460031e5d8487a730da75bd0dd79c69433fab116438c1c9f5463e481',
        'database/migrations/2026_02_12_100002_create_hr_employee_profiles_tables.php' => 'a346b7b11db37e8167d5b8dcfb19c7a1276a4c309b37fc39c7ffc65a48690f8c',
        'database/migrations/2026_02_12_100003_create_hr_compliance_matrix_tables.php' => '1ceffd4b3fc522f31596c5f9c467f5b0592049d02bc2258982df4507844f1356',
        'database/migrations/2026_02_12_100004_create_hr_leave_tables.php' => '20fd4e413b278bb35a8b408987503360203a324b92258440d1152ce5937cab6f',
        'database/migrations/2026_02_12_100005_create_hr_onboarding_tables.php' => 'efae5f7eb3c236e9b8f8a24ecccf3e8f0a64c61eac5bc18dff0f16907f3ff9cf',
        'database/migrations/2026_02_12_100006_create_hr_performance_tables.php' => '88c9ab961b215809e98c6988851ca7117db92df7b6808e41a20ea3b18dec5362',
        'database/migrations/2026_02_12_100007_create_hr_cases_tables.php' => '2ba9908d9381634df391c2fa21e865510b1673b7e3a1833db2531104c8c47867',
        'database/migrations/2026_02_12_100008_create_hr_policy_tables.php' => '4c1e5f34f1619c2e25cfb07a16c693aace320860de633a4ed4ea8a7970365057',
        'database/migrations/2026_02_12_100009_create_hr_documents_tables.php' => '802e6683751951ced6a1ec7207f327fe69d99da7a7d03abca52e9f8626faa7d3',
        'database/migrations/2026_02_12_100010_create_hr_payroll_tables.php' => 'fb08358231a203935247a10d0ee6a5beef075b11e9c4cd498ceb335e62e1a968',
        'database/migrations/2026_02_12_100011_create_hr_driver_eligibility_table.php' => 'a2c4b9558df9cd517158f306e1ba0a13c0c715c927198aeb9e5b885c83efe046',
        'database/migrations/2026_02_12_100012_create_hr_wellbeing_indicators_table.php' => '35ea54b874afd8bdd47c141c2369d6ed71f5b37045cf892a6c03821c4cc0bc9e',
        'database/migrations/2026_02_13_120000_create_roadmap_module_tables.php' => 'bac08cc33e0cc7872f8083f036659579616963bbe605000b08fd4f2c81c6b642',
        'database/migrations/2026_02_17_220000_expand_hr_workflow_foundations.php' => '600ebcfeece3ebe755de71ec659e04b6a0fbccbba0ff6936fb3a6f57a62ef92c',
        'database/migrations/2026_02_17_230000_expand_hr_ats_and_candidate_portal.php' => 'd60b705c0e287cf74e9c096e8a8bb3b501cead6fd9745556a397f049be197125',
        'database/migrations/2026_02_18_000000_create_hr_engagement_and_development_tables.php' => 'bee61023bcd6f435450522dae57ed9a563455c1bd6293ab3a4bbb6caebb935e4',
        'database/migrations/2026_02_18_010000_create_hr_attendance_sessions_table.php' => '7e7dc5d5b1f6a8479d8327caf75364a130e6a9706260b48a1c0b47ad1b268c30',
        'database/migrations/2026_02_18_030000_create_hr_report_automation_tables.php' => 'dff7f9cdd24161e0756cbd127df2a2731ef46a4b224dcf507f7837d6b1d2a6f1',
        'database/migrations/2026_02_18_050000_create_hr_webhooks_tables.php' => '909711ae7cc5ae818620e3b4a1d1ddbc07090b679a9dc7467ff6af67f0791bc3',
        'database/migrations/2026_02_18_060000_create_hr_automation_rules_tables.php' => '1dddb70133c1ae20d6f90ef12c8c979fce20e4440c6eecd1007a401a9b0e8e73',
        'database/migrations/2026_02_19_120000_create_hr_payroll_export_profiles_table.php' => '5f2602822ad7739f72ed22446e9273aa67bdaa4ee9a86722c156848b6e4ab853',
        'database/migrations/2026_02_20_000001_create_site_damages_table.php' => '531c5b9c33ca1f4a132dc0b70c6b1e967181eb3f00f8fe1401c0e18d8daa25d0',
        'database/migrations/2026_02_20_000002_create_house_ledger_tables.php' => '50e9a456a6e0bf946c841ee22108c2f214e68ae72a153798e5615cd6bfbc6aab',
        'database/migrations/2026_02_20_000003_add_site_id_to_site_checklist_templates.php' => 'ec69c435b48eabca07daf3423d938b0ec2461444f5b62a775dafeeed46557af1',
        'database/migrations/2026_02_20_210000_add_missing_tenant_ids_and_reconciled_by.php' => '481b6619fbc516233572ceafbd50233335ae8b9edea8a9e9a4aeef239d2de21e',
        'database/migrations/2026_03_22_100001_create_hr_positions_table.php' => 'fadf725d25764c8fb20add9a3b121c52b3e2c8cfa47c7b5dbd72b22acce00dcf',
        'database/migrations/2026_03_22_100002_add_org_chart_fields_to_hr_employee_profiles.php' => 'd5fa9a616b38909ccefecab71e177e285b0e2ed628edba2ce0bb540cd33c7f54',
        'database/migrations/2026_03_22_100004_create_hr_time_tracking_tables.php' => '160e1cd4c64e7845c90a2f1cb8e46f4534d267810045f75da349d3dae0b4328d',
        'database/migrations/2026_03_22_100005_create_hr_compensation_tables.php' => 'a7107eb7d4cd41f5000968155948990e6ec6d23d9a9f81c6075483a6ec19cad1',
        'database/migrations/2026_03_22_100010_create_hr_benefits_tables.php' => '3367f9b9d573f8a8cbb8f640f9f2b48d6c0c00499ea8aa669e047a8f1039bb55',
        'database/migrations/2026_03_22_100011_create_hr_goals_tables.php' => 'fb419dfa0ac32fae9a524e76be560aaf9ac7ca8c6fc8fe8111fa807bf6fe23c8',
        'database/migrations/2026_03_22_100012_create_hr_training_tables.php' => 'f64a4ba356d58c688a0994081115bb46a02fef5d286ab9dc3d800444bfde3e69',
        'database/migrations/2026_03_22_100013_create_hr_assets_tables.php' => 'a987f6621041aba13f0efcc5d5f293a63e3318b4387049c3f3d3bc8d409d06fb',
        'database/migrations/2026_03_22_100020_create_hr_survey_tables.php' => 'aebb36eac5c0e6b3756e680320639bc9fa0de47ceb0cfe3fb1d76982ea5dbaeb',
        'database/migrations/2026_03_22_100021_create_hr_expense_tables.php' => '84b116d982516694c3743ab0da8e86dc58a89574807e6bda6270a96c5b022b3c',
        'database/migrations/2026_03_22_100022_create_hr_skills_tables.php' => 'ee3dfe704d7c4f9653e06b42b8af9664f4f3b4d1f071eca6cf0b816637cf8f93',
        'database/migrations/2026_03_22_100030_create_hr_calendar_events_table.php' => '7cf8db038dd5d6b2cb15d59f73d27cd2b925d31d186cd04348d57cab9c6f23eb',
        'database/migrations/2026_03_22_100031_create_hr_announcements_tables.php' => '4005f9121e6180475245cabba78f2e17f4b6d200c476e476b2cffdac896c0507',
        'database/migrations/2026_03_22_100032_create_hr_exit_interviews_table.php' => 'ba43edb235d5da825a991bbabed3b47ec2414cee4490ad523e316c5f86a1725d',
        'database/migrations/2026_03_22_100200_create_fleet_vehicle_bookings_table.php' => 'ad63a3a747b8167bb55f6238f347a0f14c82533546f45ba7485645a52a1bac4e',
        'database/migrations/2026_03_22_100300_create_fleet_maintenance_tables.php' => '486507fe939cb84fa65b054f7cb24767f72c015e71963fbcf7960b6c28ee7cf5',
        'database/migrations/2026_03_22_200001_create_hr_approval_workflow_tables.php' => '035e42ec25c19ce49ca8d89ee37cbcd57868e6cadc314016c79752d22d95b09c',
        'database/migrations/2026_03_22_200002_create_hr_document_signatures_table.php' => '58ae56c26349960b9c73538056f8d72428f914425c33e2d19f4565c346ae0ae9',
        'database/migrations/2026_03_22_200003_create_hr_payslips_table.php' => '89cf03f6f76e93ff459009f39837349cb5b9a7b57d4b43cafe7c8c84c92c2842',
        'database/migrations/2026_03_22_200004_create_hr_saved_reports_table.php' => '1cff3409de98c1c738c8df87df3dac874842cbf2b6865767810be70e6b1bac58',
        'database/migrations/2026_03_22_200005_create_hr_public_holidays_table.php' => 'a8b9a6cf1a4ec63cdd5d0cfe0ccf64b301f3d9dfee98cbb1ba61741b1c82709b',
        'database/migrations/2026_03_22_300001_create_hr_feed_and_kudos_tables.php' => 'f26cea9767a4d1b5f5f2cb3bfedac89aed6da2cd02102ca15f75bb9225c25ba7',
        'database/migrations/2026_03_22_300002_create_hr_feedback_tables.php' => 'c0848d77b4a806b9bedaccaea2c778b79f71e5e473a1eeca7217a884fab93cb6',
        'database/migrations/2026_03_22_300003_create_hr_job_postings_table.php' => '94e6d4df1cf9dd24e8d26ca8048e0f7bc781a38d3310c51673831a7a0da40724',
        'database/migrations/2026_03_22_300004_create_hr_checkins_table.php' => 'a40420016b6fb51163c3bf28e1e2f2006cf56c74ccf6396c7f2330610ca25569',
        'database/migrations/2026_03_22_400001_create_hr_webhooks_table.php' => '01b0c8cf479569e5d776a05dd7e538c2ee1cdbffbb7f801c54d434b21018b866',
        'database/migrations/2026_03_22_400002_create_hr_custom_fields_tables.php' => 'd8d14d31d3f7a3795d544263bd725ee769822afbee8a05235ce87764a23d0cbf',
        'database/migrations/2026_03_22_400003_create_hr_audit_log_table.php' => '13b76b2331db753c925eb7ba0d4a16a57b4574ebe1815c111958294cf49c1fe6',
        'database/migrations/2026_03_22_500001_create_hr_employee_status_changes_table.php' => 'b2fddd1457f957160f4a0fd7acbc96efee7da885d70b185bc7497bc407042755',
        'database/migrations/2026_03_22_500002_create_hr_interview_scorecards_table.php' => '1b20a58cefe10bd1706f119ff400565cc96a83eb2c70b60f7c3cabed5d519d89',
        'database/migrations/2026_03_22_500004_create_hr_pips_table.php' => 'fbe63f79a330250807239980e07b7658a7c882a157c8f0036c575561f9089bc1',
        'database/migrations/2026_03_22_500005_create_hr_competency_tables.php' => '938187beacbf0d749bc29e22072acd7670e23b06f00a48cef492f1d8de7ce5d5',
        'database/migrations/2026_03_22_600001_create_hr_succession_tables.php' => 'ece8ee057945a907a4588841b4678e9949d5c23c67e2f0c8d645d74b484c5218',
        'database/migrations/2026_03_22_600002_create_hr_bonus_payments_table.php' => '7afcd9bfb9b92cfbfd056132d17198afed3366f3a91f559e493cf0bd093fa15f',
        'database/migrations/2026_03_22_600005_create_hr_onboarding_emails_table.php' => '0c2a38a50c130ad6a50b1ea1a597da5d06c1c3871eef11cc893b26f6e5c9e105',
        'database/migrations/2026_03_23_000100_create_care_plans_table.php' => '4fb4a3205b8abd7b06a153c1b96ab0f3ea874d9e254557b4862cea296ec6aa2e',
        'database/migrations/2026_03_23_000200_create_care_plan_goals_table.php' => '5ced1929f80a50790449daf1030a49b6914334e48ee7f3c7e18f94e539cd63a4',
        'database/migrations/2026_03_23_000300_create_progress_notes_table.php' => 'd659e04b18d32749c1abbfcb50189b602b105411974a9108e0a7321ef5477fc9',
        'database/migrations/2026_03_23_000400_create_service_agreements_table.php' => '28d343b406bd1674bc20c03217abcf3daa8f8c9a2454f5c563ffd3f98f3a4021',
        'database/migrations/2026_03_23_000500_create_service_agreement_line_items_table.php' => '92b65082c5e83c1cd1f39a095b7c3e0769f40882755b3c996b9eb4b0bccfd00d',
        'database/migrations/2026_03_23_000600_create_funding_claims_table.php' => '5f43501d732483b3d1c039f291dfb3ed93468526de884288ac224f9cf52846b1',
        'database/migrations/2026_03_23_000700_create_funding_claim_items_table.php' => '3c0bd88cac8ab742d7d9268617eea77fd692e2aa8eff351a608a84605c3ade2d',
        'database/migrations/2026_03_23_001000_create_shift_notes_table.php' => 'dbf083cf5cb4fa120d5d4f11d1b096482045e75074c14e114c65e7ba27556da6',
        'database/migrations/2026_03_23_001100_create_shift_handovers_table.php' => 'eabcf826080002732b77c5c056e7cfbb79d543638a45215351f7bfd96b153cae',
        'database/migrations/2026_03_23_001200_create_shift_gps_logs_table.php' => '9f1281339949bc20034d4df1316f1abc87f89e8578165455488c0bae8549561a',
        'database/migrations/2026_03_23_001300_create_roster_templates_table.php' => 'e314e429d2ac7fc2a232dd6b2b631c0511504c1885420c23ad11a0294a2391a2',
        'database/migrations/2026_03_23_001400_create_roster_template_shifts_table.php' => 'ba6dd2b4fcf67d6ca3349eb705718556fcc674974153272066b96ab015e240e7',
        'database/migrations/2026_03_23_002000_create_billing_entries_table.php' => 'efbb12c381cecaad3e2bea1ca7c45758ac81adb07e6ebabb6ddf296e133e3e9d',
        'database/migrations/2026_03_23_002100_create_invoices_table.php' => '0262a1df0108f619e5aa33ca6f94addf721ee4bed1d814da1ad7da06fb183afe',
        'database/migrations/2026_03_23_002200_create_invoice_items_table.php' => 'a462922fbfe84bb70d78b65c5b86a95e6399fadd17fc7aa507aec98fefb821a3',
        'database/migrations/2026_03_23_003000_create_ops_conversations_table.php' => 'e3d0a5960961f0e3787cb283fe4b6fcbd9acdd1835384a85d103723cc21900ba',
        'database/migrations/2026_03_23_003200_create_ops_messages_table.php' => 'f93474d46f9aeda74d18d2822eb4bd4eeb178caf2aa48dd918772d0c4d0a9d88',
        'database/migrations/2026_03_23_004000_create_price_books_table.php' => '82b611a84f47618e3af973bf54949620c33b3f5e1dff214f4714d7ea51ec0b0e',
        'database/migrations/2026_03_23_004100_create_price_book_items_table.php' => '2d51a2bf3e8b72ffd5d86199d6e5a858d25f2a4091e44072a5ae91962ef03b82',
        'database/migrations/2026_03_23_004200_create_quotes_table.php' => '17de1742845bdf0483a9070537b6e8f78e847af3194f9039cacb9a3465c820fe',
        'database/migrations/2026_03_23_004300_create_quote_line_items_table.php' => 'bed1fe675e64519a9874361503a4cafbdbeb3272c1b4e18de3d3a868f63265cc',
        'database/migrations/2026_03_23_004400_create_client_onboarding_workflows_table.php' => '81418db5dd162ea80d6dbe4cf3471def2e5bdd1ecd03d08eccd10cd02778176d',
        'database/migrations/2026_03_23_004500_create_client_onboarding_steps_table.php' => '92258572044e0e4f2d1177594a325a27421c0da5d22d50967327bcba71315a81',
        'database/migrations/2026_03_23_004600_create_client_funds_table.php' => '2b679e216b6c9c9c1214dcc99f6db31fec4f40607278b4f13f258a4522304466',
        'database/migrations/2026_03_23_004700_create_client_fund_transactions_table.php' => '2e66c6cc891cb85a3b8c0d69967fdfb147e365c579c644eb91a7d6af0e45c7ba',
        'database/migrations/2026_03_23_005000_create_shift_open_positions_table.php' => 'd58ce443359d50aec3e26aec86677e6f0d20345072ad0b33836e2e784b1f9c4d',
        'database/migrations/2026_03_23_005100_create_custom_forms_table.php' => '165adfbb3bf4f8c48b9bf83aa6a834ed32debe5c47b86a13e5f74563210da0b2',
        'database/migrations/2026_03_23_005200_create_custom_form_submissions_table.php' => '6b3fdad5f775281f16eee9c4894ce515fbbaa6366738f62c835c5e1d4099f0df',
        'database/migrations/2026_03_23_005300_create_evv_records_table.php' => '02bb572e00d759a4c58ddee866ad8eccbd42c4cf011c6e1c063be3ebea0b4ad7',
        'database/migrations/2026_03_23_005400_create_family_portal_settings_table.php' => '3d6a46a4bb26fa0ab7ef1ecc045263b796914043faaa981ba1c0fa4795a7518d',
        'database/migrations/2026_03_23_005500_create_mileage_claims_table.php' => '647c8b025b65de0a14f77ce24595a58cf8a534fff4ca42cdbc9bdc44772a38e5',
        'database/migrations/2026_03_23_005600_create_recurring_charges_table.php' => '123ea0666058af106e6909792b1dea80e511ea2502a433303dde437210eb0bb5',
        'database/migrations/2026_03_23_006000_create_staff_qualification_requirements_table.php' => '00c6f74f68afb3671921233865509fde0d2d62f5e94ccb4d2ff130ac7d74edb4',
        'database/migrations/2026_03_23_006100_create_ops_notifications_table.php' => '091a01d310d91375d670fe94b8fbe06dcfb38a658816d5a7ab67474bc32095aa',
        'database/migrations/2026_03_23_006200_create_care_note_templates_table.php' => '002db16cf3562218858adc05d5d32348bdad05d5890d2005fbae3369a944be9d',
        'database/migrations/2026_03_23_006300_create_payroll_exports_table.php' => 'daa0fca92ccbadf2d321c8e6d796f7cd5b46d66a9857bd84dd1930e108d663dd',
        'database/migrations/2026_03_23_006500_create_calendar_syncs_table.php' => 'e26e8e8fa0343fde07431f58d22163dd37b1548ab6f775f233f76797f9acb929',
        'database/migrations/2026_03_23_006600_create_geofence_zones_table.php' => '1a642f0d452dbc59c0e00497fc870de22a9f9c9d98f1a767e8685ac4136e8a34',
        'database/migrations/2026_03_23_100100_create_fleet_resident_transports_table.php' => '4d0cbc4e6308f7b3d950c8cce134314a57f451ce0f27c766f00f6d3846e6341d',
        'database/migrations/2026_03_23_200400_create_fleet_medication_transit_logs.php' => '5234693511e48c56d80732e0cf804ddd46e8680b5c7e29cc09a8a01940eeeab3',
        'database/migrations/2026_03_23_200500_create_fleet_personal_trips.php' => 'a60973d0cc95890a8b4bcf0c790bad522979b65f88fe50f281291fd98336ef02',
        'database/migrations/2026_03_24_030000_add_operational_fields_to_client_notes_table.php' => '97cbe33873a55c45cc64dbea41ba1e7aedcfd3b633dfa9364d1d57498e2911bc',
        'database/migrations/2026_03_25_000200_create_service_agreement_rates_table.php' => '8e6eb8715a5330e02de769846b36faeff82f70e70ccafda34594d328e448edc8',
        'database/migrations/2026_03_25_200000_create_site_compliance_tables.php' => '953bd863ba099893d32ea50ee09e8f41c299ebcf672e5348bfb2587180b88bb9',
        'database/migrations/2026_03_25_200400_create_site_compliance_templates.php' => 'e63ac196dab54fc5de4bfa7902745100f82a27638df62b9fbf6f7a9fa2889d8b',
        'database/migrations/2026_03_25_200500_create_site_staff_requirements.php' => '0826a74c831f932ff2698b9d11877b98eda97fc9ed69f47ad2509c96c29a1283',
        'database/migrations/2026_03_25_200600_create_site_feedback.php' => '69f9e59271340b1d0b93fb546c841c3378aa23018a36867e91ab0ea0d27323cc',
        'database/migrations/2026_03_26_000500_create_sso_group_mappings.php' => '41727f253d589880a91bc2ed7f469a01523b25d1fa241642fa2c24e750073b71',
        'database/migrations/2026_03_26_200000_add_phone_and_notification_templates.php' => 'bde8db531c6f397bde6735781bb28115f2e2b429a3e4645e7dec90550d868a09',
        'database/migrations/2026_03_28_000100_create_fin_fiscal_periods_table.php' => 'd59bea51e1e7a0502081868dc8672cda7de6467e93e68b5f91bca18a2015c3d4',
        'database/migrations/2026_03_28_000200_create_fin_accounts_table.php' => '40f2b19b953267664856230bfb63f1c7fe6f6338bba722569ba297ec48c31181',
        'database/migrations/2026_03_28_000300_create_fin_cost_centres_table.php' => 'c0027c74111cd6c47d5ea884e83c6c27a3942d2b65ff2e3ec041cc22a394080d',
        'database/migrations/2026_03_28_000400_create_fin_funding_streams_table.php' => 'a3cdd0f6e99ba054704b0c330fa7e73dc8ee013597fd4c05b3c02849d9911113',
        'database/migrations/2026_03_28_000500_create_fin_tax_rates_table.php' => '8da146e243c18b4046148b8facfcbf028f3bd3782b1de90690bee884f0a055a7',
        'database/migrations/2026_03_28_000600_create_fin_vendors_table.php' => 'c2946e6678c17806280fffda4fd260b5049919dd428dd16507cef5b75e581e3d',
        'database/migrations/2026_03_28_000700_create_fin_bank_accounts_table.php' => 'f06aca49eaac854714b4c9d69502878b119b24dbbe61ed0ca75222faeaf8c707',
        'database/migrations/2026_03_28_000800_create_fin_fixed_assets_table.php' => 'c07ba83084ef3516dd9deddf19e3b760bc66453ab158e188c8000c1f368b2b46',
        'database/migrations/2026_03_28_000900_create_fin_petty_cash_funds_table.php' => '37970d17b48f707cc1f1142bec344d98f759c5d45ea3e1bcae11587aa7783595',
        'database/migrations/2026_03_28_001000_create_fin_journals_table.php' => '2468357f53468d4744b59432014c60b8a7a17a41644e02846ac2210f89d9dbcb',
        'database/migrations/2026_03_28_001300_create_fin_purchase_orders_table.php' => 'a610772f349ad567752ec63d75980e16105b7886971a5ca5dda2e03b8b49451a',
        'database/migrations/2026_03_28_001500_create_fin_bills_table.php' => '22cb697cd3b375988d56d0879b8d97dc01ff5a59832fac03de6920808de9eb83',
        'database/migrations/2026_03_28_001700_create_fin_credit_notes_table.php' => '8fc4d428c2fc0e9c9d7004f74c860e66d6f5318876ea676fa83c6772cf201d86',
        'database/migrations/2026_03_28_001900_create_fin_payment_runs_table.php' => '62509e5818c0aad1b37bb08a9dd5560040498e72189d68c2f1853a4d35abbe99',
        'database/migrations/2026_03_28_002100_create_fin_payment_allocations_table.php' => '7b5e3b489b212646f2d682b74149ace3c353878b7ddd9b89e665eeee031aadd8',
        'database/migrations/2026_03_28_002200_create_fin_bank_transactions_table.php' => '79ace29de8c8c02f28c0b31aee6ec9dcfb022470e4aed43939d9d98b94da215f',
        'database/migrations/2026_03_28_002300_create_fin_bank_reconciliations_table.php' => '53581c6ea3ec7ef2ed19559bbb5614293b4c97d0f841ce6eecf47af7780a5c09',
        'database/migrations/2026_03_28_002500_create_fin_gst_returns_table.php' => '88e1bc9e682e4f18b0bc3c84578f9eb23bbd45d2d2e1919a10b57d9df528d259',
        'database/migrations/2026_03_28_002900_create_fin_report_snapshots_table.php' => '535887c834835ff2afc7cbbafa0b22adbd636688d6f70312ea3045041a627ec5',
        'database/migrations/2026_03_28_003000_create_fin_recurring_journals_table.php' => '0372aef04dc9331f19e9e6fc3c24d4254ba323a7704ebac311c7a61a8d946a13',
        'database/migrations/2026_03_28_003100_create_fin_currencies_table.php' => 'b543815d6be1f6ee4d33cd14d35f7585ec8f6f1145722f01fe62cb1cbba22f77',
        'database/migrations/2026_03_28_003200_create_fin_fx_rates_table.php' => '89afbc266a02d20260da55e48a244a52cf1adbd3634b58b3dee1fcf71f6ebd2b',
        'database/migrations/2026_03_28_003300_create_fin_fx_revaluations_table.php' => '9b2aa38a4e08c599cbaa64052374a9e54d9946b606f9c99caa5d042425ae8ec0',
        'database/migrations/2026_03_28_003500_create_fin_bank_feeds_table.php' => 'cf3fb76d32696aeebaf572afae36e4f222db58505c275e9085d9fa6c921a4de1',
        'database/migrations/2026_03_28_003800_create_fin_accounting_integrations_table.php' => 'a13ceadc30b33251e63dfca46c6fa74db8eed1a35a2245d2415280fd97c71154',
        'database/migrations/2026_03_28_004100_create_fin_invoices_table.php' => 'c41d8e37e7ca9a450f1eea209f0148829c46a6c5b050204646491b9305f619b5',
        'database/migrations/2026_03_28_004300_create_fin_audit_exports_table.php' => '90cd2709f5ab7a85f1d58ff43fa136029b491e16fe199ac20e5c1d90bd40da85',
        'database/migrations/2026_03_28_004400_create_fin_payment_matches_table.php' => '7aab1615d44f6bcc44f76d7e379cf87e699e2878b0b3efd66abcf62a95f0b258',
        'database/migrations/2026_03_28_004500_create_fin_match_rules_table.php' => 'b45f9d7784543f9ad1a8b170357b0e12ba07d7294d14c80407bb1041eb622cf1',
        'database/migrations/2026_03_28_004700_create_fin_consolidation_entities_table.php' => '2975a78d8a1425daf2fd4a7a547e2f67f813e918eecdb9aeaa85272b21539163',
        'database/migrations/2026_03_28_005100_create_fin_cash_flow_forecasts_table.php' => 'a9b7bea53ec040a707a96ed4b1eeb62790eed2a9f7221b7aea07f3143869764c',
        'database/migrations/2026_03_28_005300_create_fin_ird_filings_table.php' => '67832f99fb76df5dee18642e47197947a752ef3f688935e8da737c3b30abbd0c',
        'database/migrations/2026_03_28_005400_create_fin_eftpos_terminals_table.php' => '75d1fd4aa2bbc97bf2624d96bab570a83a8c53e5cd0382fecae433ead22d05ca',
        'database/migrations/2026_03_28_005500_create_fin_eftpos_batches_table.php' => '8a2d1f6ecd20610f3f4300c2e6b08db935d0a829c54dbac68a55106c645eb62a',
        'database/migrations/2026_03_28_005700_create_fin_donor_funds_table.php' => '60d46a6fd73ed5d18f1f0172be482faacd8fff97db43c9a875e4fc70e2604c4c',
        'database/migrations/2026_03_31_000001_create_hr_departments_table.php' => 'e4c1cf123823eb908d6d053e8ac101de769bed50b056ed00b95e53dade810e0d',
        'database/migrations/2026_03_31_200000_create_hr_feedback_templates_table.php' => 'ef87fbf970aa0d40d79382f6614599423f781af7fd9a9285326d3cb5290d02a9',
        'database/migrations/2026_04_01_100000_enhance_hr_job_postings.php' => 'd70a5b2e6470728dbed1beb102f8dbb54947074c688e5308132aa0b4731f8812',
        'database/migrations/2026_04_01_200000_create_hr_candidate_documents_table.php' => '7eebfe7a9837da830a057a08ed4b2e15e086e16deee4d9709747d6ba25c1e57b',
        'database/migrations/2026_04_01_400000_create_hr_key_results_table.php' => 'c964ba51b643ef898d8d3f9fca84315014ec1f24cbd6296a4d7e09193448b476',
        'database/migrations/2026_04_02_200000_add_manager_amendment_fields.php' => 'f5b579936148c65feda8f2a85a64526cea1746ecf8d23ba8bcd8489494028d77',
        'database/migrations/2026_04_05_100200_add_tenant_id_to_fleet_tables.php' => '140afef7765dc562fba913ce621d79a8153283c16eecdb45f4b82ed2b12052d7',
        'database/migrations/2026_04_05_235000_create_site_coverage_requirements_and_link_sites_to_shifts.php' => '87d4e1d9718e60c42c70d44d913f3815c9c030023d09c5b0dce66a9903993a21',
        'database/migrations/2026_04_06_010000_create_shift_replacement_requests_table.php' => 'b9ae05212f6d5a72f73adeb12bac5da6c0120b24552bc0e6728b43b58699607e',
        'database/migrations/2026_04_06_120000_add_phase4_coverage_controls.php' => 'e9444223462d49999df9c4d563d7d4d6a1179a87c0054fcb1670c89ec9e46424',
        'database/migrations/2026_04_09_120000_create_financial_events_system.php' => 'bab4dd11df9f3e51c8e87cf9d17b009f0a2f0db7078475e77a1ee96ee3be5bd8',
        'database/migrations/2026_04_09_140000_harden_financial_events_system.php' => '6cecf76f484c1951b925dc2c514a4212b4e00e7b5adc911427e5287ae3e14812',
        'database/migrations/2026_04_09_160000_site_financial_integration.php' => '4454f0018a1f93e2f7deaed536e624dc593c220e6e627f237052f07c94b8ac1d',
        'database/migrations/2026_04_09_200000_create_client_ledger_system.php' => '3390e5de20026ee64eaa67de645a0946b45ca60a0aeb31161776d181cbd2d00a',
        'database/migrations/2026_04_09_220000_create_site_budget_system.php' => '24369fa8eb81cfe14ef35945bbd79b6b810acd88ae1f67c59fb85716cccf90fd',
        'database/migrations/2026_04_10_140000_create_hs_events_table.php' => 'f022b728fbec73e7c5710878f95f7d42adc7f884e9a1f918a7b90463843b5bf4',
        'database/migrations/2026_04_10_160000_create_hs_investigations_table.php' => '0fbc0b2bf27a8ccd755ea2ab427cb8a521124c8e8a29328c385c3cb7107a8515',
        'database/migrations/2026_04_10_180000_create_hs_corrective_actions_table.php' => 'db497f716003f1bbbaea8862ecb4cbb6bd988573067706decae9cc79076d533c',
        'database/migrations/2026_04_10_200000_create_hs_risk_assessments_table.php' => 'c2c747a34ebd6494636fb9b41e16eaa64471d0cd36ed9eb613d72eabec8a6de3',
        'database/migrations/2026_04_10_210000_create_hs_training_requirements_table.php' => 'aa013dbf956fbd301a1a9ace488f071303313cb39d5ea247dacc87deec9021f6',
        'database/migrations/2026_04_12_000001_create_missing_fleet_incidents_table.php' => '5f0da5a092a3cc9b951363844c2af9f6f8bc41f4f3b518a4f0e397860c54ff97',
        'database/migrations/2026_04_12_060000_backfill_missing_fleet_incident_columns.php' => 'fd9d54b64ca1227ab94b3b2f73ed4c73de10fd5695c03bb115d55877b086c1e3',
        'database/migrations/2026_04_14_000001_create_security_devices_tables.php' => '5a5a0b812a5c6bac6514a94c12612e9834d040e58a2de1221ba3ad4bbeba6683',
        'database/migrations/2026_04_23_200000_create_missing_fleet_shift_handovers_table.php' => 'a9220198c2aa74338fa60b0120f6b611fb752a7aa9f45af4724100fc53674270',
        'database/migrations/2026_04_24_000100_add_organization_scope_to_users_and_clients.php' => '722835751a92ecc2db1900adb6713979287635157795b05148b0e60c44d4173a',
        'database/migrations/2026_04_24_000200_add_organization_scope_to_shifts.php' => 'd6b5ebf40f37185353c89fd4221cbf254f6914cd05fb378f0549675ee0adf400',
        'database/migrations/2026_04_29_000100_create_roster_periods_and_publish_columns.php' => 'b8cd629bc468862f0725f8c5aaafb0610e1ce70356f01e84b7bb0991b5d0d668',
        'database/migrations/2026_04_29_000200_create_roster_suggestion_tables.php' => 'be70ca87c6133b963a8592046b96ad6242f5b4113d98ddcda2831af8bf9cf38b',
        'database/migrations/2026_05_01_000100_add_gl_posting_columns_to_fin_invoices_table.php' => 'df8e981bfe180713a40de3b521ca277ce59f38da94cc0daa5537d4714e0d9620',
        'database/migrations/2026_05_01_090000_create_coverage_gap_acknowledgements_table.php' => '035d1548deb665ffb66e60c39976c19a59ddcc63daf537b71605e04d203aeb9f',
        'database/migrations/2026_05_01_100000_reconcile_finance_chart_config_accounts.php' => 'c7d52e42ea6cf736e32bf344dfa9610cf5c74339804b6ac4887c419d76f8812c',
        'database/migrations/2026_05_01_211000_add_posted_payroll_journal_source_uniqueness.php' => '613a7ca63ab48e18c18d86ef07605578616efaf395a4c6947b9c27ed59a22a7e',
        'database/migrations/2026_05_01_212000_add_eftpos_settlement_journal_support.php' => '8943b329c220f501cb4146b60170a23c6e399a32e43a42ce09c9035c8a5cf0a7',
        'database/migrations/2026_05_02_100000_add_operations_metadata_to_fin_invoices.php' => 'b537b2df53d0307c8eba8464db0d572eb1470232e2d2a03bbef2991818e4a8e8',
        'database/migrations/2026_05_03_000100_scope_integration_event_deduplication_to_tenant.php' => '5da5f1e692d1b304b5e93c7734b125cfae2a22e8d176b569f0d07b8ca0ea0b83',
        'database/migrations/2026_05_11_120000_create_queclink_devices_table.php' => '4f1096adf7ff2f945d0c7ef5cdd5bf82cc0a51d41f9a1b3ef49a1bca9f58d6c3',
        'database/migrations/2026_05_11_120001_create_queclink_raw_frames_table.php' => '23ae73eb84c91f1ded6162ac59d0b647f83e82b8361a406b360aa5c42c628248',
        'database/migrations/2026_05_11_120002_create_queclink_pending_commands_table.php' => '99981e873e9f7ee41f33b8c084d168090ea45a4cb9fb4b6a9cfce90163c246d8',
        'database/migrations/2026_05_16_000001_drop_legacy_site_contact_scalars.php' => '357129a806cdbe20764b434e758217101395fa62585b59e13b0bd40f1780e796',
        'database/migrations/2026_05_16_120000_create_site_type_plans_and_pins.php' => '0776958ea856e007c09bb0aff509a14836e40652347be1171ad6f327d3a0bd45',
        'database/migrations/2026_05_17_120001_create_meal_dietary_tags_table.php' => 'b6182a2d056dd2830714c790dfcdf6a2fef6135ed0a77b6707f1318f8e7f054c',
        'database/migrations/2026_05_17_120002_create_meal_products_table.php' => '14d578d943dfe609fe8c8e731e6a1e9a023c47836da333ee5f50d3791a48f8f1',
        'database/migrations/2026_05_17_120004_create_meal_recipes_table.php' => '20aea2f5ae1a7d2e0a8713d749bd844d7980dd44539a9bcb2054224914d07034',
        'database/migrations/2026_05_17_120008_create_site_meal_plan_entries_table.php' => 'dc5fb52223d9cf1e08bb311e5d6ed8ccaefadd1b3e25aa6fb78e6dc7b9c8f6d9',
        'database/migrations/2026_05_17_120009_create_site_meal_inventory_items_table.php' => 'c7026aa5aadd6cfa906030341dd268431a9edf74c87e3bc3af0e3f5d3ad62c26',
        'database/migrations/2026_05_17_120010_create_site_meal_inventory_movements_table.php' => '5045b38f5300cacb6237a3542265523fed67ae13c5c0453638640f317e835b00',
        'database/migrations/2026_05_17_120011_create_site_meal_shopping_lists_table.php' => 'd7913c5b0936273543ef47a8198682f18bd443f95e603789509bd61f1caeec2a',
        'database/migrations/2026_05_19_000002_create_queclink_audit_events_table.php' => '04aa4c345a46ca2ccc530f687e258996eccb17cab6107f616931ad013791963a',
        'database/migrations/2026_05_22_000002_create_client_health_and_routine_tables.php' => 'f377822335a001489d13b2a3fed4bd4813bad489f05e1ad78dc91847614d8edd',
        'database/migrations/2026_05_22_000003_create_client_leave_and_excursion_tables.php' => '7b67987764c2667285fb68126b974fb95edfce267bcc8e175594e945da2ccce2',
        'database/migrations/2026_05_22_000005_create_client_path_plans_table.php' => '150cf0916a41ff50be4a8c1f3ff52338d89a849e9d1350313bf7480472489dd6',
        'database/migrations/2026_05_22_000006_create_client_purchase_requests_and_discrepancies.php' => '0719b602b2e2d9983467cdc91daabbd1e2ae6c1a0f342770a98912602c6f4e34',
        'database/migrations/2026_05_30_000001_create_queclink_presets_table.php' => 'e68557599d6953b391844c8052d29cb9e660c478a681f3ee77283b24596d364e',
        'database/migrations/2026_05_30_000002_add_org_status_expires_index_to_shift_open_positions.php' => '8e5bc09484439724e0928c5551bfe524ed0ca03a77c21104e40089d72afa8b1c',
        'database/migrations/2026_06_01_000001_extend_credential_audit_actions_and_index.php' => 'f669dcc1bec0ab033b7de22fd92b84c63ac7c2f8e48e492015aeb9557bad970c',
        'database/migrations/2026_06_01_000003_create_credential_types_table.php' => 'fa3c8d1b8e831176d5084dec6a23e8418e5df4bae8e5add25ff20439f6999db3',
        'database/migrations/2026_06_02_100002_create_site_meal_week_templates_table.php' => '2f826fe9fb614b6058cbfa890d182b9ed9e4e2bc48b024f500c89e274e9191f9',
        'database/migrations/2026_06_04_130200_create_site_emergency_plans_table.php' => '06e1467953459e2cdc030d0eb9fef125615c50ed560b888368bb73db920e6137',
        'database/migrations/2026_06_04_140000_create_calendar_sync_connections_table.php' => 'f942d8b3d68435a42820d16514722ed029c5aa10963896de454c367c171c3188',
        'database/migrations/2026_06_04_140100_create_calendar_sync_mappings_table.php' => '6b4ac5cd9e68b66cc9be19c2a8b2bde92942854d9a3ea1ade5013822eb09e1cc',
        'database/migrations/2026_06_04_140200_create_calendar_sync_event_links_table.php' => '72251fbc56ed4ae97d106cbcb53ccd5178097b1030b31dced070449a86d863fc',
        'database/migrations/2026_06_05_140000_create_calendar_sync_busy_blocks_table.php' => '56fbd2e55e88f48e06364f4d810d8ac41540cc9a64fc128da2d8f1758a17635b',
        'database/migrations/2026_06_11_100001_create_client_transport_bookings_table.php' => 'ed886e7eea8151cc6123794d0c0dc68671d9665d177bd828fcdded2b7feb147e',
        'database/migrations/2026_06_11_120000_add_client_profile_data_gap_tables.php' => '2151f7d23f3778c0f2091b365b5f353854e8ee9110ed988be6b60ab793dfba07',
        'database/migrations/2026_06_12_000001_create_care_plan_goal_steps_table.php' => '27c9b124d396df295604d8eb989cc3754e817608ebec5e98c12c588d2d0bcc1a',
        'database/migrations/2026_06_13_000001_create_care_plan_sign_offs_table.php' => '914bafd6a512024ea1468891c8c8629e28cccfcacb4ac457049fa51290b80395',
        'database/migrations/2026_06_15_093000_create_break_glass_policies_table.php' => '91db51edfcbbd3340b242d8b0c8fca694c4ea755c6b0a76df0c51e50cfee59c4',
        'database/migrations/2026_06_15_094000_create_break_glass_flag_dismissals_table.php' => 'f422138f9917ce7bc2a949fb67f8558a71d55648662c8081cf44c3cbc6476ebf',
        'database/migrations/2026_06_16_000000_drop_hr_check_ins_table.php' => 'cabbed5056591597bd56610121c0e4f797d88e89b4d737cbf75db3b69d5287fb',
        'database/migrations/2026_06_16_000002_drop_hr_survey_tables.php' => 'f51726df1f44e90e06be290b455d3ef8cf393a5f71c602976ad45c4d7e8d2663',
        'database/migrations/2026_06_17_130000_retire_inline_remediation_from_client_incidents.php' => '4613ea0de5923501eadca93b07a64ef0fb256b23f2a0c719804a3aaa69dd1475',
        'database/migrations/2026_06_20_000001_enhance_hazards_module.php' => 'd0ccdd87d0643cd3cf139c05d10dfcbc42351138f808796bc1b06b06abb7c342',
        'database/migrations/2026_06_20_120000_create_clinical_risk_assessments_table.php' => 'a6b942431217e7a3cc60013f90edcfacfc4615a567716905e6911e6b9a0dee42',
        'database/migrations/2026_06_21_120000_create_hr_kudos_reactions_and_replies_tables.php' => '5682e7aeec32742d128d6e87964a925609d53d05fbc223ff4d4c76d8ddf196a1',
        'database/migrations/2026_06_22_000005_create_hr_feed_reactions_and_replies_tables.php' => '696aca14824b3e341a8f822a412ca49bc7c24451e601dfec6d820f0a337b98d3',
        'database/migrations/2026_06_22_000006_create_hr_feed_attachments_table.php' => 'ba3ef97026e8b245562006275c2cbbf960e386f0412322cc58fc47a4ac173e86',
        'database/migrations/2026_06_24_000001_link_staff_time_offs_to_hr_leave_requests.php' => 'eea50d455ad0e4855b5fd4f9f522a098aef18bd6728d1e313dfd66a4bfd27c85',
        'database/migrations/2026_06_27_000001_add_department_id_to_hr_calendar_events.php' => 'e238f8555675aa6f2d169dabbfaa5e2c454af50060727095b2b2ce735f5279e3',
        'database/migrations/2026_06_27_000001_drop_hr_interview_scorecards_table.php' => 'b87752306285b3a52ceb5c113e3288b17304136c7c75baa3e1e075f976bb719a',
        'database/migrations/2026_06_27_000002_create_hr_calendar_event_categories_table.php' => '1447aec9aa0225fc609e75161d260dbd6495d35e472d04298555cb19d63408cf',
        'database/migrations/2026_06_27_000002_create_hr_talent_pool_table.php' => '94645ca4eda0111c2180273c5489dc19635742815913b0840267ec9203bf2cab',
        'database/migrations/2026_06_27_000006_create_hr_calendar_event_attachments_table.php' => 'd5f7f50e8c61dd0fa4cec7c5b2afdade3403b5d08bd5601a78bab056dbcb94c2',
        'database/migrations/2026_06_28_000001_create_hr_candidate_email_templates_table.php' => '4e0686954c8fd0300f079fd862be95b81b091890b390dd2137346280c858f9e7',
        'database/migrations/2026_06_29_000004_create_hr_review_goals_table.php' => 'b3abbbc0daf5124e2674f54f8bf4184c420bcc4e419164b38f7716791d666309',
        'database/migrations/2026_06_29_000005_backfill_hr_review_goals.php' => '07c32c029edfece9716de9005c401cdfac6ab43cc70224b85d56fd326f0cafe8',
        'database/migrations/2026_06_29_000010_create_hr_goal_cycles_table.php' => '285cc3f25957089b2d07bfd2f53f95f529b50bf8085d78a22f30c1a094860e50',
        'database/migrations/2026_06_29_000020_add_okr_fields_to_hr_goals_table.php' => 'bd8b4731f0013be15d0d73016c81f18e302705c67dfe2c16833f7dc00568e5bb',
        'database/migrations/2026_06_29_000050_seed_default_goal_cycles_and_backfill.php' => 'da73ac322b3d9a1cfd0a5d540853a22fcb989b10c72b1318b22fdeb137befea8',
        'database/migrations/2026_06_29_000080_create_hr_goal_templates_table.php' => '0b69f8239f4b874309fc42ceaab78c3340c02e29fd7725a2fd2e2d3b03d7171d',
        'database/migrations/2026_06_29_000090_seed_default_goal_templates.php' => 'ab75e5c0e0a0d0181c2b89089c01f58b768948df23802efa80c20eb10fb5a62f',
        'database/migrations/2026_06_29_010001_create_hr_wellbeing_flag_actions_table.php' => '9a38b2dc6763f39fc65ecefe1c83dda2f661d8ba9c23bca44dc972496380c0bb',
        'database/migrations/2026_06_29_010004_create_hr_wellbeing_checkins_table.php' => '127428e794834914080f542f4b3ce36d2dbb9fec7ecfd07da60832065a4e969e',
        'database/migrations/2026_06_29_010005_create_hr_eap_referrals_table.php' => '7fcb070d95bb774072162969fa243f452c0697ca893efc231354ba68da744e5c',
        'database/migrations/2026_06_29_100003_create_hr_course_assignments_table.php' => 'e015022466427e75251951dad7ae69979f73bb87f5cce4f22ec68515cea7ec01',
        'database/migrations/2026_06_29_120000_extend_hr_assets_for_redesign.php' => 'f3459ca3c795846ff7aad4d3e112194b29bb8a0eb3d82c962426ea24923d05e3',
        'database/migrations/2026_07_02_100001_create_it_provisioning_tables.php' => 'b72a4285b6b50aade328675588f8c4b9f564b172eb6fd519e595be0776fde213',
        'database/migrations/2026_07_07_100002_extend_it_ticketing_schema.php' => '06d8e5db6394a0f0e7359d096e780cdd92511ddf38eab5012a805b96d22ae1cd',
        'database/migrations/2026_07_08_100001_create_it_attachments_table.php' => 'f31f7ef9c3436547305b39e79e1b7c3db809d563ecfb949dbbde3cb4f7d67d7c',
        'database/migrations/2026_07_08_100002_create_it_sla_policies_table.php' => 'd88ce4c8da2ee8a20870dd6278ce1267786e5e333db5fd5ef6a8fdde8bf7020a',
        'database/migrations/2026_07_08_100003_create_it_kb_articles_table.php' => 'a06960d9d92a35635b8ed65b286057bdaaeed599012b8411d075dcd2f16eb042',
        'database/migrations/2026_07_10_000001_seed_demo_finance_chart_and_fiscal_period.php' => 'b70a3748f58597002a038b49bdbaf0e5546594b6cee40e8b011026f8f907d64c',
        'database/migrations/2026_07_10_000002_backfill_demo_site_tenant_ids.php' => 'fb24825535863810edc624ed1f7ce36048afdfcaac1ee60d426ece5377846546',
        'database/migrations/2026_07_10_100001_create_it_ticket_approvals_table.php' => '91eb85159d8f57f274846dc61a0b04183b55303fcffbd6807c0abe0a5312b597',
        'database/migrations/2026_07_10_100002_create_it_inbound_emails_table.php' => 'fbe9a8555f8de3ba01af3cdca249ba6b668970c6f7e2feb776a4f3bc2633b5ce',
        'database/migrations/2026_07_10_110001_create_it_mailbox_connections_table.php' => '0f3ca9dde517d15d22c788de2b77af364aec5cae3fdee40a458834f58b111028',
        'database/migrations/2026_07_11_000001_add_organization_id_to_audit_logs.php' => '0bdc33bcf7e48befc22c36b170088ad69dc2ec080fa07b6e9dd24e1e643e1144',
        'database/migrations/2026_07_11_000004_link_exit_interviews_to_offboarding_tasks.php' => 'fedbc7e5bba9fbcea8bbcbfb0a6dc36a01a1331e2418226871906e8d88528342',
        'database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php' => 'acfd418be829465288f7d5c811aaf5ba6db4834ef75744eb38626452dd96e12b',
        'database/migrations/2026_07_18_100002_extend_it_work_and_create_ticket_links.php' => 'd72ea730b9edb3bb08e09b5339042fbe01ad3765b3f9c953d323c8091e24db9d',
        'database/migrations/2026_07_18_200001_create_it_service_management_core.php' => 'd7b0dfb361faa1b32dafcd29e9d06cfdc016349038498f51289199ed4a7f20ac',
        'database/migrations/2026_07_18_200002_create_it_service_catalogue.php' => '60fb9057d9fe429a9e51bdd064aca5eb14036948e64393a8f38412e7bfb58a95',
        'database/migrations/2026_07_18_200003_create_it_problem_profiles.php' => '4fb0addc594e70046c69bf79eed93fe02b877c2210f697ecd90137ebde2b5b79',
        'database/migrations/2026_07_18_200004_create_it_change_profiles.php' => '9a9de8130d030aa9e0c07ad1624d49bc8470478e761815e82e8b376abb4fdecf',
        'database/migrations/2026_07_18_200005_create_it_major_incident_profiles.php' => '9179d77d2b61347bc85d93053917e8d7c2a26359eaef811c1dd7d0edeaacd823',
        'database/migrations/2026_07_18_200006_create_it_service_identities.php' => '89d296102acd670273224f6a687741d409a26f182dded318bb37d30e14f27e6b',
        'database/migrations/2026_07_18_200007_create_it_provisioning_templates.php' => '6c2024ee874d2b3c541602a6576890aa253dde836d56ceb15005d817cf224082',
        'database/migrations/2026_07_18_200008_create_it_service_operations.php' => '1b9a764d8ff55b4efe98ebe6cda723377ad3629aa81a5443454c6d5f7295b6c6',
        'database/migrations/2026_07_21_140000_add_canonical_context_to_queclink_audit_events.php' => 'f1e786910dc756fb8f00c51e49b1952fb22e0152406deb42258a2d401b4a9105',
        'database/migrations/2026_07_21_145000_enforce_global_provider_connection_identity.php' => '5330bd5898f03e97c34809f08621b2cf6c2a8bf68eb54669dc89cc71d7e1d762',
        'database/migrations/2026_07_21_150000_refactor_monitoring_foundations_for_single_application.php' => '319eabd323fbb82fc380c255c5204c2a8b9481d73339c0d101273bbaa0c54d49',
        'database/migrations/2026_07_21_160000_enforce_single_application_global_identities.php' => '01d8e7b126a6ff9d52e13712e4a112effe90f1e9456174a581f0c4bad36a0e71',
        'database/migrations/2026_07_22_122000_enforce_calendar_sync_single_application_identity.php' => '8fa41e808a547311bf1ce07af7fc292fe842a1219b1219375f1f50267e9408d4',
        'database/migrations/2026_07_22_123000_remove_calendar_sync_legacy_leading_indexes.php' => '0b9ae979a3fdc0b23e5df4bd85d0dd037e9eb447b67244df9969ce0ba3cf12f0',
        'database/migrations/2026_07_23_000002_enforce_compliance_single_application_identity.php' => 'bbb30ee956121a8aa4e83fb4e4eefac2ff0a57ef3e4b41ec267773805d1d5ff9',
        'database/migrations/2026_07_23_000004_enforce_hr_asset_global_identity.php' => '6f9fa17d925808acd24932d3ee0a4f0f2e8114f43ab760b80f0fec57534d8288',
        'database/migrations/2026_07_23_000005_enforce_onboarding_single_application_identity.php' => 'fe0249375cc10dd23410dff60620d0f4a59b8b603f5e5a7c73646a543c0b55f2',
        'database/migrations/2026_07_28_000002_realign_hr_report_application_indexes.php' => 'ba9b84d66d974cabdfb1d28a5ca0da3e4a763d5cb0d1fd89e9768d10e4fa1fcf',
        'database/migrations/2026_07_28_000003_enforce_hr_approval_application_identity.php' => '8cac0a10ba01d4a4613a31ea07107bd015d749ff16d415979ab68e1b1587c5a0',
        'database/migrations/2026_07_28_000004_realign_hr_payroll_application.php' => '83460ef05167c956cfbbacf60c500cc716464b42823ffc77b2bbcd9139d4f421',
        'database/migrations/2026_07_28_000005_realign_hr_calendar_application.php' => '517b7421f0982d701ac93d44c0ed7b878bd3e25ebc35523a652085cad8351e40',
        'database/migrations/2026_07_28_000006_realign_hr_leave_application.php' => '09c88f55bf4b24bf97c67a55648efcf0703148233606805a7a35b6ce8ebfb794',
        'database/migrations/2026_07_28_000007_realign_hr_announcement_application_indexes.php' => '5b73beada40b1f06a2e516f1e23c43ad9b728aca9b1a9afef6afbbf816cd0409',
        'database/migrations/2026_08_01_000008_realign_hr_time_tracking_application_indexes.php' => 'aa1b07c79c2942cdfefabb5084fd0493aae4321acfcac4af28dc0a0a43e9dd15',
        'database/migrations/2026_08_01_000009_enforce_hr_goals_application_identity.php' => '8a1524073dd404e3f4554babf3cc45436d2579e7c685d549f7966937688b4b6c',
        'database/migrations/2026_08_01_000010_realign_site_access_vault_application_identity.php' => 'da1058ab11b0dfdc86c7d704515840e6973d3626d849926430e956a620952df6',
        'database/migrations/2026_08_01_000011_realign_site_type_plans_application_identity.php' => 'bb767329ea55faeab8bcf8e42b696be59252a46c06050a49a95fe21b563170c5',
        'database/migrations/2026_08_02_000012_realign_site_hardware_rooms_application_identity.php' => '6f1f5987b03b7527f8f8a71cffa19e861edd577a43cfe19b19cad7abc592ee86',
        'database/migrations/2026_08_02_000013_realign_site_compliance_application_identity.php' => 'a734756d099041eecbadf8b0eee8f27342c0dfd14133d6fbefb772387aefcda4',
        'database/migrations/2026_08_02_000014_realign_hr_skills_application_identity.php' => 'dc8287ddf1b92b318f91d92ae4c3491022bee7d79b61209b9846a59d2422bf0f',
        'database/migrations/2026_08_02_000015_realign_site_profile_application_identity.php' => 'd59a7523eccb409e82d0e2930733654ea3a4d7e0a1743846c5c5dc2c3a2bb6be',
        'database/migrations/2026_08_02_000016_realign_hr_automation_webhooks_application_identity.php' => '4c40ee432de581f8be741f7a0bb52d3fc1b554a9b04f50620b589a61117e9ff1',
        'database/migrations/2026_08_02_000019_realign_hr_documents_signatures_application_identity.php' => 'a40891f91f8f2c616f35bb74b6ed6dbd7ac37633021b87d42a8eb22259b1c237',
    ];
}

/** @return list<string> */
function itSecurityLegacyStorageDeclarationDriftSnapshot(string $root): array
{
    $drift = [];

    foreach (itSecurityLegacyStorageFiles() as $relativePath) {
        $absolutePath = $root.'/'.$relativePath;
        $contents = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
        if (! is_string($contents)) {
            $drift[] = $relativePath.'|missing';

            continue;
        }

        preg_match_all('/\btenant_id\b/u', $contents, $matches, PREG_OFFSET_CAPTURE);
        $occurrences = $matches[0] ?? [];
        $allowed = array_filter(
            $occurrences,
            fn (array $match): bool => itSecurityIsAllowedLegacyStorageOccurrence(
                $relativePath,
                $contents,
                (int) $match[1],
            ),
        );

        if ($occurrences === []) {
            if (! in_array($relativePath, itSecurityLegacyStorageWriterModels(), true)) {
                $drift[] = $relativePath.'|missing_storage_writer_contract';
            }

            continue;
        }

        $expectedOccurrenceCount = $relativePath === 'app/Domain/Hr/Models/HrEmployeeProfile.php' ? 2 : 1;
        if (count($occurrences) !== $expectedOccurrenceCount || count($allowed) !== 1) {
            $drift[] = $relativePath.'|unexpected_storage_declaration|'.count($occurrences).'|'.count($allowed);
        }
    }

    $helperPath = 'app/Support/LegacyStorageContext.php';
    $helperContents = file_get_contents($root.'/'.$helperPath);
    $expectedHelperHash = 'c8e014cf0dd3757f141e237211c908efb7b22efdb67b65fce957a9c6889e6ab6';
    if (! is_string($helperContents) || ! hash_equals($expectedHelperHash, hash('sha256', $helperContents))) {
        $drift[] = $helperPath.'|storage_helper_fingerprint_mismatch';
    }

    sort($drift, SORT_STRING);

    return $drift;
}

/** @return list<string> */
function itSecurityLegacyStorageWriterDriftSnapshot(string $root): array
{
    $drift = [];

    foreach (itSecurityLegacyStorageWriterModels() as $relativePath) {
        $contents = file_get_contents($root.'/'.$relativePath);
        if (! is_string($contents)) {
            $drift[] = $relativePath.'|missing';

            continue;
        }

        if (preg_match('/^use App\\\\Models\\\\Concerns\\\\WritesLegacyStorageContext;$/m', $contents) !== 1) {
            $drift[] = $relativePath.'|missing_concern_import';
        }

        if (preg_match('/^\s+use [^;]*\bWritesLegacyStorageContext\b[^;]*;$/m', $contents) !== 1) {
            $drift[] = $relativePath.'|missing_concern_use';
        }
    }

    sort($drift, SORT_STRING);

    return $drift;
}

/** @return list<string> */
function itSecurityHistoricalMigrationDriftSnapshot(string $root): array
{
    $drift = [];

    foreach (itSecurityHistoricalTenantMigrationFingerprints() as $relativePath => $expectedHash) {
        $absolutePath = $root.'/'.$relativePath;
        $contents = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
        if (! is_string($contents)) {
            $drift[] = $relativePath.'|missing';

            continue;
        }

        $actualHash = hash('sha256', $contents);
        if (! hash_equals($expectedHash, $actualHash)) {
            $drift[] = $relativePath.'|expected:'.$expectedHash.'|actual:'.$actualHash;
        }
    }

    sort($drift, SORT_STRING);

    return $drift;
}

/** @return list<string> */
function itSecurityApprovedTenantDebt(): array
{
    return [];
}

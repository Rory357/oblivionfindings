<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$mode = $argv[2] ?? '';
if (! in_array($mode, ['prepare', 'restore', 'inspect'], true)) {
    throw new RuntimeException('Choose prepare, restore or inspect.');
}
$marker = __DIR__.'/runtime/assignment-browser.json';
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$author = User::where('email', 'author@referrals-preview.test')->sole();
$record = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
$permission = Permission::where('key', 'referrals.viewAny')->sole();
if ($mode === 'prepare') {
    if (is_file($marker) || (int) $record->owner_id !== $reviewer->id) {
        throw new RuntimeException('Existing fixture or unexpected current owner.');
    }
    $override = $reviewer->permissionOverrides()->where('permissions.id', $permission->id)->sole()->pivot->allowed;
    $state = ['database' => $database, 'previous_view_any' => (bool) $override, 'work_id' => null, 'reference' => $record->reference];
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
    $item = DB::transaction(function () use ($record, $reviewer, $permission) {
        $work = ReferralWorkItem::create(['referral_id' => $record->id, 'owner_id' => $reviewer->id, 'kind' => 'follow_up', 'audience' => 'intake', 'status' => 'open',
            'details' => ['title' => 'Fictional transfer preparation', 'description' => 'Browser QA only. No real contact or support is requested.'], 'due_at' => now()->addDay(), 'lock_version' => 1]);
        $reviewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);

        return $work;
    });
    $state['work_id'] = $item->id;
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
    echo json_encode($state).PHP_EOL;

    return;
}
$state = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
if ($state['database'] !== $database) {
    throw new RuntimeException('Marker mismatch');
}
if ($mode === 'restore') {
    $reviewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => $state['previous_view_any']]);
    $state['access_restored'] = true;
    file_put_contents($marker, json_encode($state, JSON_PRETTY_PRINT));
    echo json_encode(['access_restored' => true]).PHP_EOL;

    return;
}
$work = ReferralWorkItem::findOrFail($state['work_id']);
if ((int) $record->owner_id !== $author->id || (int) $work->owner_id !== $author->id || $work->lock_version !== 2) {
    throw new RuntimeException('Expected browser transfer not completed');
}
echo json_encode(['passed' => true, 'reference' => $record->reference, 'owner' => $author->name, 'record_version' => $record->lock_version, 'work_id' => $work->id, 'work_version' => $work->lock_version, 'due_at' => $work->due_at->toISOString(), 'stage' => $record->stage, 'client_id' => $record->client_id]).PHP_EOL;

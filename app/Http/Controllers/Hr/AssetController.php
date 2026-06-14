<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssetController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected AssetService $assetService,
    ) {}

    /**
     * Asset list with status.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);

        $assets = HrAsset::query()
            ->forTenant($this->resolveHrTenantIdForUser($user))
            ->with('currentAssignment.employeeProfile.user:id,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('asset_tag', 'like', "%{$s}%")
                    ->orWhere('serial_number', 'like', "%{$s}%");
            }))
            ->orderBy('asset_tag')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/assets/index', [
            'assets' => $assets,
            'filters' => [
                'status' => $request->query('status'),
                'category' => $request->query('category'),
                'search' => $request->query('search'),
            ],
            'categories' => [
                ['value' => 'laptop', 'label' => 'Laptop'],
                ['value' => 'phone', 'label' => 'Phone'],
                ['value' => 'tablet', 'label' => 'Tablet'],
                ['value' => 'vehicle', 'label' => 'Vehicle'],
                ['value' => 'key', 'label' => 'Key'],
                ['value' => 'card', 'label' => 'Card'],
                ['value' => 'uniform', 'label' => 'Uniform'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'can' => [
                'manage' => $user->canDo('hr.assets.manage'),
            ],
        ]);
    }

    /**
     * Create asset form.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        return Inertia::render('hr/assets/create', [
            'categories' => [
                ['value' => 'laptop', 'label' => 'Laptop'],
                ['value' => 'phone', 'label' => 'Phone'],
                ['value' => 'tablet', 'label' => 'Tablet'],
                ['value' => 'vehicle', 'label' => 'Vehicle'],
                ['value' => 'key', 'label' => 'Key'],
                ['value' => 'card', 'label' => 'Card'],
                ['value' => 'uniform', 'label' => 'Uniform'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /**
     * Store a new asset.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'asset_tag' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:laptop,phone,tablet,vehicle,key,card,uniform,other'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        HrAsset::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'status' => 'available',
            ...$data,
        ]);

        return redirect()->route('hr.assets.index')->with('success', 'Asset created.');
    }

    /**
     * Show asset detail with assignment history.
     */
    public function show(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.view'), 403);

        $asset->load([
            'currentAssignment.employeeProfile.user:id,name',
            'assignments' => fn ($q) => $q->with([
                'employeeProfile.user:id,name',
                'assignedByUser:id,name',
            ])->orderByDesc('assigned_at'),
        ]);

        $employees = HrEmployeeProfile::with('user:id,name')
            ->where('tenant_id', $this->resolveHrTenantIdForUser($user))
            ->where('is_active', true)
            ->get(['id', 'user_id', 'position_title']);

        return Inertia::render('hr/assets/show', [
            'asset' => $asset,
            'employees' => $employees,
            'can' => [
                'manage' => $user->canDo('hr.assets.manage'),
            ],
        ]);
    }

    /**
     * Assign an asset to an employee.
     */
    public function assign(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'assigned_at' => ['required', 'date'],
            'condition_on_assign' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = HrEmployeeProfile::findOrFail($data['employee_profile_id']);

        $this->assetService->assignAsset($asset, $profile, [
            'assigned_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Asset assigned.');
    }

    /**
     * Return an asset.
     */
    public function returnAsset(Request $request, HrAssetAssignment $assignment)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'returned_at' => ['required', 'date'],
            'condition_on_return' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assetService->returnAsset($assignment, $data);

        return redirect()->back()->with('success', 'Asset returned.');
    }

    /**
     * Send an available asset to maintenance.
     */
    public function sendToMaintenance(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->assetService->sendToMaintenance($asset, $data);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset sent to maintenance.');
    }

    /**
     * Return an asset from maintenance to the available pool.
     */
    public function returnFromMaintenance(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->assetService->returnFromMaintenance($asset, $data);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset returned to service.');
    }

    /**
     * Retire (decommission) an asset.
     */
    public function retire(Request $request, HrAsset $asset)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.assets.manage'), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->assetService->retireAsset($asset, $data);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset retired.');
    }
}

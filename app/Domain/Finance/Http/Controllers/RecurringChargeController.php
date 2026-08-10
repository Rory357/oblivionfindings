<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecurringChargeController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $baseQuery = $this->accessibleCharges($auth);

        $charges = (clone $baseQuery)
            ->with(['client:id,first_name,last_name'])
            ->when(
                ! empty($filters['q']),
                fn ($query) => $query->where(function ($innerQuery) use ($filters) {
                    $search = '%'.$filters['q'].'%';

                    $innerQuery->where('name', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhereHas('client', function ($clientQuery) use ($search) {
                            $clientQuery->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        });
                }),
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderBy('next_charge_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RecurringCharge $charge) => [
                'id' => $charge->id,
                'name' => $charge->name ?: $charge->description,
                // Raw fields so the row can prefill the edit modal.
                'client_id' => $charge->client_id,
                'description' => $charge->description,
                'amount' => (float) $charge->amount,
                'frequency' => $charge->frequency,
                'is_active' => (bool) $charge->is_active,
                'next_charge_date' => $charge->next_charge_at?->toDateString(),
                'client' => $charge->client ? [
                    'id' => $charge->client->id,
                    'first_name' => $charge->client->first_name,
                    'last_name' => $charge->client->last_name,
                ] : null,
            ]);

        $canManage = (bool) $auth->canDo('finance.ar.manage');

        return inertia('finance/recurring-charges/Index', [
            'charges' => $charges,
            'filters' => $filters,
            'canManage' => $canManage,
            // Client options for the create/edit modal.
            'clients' => $canManage ? $this->clientOptions($auth) : [],
            'stats' => [
                'active' => (clone $baseQuery)
                    ->where('is_active', true)
                    ->count(),
                'monthly_total' => (float) (clone $baseQuery)
                    ->where('is_active', true)
                    ->sum('amount'),
                'next_due' => (clone $baseQuery)
                    ->where('is_active', true)
                    ->whereDate('next_charge_at', '<=', now()->addDays(7))
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'string', 'in:weekly,fortnightly,monthly,quarterly,annually'],
            'next_charge_date' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:next_charge_date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            ['reports.viewAny'],
        );

        RecurringCharge::create([
            'client_id' => $data['client_id'],
            'name' => $data['description'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'frequency' => $data['frequency'],
            // The series starts at its first charge date — starts_at is NOT NULL
            // with no default, so omitting it 500'd every create (the retired
            // full-page form had the same bug).
            'starts_at' => $data['next_charge_date'],
            'next_charge_at' => $data['next_charge_date'],
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge created.');
    }

    public function update(Request $request, $charge)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $charge = $this->accessibleCharges($auth)->findOrFail($charge);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'frequency' => ['sometimes', 'required', 'string', 'in:weekly,fortnightly,monthly,quarterly,annually'],
            'next_charge_date' => ['sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('client_id', $data)) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );

            if (
                $charge->service_agreement_id
                && ! $charge->serviceAgreement()->where('client_id', (int) $data['client_id'])->exists()
            ) {
                throw ValidationException::withMessages([
                    'client_id' => 'The recurring charge Client must match its Service Agreement.',
                ]);
            }
        }

        if (array_key_exists('description', $data)) {
            $data['name'] = $data['description'];
        }

        if (array_key_exists('next_charge_date', $data)) {
            $data['next_charge_at'] = $data['next_charge_date'];
            unset($data['next_charge_date']);
        }

        $charge->update($data);

        return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge updated.');
    }

    public function destroy(Request $request, $charge)
    {
        $auth = $this->authorizeFinance($request, 'finance.ar.manage');

        $charge = $this->accessibleCharges($auth)->findOrFail($charge);

        $charge->delete();

        return redirect()->back()->with('success', 'Recurring charge deleted.');
    }

    private function authorizeFinance(Request $request, string $permission)
    {
        $user = $request->user();

        abort_unless($user && $user->canDo($permission), 403);

        return $user;
    }

    private function clientOptions(User $user)
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $user,
            ['reports.viewAny'],
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    private function accessibleCharges(User $user): Builder
    {
        return RecurringCharge::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ))
            ->where(function (Builder $query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'recurring_charges.client_id'));
            });
    }
}

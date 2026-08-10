<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PriceBook;
use App\Models\PriceBookItem;
use Illuminate\Http\Request;

class PriceBookController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $data = $request->validate([
            'active' => ['nullable', 'boolean'],
        ]);

        $priceBooks = PriceBook::query()
            ->withCount('items')
            ->when(isset($data['active']), fn ($q) => $q->where('is_active', $data['active']))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('finance/price-books/Index', [
            'price_books' => $priceBooks,
            'filters' => $request->only(['active']),
            'canManage' => (bool) $auth->canDo('finance.ar.manage'),
        ]);
    }

    public function show(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $priceBook = PriceBook::query()
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->findOrFail($priceBook);

        return inertia('finance/price-books/Show', [
            // NOTE: the page reads `price_book` (the old `priceBook` key never
            // reached it). Kept snake_case to match the existing page contract.
            'price_book' => $priceBook,
            'canManage' => (bool) $auth->canDo('finance.ar.manage'),
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceBook = PriceBook::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Price book created.');
    }

    public function update(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $priceBook = PriceBook::query()->findOrFail($priceBook);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceBook->update($data);

        return redirect()->back()->with('success', 'Price book updated.');
    }

    public function storeItem(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $priceBook = PriceBook::query()->findOrFail($priceBook);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ]);

        $priceBook->items()->create([
            'name' => $data['name'],
            'service_code' => $data['code'] ?? null,
            'rate' => $data['unit_price'],
            'unit' => $data['unit'] ?? 'hour',
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Item added.');
    }

    public function updateItem(Request $request, $priceBook, $item)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        PriceBook::query()->findOrFail($priceBook);

        $priceBookItem = PriceBookItem::where('price_book_id', $priceBook)->findOrFail($item);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ]);

        $priceBookItem->update([
            ...(array_key_exists('name', $data) ? ['name' => $data['name']] : []),
            ...(array_key_exists('code', $data) ? ['service_code' => $data['code']] : []),
            ...(array_key_exists('unit_price', $data) ? ['rate' => $data['unit_price']] : []),
            ...(array_key_exists('unit', $data) ? ['unit' => $data['unit'] ?? 'hour'] : []),
            ...(array_key_exists('description', $data) ? ['description' => $data['description']] : []),
        ]);

        return redirect()->back()->with('success', 'Item updated.');
    }

    public function destroyItem(Request $request, $priceBook, $item)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        PriceBook::query()->findOrFail($priceBook);

        $priceBookItem = PriceBookItem::where('price_book_id', $priceBook)->findOrFail($item);
        $priceBookItem->delete();

        return redirect()->back()->with('success', 'Item removed.');
    }
}

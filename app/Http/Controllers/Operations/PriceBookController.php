<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\PriceBook;
use App\Models\PriceBookItem;
use Illuminate\Http\Request;

class PriceBookController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.viewAny'), 403);

        $data = $request->validate([
            'active' => ['nullable', 'boolean'],
        ]);

        $priceBooks = PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->withCount('items')
            ->when(isset($data['active']), fn ($q) => $q->where('is_active', $data['active']))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/price-books/Index', [
            'priceBooks' => $priceBooks,
            'filters' => $request->only(['active']),
        ]);
    }

    public function show(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.view'), 403);

        $priceBook = PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->findOrFail($priceBook);

        return inertia('operations/price-books/Show', [
            'priceBook' => $priceBook,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.create'), 403);

        return inertia('operations/price-books/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceBook = PriceBook::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Price book created.');
    }

    public function edit(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.edit'), 403);

        $priceBook = PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->findOrFail($priceBook);

        return inertia('operations/price-books/Edit', [
            'priceBook' => $priceBook,
        ]);
    }

    public function update(Request $request, $priceBook)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.edit'), 403);

        $priceBook = PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($priceBook);

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
        abort_unless($auth && $auth->canDo('price_books.edit'), 403);

        $priceBook = PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($priceBook);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ]);

        $priceBook->items()->create($data);

        return redirect()->back()->with('success', 'Item added.');
    }

    public function updateItem(Request $request, $priceBook, $item)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.edit'), 403);

        PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($priceBook);

        $priceBookItem = PriceBookItem::where('price_book_id', $priceBook)->findOrFail($item);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ]);

        $priceBookItem->update($data);

        return redirect()->back()->with('success', 'Item updated.');
    }

    public function destroyItem(Request $request, $priceBook, $item)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('price_books.edit'), 403);

        PriceBook::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($priceBook);

        $priceBookItem = PriceBookItem::where('price_book_id', $priceBook)->findOrFail($item);
        $priceBookItem->delete();

        return redirect()->back()->with('success', 'Item removed.');
    }
}

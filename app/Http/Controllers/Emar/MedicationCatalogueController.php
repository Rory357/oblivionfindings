<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Services\Medication\MedicineCatalogue\MedicineCatalogueBindingService;
use App\Services\Medication\MedicineCatalogue\MedicineCatalogueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MedicationCatalogueController extends Controller
{
    public function __construct(private readonly MedicineCatalogueService $catalogue) {}

    public function index(Request $request)
    {
        $request->validate(['source_page' => 'sometimes|integer|min:1']);

        return Inertia::render('emar/catalogue/index', $this->catalogue->page($request->user(), $request->integer('source_page', 1)));
    }

    public function create(Request $request)
    {
        $data = $request->validate(['supplier' => 'required|string|max:120', 'source_name' => 'required|string|max:120', 'source_version' => 'required|string|max:100', 'attribution' => 'required|string|max:500', 'licence_reference' => 'required|string|max:500', 'licence_attested' => 'required|accepted']);
        $data['licence_attested'] = true;
        $source = $this->catalogue->create($request->user(), $data);

        return response()->json(['id' => $source->id, 'version' => $source->version], 201);
    }

    public function import(Request $request, int $source)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'dataset' => 'required|file|max:2048']);
        $source = $this->catalogue->import($request->user(), $source, $data['version'], file_get_contents($request->file('dataset')->getRealPath()));

        return response()->json(['id' => $source->id, 'version' => $source->version]);
    }

    public function photo(Request $request, int $source, int $product)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'request_uuid' => 'required|uuid', 'photo' => 'required|file|max:2048']);
        $row = $this->catalogue->photo($request->user(), $source, $product, $data['version'], $data['request_uuid'], $request->file('photo'));

        return response()->json(['id' => $row->id, 'photo_url' => '/emar/catalogue/products/'.$row->id.'/photo', 'version' => $row->source()->value('version')]);
    }

    public function review(Request $request, int $source)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'expires_at' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/']]);
        $row = $this->catalogue->review($request->user(), $source, $data['version'], $data['expires_at']);

        return response()->json(['id' => $row->id, 'version' => $row->version, 'status' => $row->status]);
    }

    public function revoke(Request $request, int $source)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $row = $this->catalogue->revoke($request->user(), $source, $data['version']);

        return response()->json(['id' => $row->id, 'version' => $row->version, 'status' => $row->status]);
    }

    public function match(Request $request)
    {
        $data = $request->validate(['code_system' => 'required|string|max:40', 'code' => 'required|string|max:100', 'name' => 'required|string|max:200', 'strength' => 'required|string|max:100', 'form' => 'required|string|max:100']);

        return response()->json($this->catalogue->match($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $request, int $medication)
    {
        $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);

        return response()->json(app(MedicineCatalogueBindingService::class)->products($request->user(), $medication, (string) $request->query('q', ''), $request->integer('page', 1)))->header('Cache-Control', 'private, no-store');
    }

    public function binding(Request $request, int $medication)
    {
        return response()->json(app(MedicineCatalogueBindingService::class)->read($request->user(), $medication))->header('Cache-Control', 'private, no-store');
    }

    public function bind(Request $request, int $medication)
    {
        $data = $request->validate(['product_id' => 'required|integer|min:1', 'expected_medication_version' => 'required|integer|min:1', 'product_label_confirmed' => 'required|accepted', 'reference' => 'required|string|max:500']);
        $data['product_label_confirmed'] = true;

        return response()->json(app(MedicineCatalogueBindingService::class)->bind($request->user(), $medication, $data))->header('Cache-Control', 'private, no-store');
    }

    public function showPhoto(Request $request, int $product)
    {
        $row = $this->catalogue->readablePhoto($request->user(), $product);

        return response(Storage::disk('private')->get($row->photo_path), 200, ['Content-Type' => $row->photo_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="medicine-photo"']);
    }
}

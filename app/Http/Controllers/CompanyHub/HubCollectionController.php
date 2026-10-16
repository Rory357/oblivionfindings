<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubCollections;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HubCollectionController extends Controller
{
    public function index(Request $request, HubCollections $collections): JsonResponse
    {
        return response()->json($collections->context($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, HubCollections $collections): JsonResponse
    {
        $data = $request->validate($this->rules());
        $row = $collections->create($request->user(), (int) $data['actor_id'], $data['name']);

        return response()->json($collections->mutationResponse($request->user(), $row), 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $collection, HubCollections $collections): JsonResponse
    {
        // Bound the route string before PHP coercion or any object lookup.
        abort_unless(preg_match('/^[1-9][0-9]{0,9}$/D', $collection) === 1 && (int) $collection <= 2147483647, 404);
        $data = $request->validate([...$this->rules(), 'version' => 'required|integer|min:1|max:2147483647']);
        $row = $collections->rename($request->user(), (int) $data['actor_id'], (int) $collection, (int) $data['version'], $data['name']);

        return response()->json($collections->mutationResponse($request->user(), $row))->header('Cache-Control', 'private, no-store');
    }

    public function adopt(Request $request, HubCollections $collections): JsonResponse
    {
        $data = $request->validate([...$this->rules(), 'key' => 'required|string|regex:/^legacy:[a-f0-9]{64}$/', 'adoption_key' => 'required|string|regex:/^[a-f0-9]{64}$/']);
        $row = $collections->adopt($request->user(), (int) $data['actor_id'], $data['key'], $data['adoption_key'], $data['name']);

        return response()->json($collections->mutationResponse($request->user(), $row), 201)->header('Cache-Control', 'private, no-store');
    }

    private function rules(): array
    {
        return ['actor_id' => 'required|integer|min:1|max:2147483647', 'name' => 'required|string|max:60'];
    }
}

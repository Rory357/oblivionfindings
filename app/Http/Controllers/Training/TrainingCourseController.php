<?php

namespace App\Http\Controllers\Training;

use App\Domain\Hr\Models\HrCourse;
use App\Http\Controllers\Controller;
use App\Models\TrainingCourse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrainingCourseController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('hr.training.catalog');
    }

    public function create(): RedirectResponse
    {
        return redirect()->to('/hr/training/catalog?open=create');
    }

    public function show(Request $request, int|string $course): RedirectResponse
    {
        return $this->redirectToHrCourse($request, $course);
    }

    public function edit(Request $request, int|string $course): RedirectResponse
    {
        return $this->redirectToHrCourse($request, $course);
    }

    public function store(Request $request) { return redirect()->back(); }
    public function update(Request $request, $course) { return redirect()->back(); }
    public function destroy($course) { return redirect()->back(); }

    private function redirectToHrCourse(Request $request, int|string $course): RedirectResponse
    {
        $legacyCourse = TrainingCourse::query()->find($course);

        if ($legacyCourse) {
            $mappedCourse = HrCourse::query()
                ->when(
                    $request->user()?->tenant_id,
                    fn ($query, $tenantId) => $query->where('tenant_id', $tenantId)
                )
                ->where(function ($query) use ($legacyCourse) {
                    $query->where('code', $legacyCourse->code)
                        ->orWhere('title', $legacyCourse->name);
                })
                ->first();

            if ($mappedCourse) {
                return redirect()->route('hr.training.courses.show', ['course' => $mappedCourse->id]);
            }
        }

        return redirect()->route('hr.training.catalog');
    }
}

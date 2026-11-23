<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View
    {
        Gate::authorize('departments.view');

        return view('admin.departments.index', ['departments' => Department::withCount('users')->orderBy('name')->paginate(15)]);
    }

    public function create(): View
    {
        Gate::authorize('departments.manage');

        return view('admin.departments.create', ['department' => new Department]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('departments.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:255', 'unique:departments,code'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']]);
        $department = Department::create([...$data, 'is_active' => $request->boolean('is_active', true)]);
        $auditLogger->record('department_created', 'departments', 'Unit kerja dibuat.', $department, null, $department->only(['code', 'name', 'description', 'is_active']));

        return redirect()->route('admin.departments.index')->with('status', 'Unit kerja berhasil dibuat.');
    }

    public function edit(Department $department): View
    {
        Gate::authorize('departments.manage');

        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('departments.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:255', Rule::unique('departments')->ignore($department)], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $oldValues = $department->only(array_keys($data));
        $department->update($data);
        $auditLogger->record('department_updated', 'departments', 'Unit kerja diperbarui.', $department, $oldValues, $department->only(array_keys($data)));

        return redirect()->route('admin.departments.index')->with('status', 'Unit kerja diperbarui.');
    }

    public function updateStatus(Request $request, Department $department, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('departments.manage');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $old = $department->is_active;
        $department->update(['is_active' => (bool) $data['is_active']]);
        $auditLogger->record('department_status_changed', 'departments', 'Status unit kerja diperbarui.', $department, ['is_active' => $old], ['is_active' => $department->is_active]);

        return back()->with('status', 'Status unit kerja diperbarui.');
    }
}

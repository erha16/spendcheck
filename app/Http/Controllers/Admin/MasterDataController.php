<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataRequest;
use App\Http\Requests\UpdateMasterDataStatusRequest;
use App\Models\Category;
use App\Services\AuditLogger;
use App\Services\MasterDataCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function index(Request $request, MasterDataCatalog $catalog, string $resource): View
    {
        $definition = $catalog->get($resource);
        Gate::authorize($catalog->permission($resource, 'view'));

        $query = $definition['model']::query();
        if ($resource === 'subcategories') {
            $query->with('category');
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $query->where(function (Builder $query) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $records = $query->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.master-data.index', compact('definition', 'records', 'resource', 'search'));
    }

    public function create(MasterDataCatalog $catalog, string $resource): View
    {
        $definition = $catalog->get($resource);
        Gate::authorize($catalog->permission($resource, 'manage'));

        return view('admin.master-data.form', ['definition' => $definition, 'resource' => $resource, 'record' => new $definition['model'], 'categories' => Category::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(MasterDataRequest $request, MasterDataCatalog $catalog, AuditLogger $auditLogger, string $resource): RedirectResponse
    {
        $definition = $catalog->get($resource);
        $values = $request->validated();
        $record = $definition['model']::create([...$values, 'is_active' => true]);
        $auditLogger->record($this->actionName($resource, 'created'), $this->moduleName($resource), $definition['label'].' dibuat.', $record, null, $record->only(array_merge(array_keys($values), ['is_active'])));

        return redirect()->route('admin.master.index', ['resource' => $resource])->with('status', $definition['label'].' berhasil dibuat.');
    }

    public function edit(MasterDataCatalog $catalog, string $resource, int $record): View
    {
        $definition = $catalog->get($resource);
        Gate::authorize($catalog->permission($resource, 'manage'));

        return view('admin.master-data.form', ['definition' => $definition, 'resource' => $resource, 'record' => $definition['model']::query()->findOrFail($record), 'categories' => Category::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function update(MasterDataRequest $request, MasterDataCatalog $catalog, AuditLogger $auditLogger, string $resource, int $record): RedirectResponse
    {
        $definition = $catalog->get($resource);
        $model = $definition['model'];
        $item = $model::query()->findOrFail($record);
        $values = $request->validated();
        $oldValues = $item->only(array_keys($values));
        $item->update($values);
        $auditLogger->record($this->actionName($resource, 'updated'), $this->moduleName($resource), $definition['label'].' diperbarui.', $item, $oldValues, $item->only(array_keys($values)));

        return redirect()->route('admin.master.index', ['resource' => $resource])->with('status', $definition['label'].' berhasil diperbarui.');
    }

    public function updateStatus(UpdateMasterDataStatusRequest $request, MasterDataCatalog $catalog, AuditLogger $auditLogger, string $resource, int $record): RedirectResponse
    {
        $definition = $catalog->get($resource);
        $model = $definition['model'];
        $item = $model::query()->findOrFail($record);
        $oldValue = $item->is_active;
        $item->update(['is_active' => $request->boolean('is_active')]);
        $auditLogger->record($this->actionName($resource, 'status_changed'), $this->moduleName($resource), 'Status '.$definition['label'].' diperbarui.', $item, ['is_active' => $oldValue], ['is_active' => $item->is_active]);

        return back()->with('status', 'Status '.$definition['label'].' berhasil diperbarui.');
    }

    public function destroy(MasterDataCatalog $catalog, AuditLogger $auditLogger, string $resource, int $record): RedirectResponse
    {
        $definition = $catalog->get($resource);
        Gate::authorize($catalog->permission($resource, 'manage'));
        $model = $definition['model'];
        $item = $model::query()->findOrFail($record);

        if ($item instanceof Category && $item->subcategories()->exists()) {
            throw ValidationException::withMessages(['record' => 'Kategori yang masih memiliki subkategori tidak dapat dihapus.']);
        }

        $values = $item->only(array_merge(array_keys($definition['fields']), ['is_active']));
        $auditLogger->record($this->actionName($resource, 'deleted'), $this->moduleName($resource), $definition['label'].' dihapus.', $item, $values, null);
        $item->delete();

        return redirect()->route('admin.master.index', ['resource' => $resource])->with('status', $definition['label'].' berhasil dihapus.');
    }

    private function actionName(string $resource, string $action): string
    {
        return Str::singular(str_replace('-', '_', $resource)).'_'.$action;
    }

    private function moduleName(string $resource): string
    {
        return str_replace('-', '_', $resource);
    }
}

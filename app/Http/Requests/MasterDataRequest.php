<?php

namespace App\Http\Requests;

use App\Services\MasterDataCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(MasterDataCatalog $catalog): bool
    {
        return $this->user()?->can($catalog->permission((string) $this->route('resource'), 'manage')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(MasterDataCatalog $catalog): array
    {
        $definition = $catalog->get((string) $this->route('resource'));
        $rules = [];
        $recordId = $this->route('record');

        foreach ($definition['fields'] as $field => $configuration) {
            $rules[$field] = $configuration['rules'];
            if (in_array($field, ['code', 'name'], true)) {
                $rules[$field][] = Rule::unique((new $definition['model'])->getTable(), $field)->ignore($recordId);
            }
        }

        return $rules;
    }
}

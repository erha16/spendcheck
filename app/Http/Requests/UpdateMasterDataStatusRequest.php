<?php

namespace App\Http\Requests;

use App\Services\MasterDataCatalog;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMasterDataStatusRequest extends FormRequest
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
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitSchedulingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    protected function prepareForValidation(): void
    {
        $storeParam = $this->route('store');

        $storeId = $storeParam instanceof Store
            ? $storeParam->id
            : Store::where('store', $storeParam)->value('id');

        $this->merge([
            'store_id' => $storeId,
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'employee_id' => [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->where(function ($query) {
                $query->where('store_id', $this->input('store_id'));
            }),
        ],
        ];
    }
}
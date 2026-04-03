<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Skill;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('name')) {

            $clean = strtolower(
                preg_replace('/\s+/', '', trim($this->name))
            );

            $this->merge([
                'name' => $clean
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {

                    $exists = Skill::whereRaw('LOWER(REPLACE(name, " ", "")) = ?', [$value])
                        ->exists();

                    if ($exists) {
                        $fail('Skill name is already taken.');
                    }
                }
            ],
            'description' => ['nullable', 'string'],
        ];
    }
}
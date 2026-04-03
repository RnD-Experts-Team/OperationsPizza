<?php

namespace App\Http\Requests;

use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && $this->input('name') !== null) {
            $cleanName = strtolower(
                preg_replace('/\s+/', '', trim($this->input('name')))
            );

            $this->merge([
                'name' => $cleanName,
            ]);
        }
    }

    public function rules(): array
    {
        $skillId = $this->route('skill') ?? $this->route('id');

        return [
            'name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($skillId) {
                    $exists = Skill::query()
                        ->whereRaw('LOWER(REPLACE(name, " ", "")) = ?', [$value])
                        ->where('id', '!=', $skillId)
                        ->exists();

                    if ($exists) {
                        $fail('Skill name is already taken.');
                    }
                },
            ],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Skill name must be a string.',
            'name.max' => 'Skill name may not be greater than 255 characters.',
            'description.string' => 'Description must be a string.',
        ];
    }
}
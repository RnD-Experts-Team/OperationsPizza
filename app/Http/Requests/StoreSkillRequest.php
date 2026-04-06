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

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('name') && $this->input('name') !== null) {
            $cleanName = trim($this->input('name'));
            $cleanName = preg_replace('/\s+/', ' ', $cleanName);
            $cleanName = ucwords(strtolower($cleanName));
            $cleanName = $cleanName === '' ? null : $cleanName;

            $data['name'] = $cleanName;
        }

        if ($this->has('description') && $this->input('description') !== null) {
            $cleanDescription = trim($this->input('description'));
            $cleanDescription = preg_replace('/\s+/', ' ', $cleanDescription);
            $cleanDescription = ucwords(strtolower($cleanDescription));
            $cleanDescription = $cleanDescription === '' ? null : $cleanDescription;

            $data['description'] = $cleanDescription;
        }

        if (!empty($data)) {
            $this->merge($data);
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
                    $normalizedValue = strtolower(str_replace(' ', '', $value));

                    $exists = Skill::query()
                        ->whereRaw('LOWER(REPLACE(name, " ", "")) = ?', [$normalizedValue])
                        ->exists();

                    if ($exists) {
                        $fail('Skill name is already taken.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Skill name is required.',
            'name.string' => 'Skill name must be a string.',
            'name.max' => 'Skill name may not be greater than 255 characters.',
            'description.string' => 'Description must be a string.',
        ];
    }
}
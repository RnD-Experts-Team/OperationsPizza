<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    protected $fillable = ['name', 'min_age', 'restriction_note'];

    protected function casts(): array
    {
        return ['min_age' => 'integer'];
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    /**
     * Whether the employee is younger than this skill allows. An unknown birth
     * date is not a minor: the warning only fires on what we actually know.
     */
    public function isRestrictedFor(Employee $employee): bool
    {
        return $this->min_age !== null && $employee->ageAtLeast($this->min_age) === false;
    }
}

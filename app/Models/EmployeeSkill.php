<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSkill extends Model
{
    protected $table = 'employee_skills';

    protected $fillable = [
        'employee_id',
        'skill_id',
        'rating'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;


    protected $fillable = [
        'id',
        'name',
        'email',
    ];

    protected $hidden = [
        'remember_token',
    ];

    public function createdSchedules()
    {
        return $this->hasMany(MasterSchedule::class, 'created_by');
    }

    public function publishedSchedules()
    {
        return $this->hasMany(MasterSchedule::class, 'published_by');
    }

    public function editedSchedules()
    {
        return $this->hasMany(Schedule::class, 'edited_by');
    }
    public function scheduleTemplates()
    {
        return $this->hasMany(ScheduleTemplate::class, 'created_by');
    }
}

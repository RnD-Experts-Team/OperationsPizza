<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class AvailabilityTime extends Model
{
 
    protected $fillable = ['from','to','availability_id'];

    public function availability()
    {
        return $this->belongsTo(Availability::class);
    }
}
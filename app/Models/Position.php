<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Model;

/** Replicated from HiringPizza's positions reference table. */
class Position extends Model
{
    use ReplicatedModel;

    protected $fillable = ['id', 'label', 'description'];
}

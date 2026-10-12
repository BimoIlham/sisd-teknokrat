<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulingState extends Model
{
    protected $table = 'scheduling_state';

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'revision',
        'updated_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'updated_at' => 'datetime',
    ];
}

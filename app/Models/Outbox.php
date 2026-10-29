<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $table = 'outbox';
}

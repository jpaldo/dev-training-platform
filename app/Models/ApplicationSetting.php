<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting value.
 *
 * Reach for App\Support\Settings instead of this model. The manager caches
 * every value in one array, so reading through it costs no query.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];
}

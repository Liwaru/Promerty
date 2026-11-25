<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyCategory extends Model
{
    protected $fillable = ['name', 'slug'];

    public function secondaryProperties(): HasMany
    {
        return $this->hasMany(SecondaryProperty::class, 'category_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'category_id');
    }
}

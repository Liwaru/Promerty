<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'label',
        'address',
        'district',
        'city',
        'province',
        'postal_code',
        'latitude',
        'longitude',
        'public_latitude',
        'public_longitude',
        'public_radius_m',
        'visibility',
        'google_place_id',
        'google_maps_url',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'public_latitude' => 'decimal:7',
            'public_longitude' => 'decimal:7',
            'public_radius_m' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function secondaryProperties(): HasMany
    {
        return $this->hasMany(SecondaryProperty::class);
    }
}

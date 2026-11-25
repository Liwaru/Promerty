<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    use SoftDeletes;

    public const PUBLIC_ORIENTATIONS = ['Utara','Timur Laut','Timur','Tenggara','Selatan','Barat Daya','Barat','Barat Laut'];

    protected $fillable = [
        'listing_code',
        'listing_type',
        'transaction_type',
        'agent_id',
        'branch_id',
        'category_id',
        'project_id',
        'secondary_property_id',
        'title',
        'unit_type',
        'description',
        'price',
        'price_period',
        'negotiable',
        'bedrooms',
        'bathrooms',
        'land_area',
        'building_area',
        'floors',
        'certificate',
        'furnishing',
        'orientation',
        'electricity',
        'additional_specs',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'negotiable' => 'boolean',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'land_area' => 'decimal:2',
            'building_area' => 'decimal:2',
            'floors' => 'integer',
            'additional_specs' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function permitsVideoDownload(): bool
    {
        // Existing listings inherit the site-wide setting until the Agent sets a preference.
        return ($this->additional_specs['allow_video_download'] ?? true) === true;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PropertyCategory::class, 'category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function secondaryProperty(): BelongsTo
    {
        return $this->belongsTo(SecondaryProperty::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function surveyRequests(): HasMany
    {
        return $this->hasMany(SurveyRequest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('listing_type', 'primary');
    }

    public function scopeSecondary(Builder $query): Builder
    {
        return $query->where('listing_type', 'secondary');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}

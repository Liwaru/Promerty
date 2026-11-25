<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id',
        'branch_id',
        'name',
        'username',
        'email',
        'phone',
        'password',
        'phone_verified_at',
        'photo',
        'bio',
        'whatsapp',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function authProviders(): HasMany
    {
        return $this->hasMany(UserAuthProvider::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'agent_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(Save::class);
    }

    public function followingRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'follower_id');
    }

    public function followerRelations(): HasMany
    {
        return $this->hasMany(Follow::class, 'following_id');
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'follower_id',
            'following_id'
        )->withPivot('created_at');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'follows',
            'following_id',
            'follower_id'
        )->withPivot('created_at');
    }

    public function conversationMemberships(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_members'
        )->withPivot([
            'id',
            'member_role',
            'joined_at',
            'last_read_at',
        ]);
    }

    public function surveyRequestsAsCustomer(): HasMany
    {
        return $this->hasMany(SurveyRequest::class, 'customer_id');
    }

    public function surveyRequestsAsAgent(): HasMany
    {
        return $this->hasMany(SurveyRequest::class, 'agent_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function reportsFiled(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function reportsHandled(): HasMany
    {
        return $this->hasMany(Report::class, 'handled_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_blocks',
            'blocker_id',
            'blocked_id'
        )->withPivot('created_at');
    }

    public function blockedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_blocks',
            'blocked_id',
            'blocker_id'
        )->withPivot('created_at');
    }

    public function hasPermission(string $permission): bool
    {
        // A legacy Owner permission row must never grant technical administration.
        if ($this->hasRole('owner') && !in_array($permission, [...\App\Support\RoleCatalog::permissions('owner'),'manage_project_assignments'], true)) return false;
        if (!$this->role) {
            return false;
        }

        return $this->role->permissions()
            ->where('permissions.name', $permission)
            ->exists();
    }

    public function hasRole(string $role): bool
    {
        return $this->role?->name === $role;
    }

    public function level(): int
    {
        return (int) ($this->role?->level ?? 0);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityIdentity extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['session_version','mfa_secret','recovery_hashes','mfa_last_step'];
    protected function casts(): array
    {
        return ['mfa_secret'=>'encrypted','recovery_hashes'=>'array', 'mfa_confirmed_at'=>'datetime',
            'mfa_pending_until'=>'datetime','mfa_last_step'=>'integer','revision'=>'integer'];
    }
}

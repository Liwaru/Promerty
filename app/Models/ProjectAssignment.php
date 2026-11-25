<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProjectAssignment extends Model
{
    protected $fillable=['project_id','branch_id','agent_id','assignment_key','assigned_by','active'];
    protected function casts():array{return ['active'=>'boolean'];}
    public function project(){return $this->belongsTo(Project::class);}
    public function branch(){return $this->belongsTo(Branch::class);}
    public function agent(){return $this->belongsTo(User::class,'agent_id');}
}

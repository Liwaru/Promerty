<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::create('project_assignments',function(Blueprint $table){
            $table->id();$table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('assignment_key',80);$table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->boolean('active')->default(true);$table->timestamps();
            $table->unique(['project_id','assignment_key']);$table->index(['branch_id','agent_id','active']);
        });
    }
    public function down():void{Schema::dropIfExists('project_assignments');}
};

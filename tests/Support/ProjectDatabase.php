<?php
namespace Tests\Support;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
trait ProjectDatabase
{
    protected function createProjectFixtureSchema():void
    {
        $database=str_replace('\\','/',(string)config('database.connections.sqlite.database'));
        $browser=str_replace('\\','/',storage_path('app/private/agent-workflow-browser-20261120-v2/fixture.sqlite'));
        if(!app()->environment('testing')||config('database.default')!=='sqlite'||!in_array($database,[':memory:',$browser],true))
            throw new \LogicException('Only isolated synthetic SQLite fixtures are allowed.');
        // Mirror the existing developers table from mysql-schema.sql; no operational migration.
        if(!Schema::hasTable('developers'))Schema::create('developers',function(Blueprint $t){
            $t->id();$t->string('name',150)->unique();$t->text('description')->nullable();$t->string('phone',30)->nullable();
            $t->string('website')->nullable();$t->unsignedBigInteger('created_by')->nullable();$t->timestamps();$t->softDeletes();
        });
        if(!Schema::hasTable('project_assignments'))(require base_path('database/migrations/2026_11_20_120000_create_project_assignments_table.php'))->up();
    }
}

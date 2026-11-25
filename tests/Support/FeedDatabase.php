<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

trait FeedDatabase
{
    protected function createFeedDatabase(): void
    {
        // Never run migrations or touch the configured business database.
        if (!app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Feed tests require isolated SQLite :memory:.');
        }
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/feed-schema.json')), true) as $name => $table) {
            Schema::create($name, function (Blueprint $blueprint) use ($table) {
                foreach ($table['columns'] as $column) {
                    $name = $column['name'];
                    if ($name === 'id') { $blueprint->id(); continue; }
                    $type = $column['type_name'];
                    $field = match ($type) {
                        'bigint','int','smallint','tinyint' => $blueprint->integer($name),
                        'decimal' => $blueprint->decimal($name, 18, 7),
                        'timestamp','datetime' => $blueprint->dateTime($name),
                        default => $blueprint->text($name),
                    };
                    $field->nullable($column['nullable']);
                    $default = $column['default'];
                    if ($default !== null && $default !== 'NULL') {
                        if (strtolower($default) === 'current_timestamp()') $field->default(DB::raw('CURRENT_TIMESTAMP'));
                        else $field->default(trim($default, "'"));
                    }
                }
                foreach ($table['indexes'] as $index) {
                    if ($index['unique'] && !$index['primary']) $blueprint->unique($index['columns']);
                }
            });
        }
        // Mirror the existing production identity table/constraints, never a new operational migration.
        Schema::create('user_auth_providers', function (Blueprint $table): void {
            $table->id();$table->unsignedBigInteger('user_id');$table->string('provider',50);
            $table->string('provider_user_id',255);$table->timestamps();
            $table->unique(['provider','provider_user_id']);$table->unique(['user_id','provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        });
        // Authentication recovery now revokes Sanctum tokens. Mirror its existing
        // production table only in this guarded, in-memory test database.
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_identities', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('session_version', 64);
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->timestamp('mfa_pending_until')->nullable();
            $table->unsignedBigInteger('mfa_last_step')->nullable();
            $table->text('recovery_hashes')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('security_identities'); }
};

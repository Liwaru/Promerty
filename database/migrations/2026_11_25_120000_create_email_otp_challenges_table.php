<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_otp_challenges', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 24);
            $table->primary(['user_id', 'purpose']);
            $table->string('binding', 64);
            $table->string('digest', 64)->nullable();
            $table->unsignedBigInteger('expires');
            $table->unsignedBigInteger('sent');
            $table->unsignedBigInteger('window_start');
            $table->unsignedTinyInteger('sends');
            $table->unsignedTinyInteger('attempts');
            $table->boolean('approved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otp_challenges');
    }
};

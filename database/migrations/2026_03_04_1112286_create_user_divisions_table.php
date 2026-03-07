<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['admin', 'manager', 'member', 'support'])->default('member');
            $table->timestamps();

            $table->unique(['user_id', 'division_id']);
            $table->index(['division_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_divisions');
    }
};

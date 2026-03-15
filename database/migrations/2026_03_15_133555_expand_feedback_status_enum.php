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
        DB::statement("ALTER TABLE feedback MODIFY COLUMN status ENUM('draft','open','seen','pending','review_required','in_progress','resolved','closed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE feedback MODIFY COLUMN status ENUM('draft','seen','pending','review_required','closed') NOT NULL DEFAULT 'draft'");
    }
};

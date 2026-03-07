<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 50);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

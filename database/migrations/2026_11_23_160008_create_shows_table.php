<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table) {
            // LezWatch.TV post id, not auto-incremented here.
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->string('slug')->index();
            $table->string('url');
            $table->boolean('on_air')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('marketplace', 32);
            $table->text('search_url');
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->unsignedInteger('max_distance_km')->nullable();
            $table->json('include_keywords')->nullable();
            $table->json('exclude_keywords')->nullable();
            $table->boolean('notify_new_listings')->default(true);
            $table->boolean('notify_price_drops')->default(true);
            $table->unsignedInteger('check_interval_minutes')->default(30);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'last_checked_at']);
        });

        Schema::create('search_watch_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_watch_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('title');
            $table->text('url');
            $table->text('image')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('distance_km')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('first_price', 10, 2)->nullable();
            $table->decimal('lowest_price', 10, 2)->nullable();
            $table->string('price_text', 64)->nullable();
            $table->boolean('negotiable')->default(false);
            $table->boolean('matches_filters')->default(true);
            $table->foreignId('url_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['search_watch_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_watch_listings');
        Schema::dropIfExists('search_watches');
    }
};

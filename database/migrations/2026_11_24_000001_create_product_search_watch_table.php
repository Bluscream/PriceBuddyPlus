<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products a search watch is compared against, so used offers show up next to new prices.
     */
    public function up(): void
    {
        Schema::create('product_search_watch', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_watch_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'search_watch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_search_watch');
    }
};

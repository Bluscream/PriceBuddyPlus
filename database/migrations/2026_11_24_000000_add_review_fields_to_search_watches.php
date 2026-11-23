<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_watches', function (Blueprint $table) {
            $table->boolean('require_price')->default(false)->after('max_distance_km');
            // last_checked_at also moves on a failed check; listings still on the marketplace
            // are the ones seen at the last successful one.
            $table->timestamp('last_succeeded_at')->nullable()->after('last_checked_at');
            $table->timestamp('listings_viewed_at')->nullable()->after('last_succeeded_at');
        });

        // Before this column existed, a check without an error left last_error empty.
        DB::table('search_watches')
            ->whereNull('last_error')
            ->update(['last_succeeded_at' => DB::raw('last_checked_at')]);

        Schema::table('search_watch_listings', function (Blueprint $table) {
            // Kept so a filter change can re-score stored listings the same way a check does.
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('search_watch_listings', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('search_watches', function (Blueprint $table) {
            $table->dropColumn(['require_price', 'last_succeeded_at', 'listings_viewed_at']);
        });
    }
};

<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /** What transactions.category stores, so it survives a re-sync. */
            $table->string('value');
            $table->string('label');
            $table->timestamps();

            $table->unique(['user_id', 'value']);
        });

        /**
         * Give every existing user the built-in set, plus a row for anything
         * already filed in their transactions. That second part is what
         * surfaces the custom categories Monzo would only ever send as an id.
         */
        foreach (DB::table('users')->pluck('id') as $userId) {
            Category::seedDefaults((int) $userId);

            $inUse = DB::table('transactions')
                ->where('user_id', $userId)
                ->whereNotNull('category')
                ->distinct()
                ->pluck('category');

            foreach ($inUse as $value) {
                Category::ensure((int) $userId, (string) $value);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

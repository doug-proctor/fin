<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs rows left holding a `tags` override that protects nothing.
 *
 * Tags used to be the "#word" words inside the note, so editing a note
 * rewrote the tags and recorded `tags` in the overrides map even though the
 * user never touched a tag. The two fields are separate now, but the flag
 * those edits wrote is still on the rows, and it is permanent: the overrides
 * map means "the user owns this field", so ApplyCategoryRules skips a rule's
 * set_tags on any row carrying it. A row edited that way can never be tagged
 * by a rule again, and nothing on screen says why.
 *
 * The flag is cleared only where the row has no tags. An override exists to
 * keep something from being written over, and there is nothing there to keep.
 * A row the user did tag by hand keeps its flag and its tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->select(['id', 'tags', 'overrides'])
            ->whereNotNull('overrides')
            ->orderBy('id')
            ->chunk(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $this->repair($transaction);
                }
            });
    }

    /**
     * Nothing to reverse: which rows were edited before tags had their own
     * field is not recorded anywhere once this has run.
     */
    public function down(): void {}

    private function repair(object $transaction): void
    {
        $overrides = json_decode((string) $transaction->overrides, true);

        if (! is_array($overrides) || ($overrides['tags'] ?? false) !== true) {
            return;
        }

        $tags = json_decode((string) $transaction->tags, true);

        if (is_array($tags) && $tags !== []) {
            return;
        }

        unset($overrides['tags']);

        DB::table('transactions')
            ->where('id', $transaction->id)
            ->update(['overrides' => $overrides === [] ? null : json_encode($overrides)]);
    }
};

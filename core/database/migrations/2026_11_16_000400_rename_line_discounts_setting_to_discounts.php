<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `settings.line_discounts.*` became `settings.discounts.*` when the same
     * switch and cap started governing the invoice discount too. A shop that
     * had set it keeps its choice: the value is moved, not reset to default.
     */
    public function up(): void
    {
        $this->move('line_discounts', 'discounts');
    }

    public function down(): void
    {
        $this->move('discounts', 'line_discounts');
    }

    private function move(string $from, string $to): void
    {
        DB::table('businesses')->whereNotNull('settings')->orderBy('id')->each(function ($business) use ($from, $to) {
            $settings = json_decode($business->settings, true);

            if (! is_array($settings) || ! array_key_exists($from, $settings)) {
                return;
            }

            // If the target key somehow exists already, it wins; the source is
            // dropped either way so there is a single place to read.
            if (! array_key_exists($to, $settings)) {
                $settings[$to] = $settings[$from];
            }

            unset($settings[$from]);

            DB::table('businesses')->where('id', $business->id)->update(['settings' => json_encode($settings)]);
        });
    }
};

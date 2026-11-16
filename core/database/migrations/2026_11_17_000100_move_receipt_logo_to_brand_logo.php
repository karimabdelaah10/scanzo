<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is one logo per shop now: `settings.brand.logo`, used in the sidebar,
 * the till header and on the receipt. The receipt had its own URL field,
 * `settings.receipt.logo`; whatever a shop typed there moves across and the old
 * key goes.
 *
 * The moved value is still a link (https, or a path on the site), not a file
 * under brands/. Brand::logoUrl() returns such a value as it is, and LogoStore
 * never deletes one, so the next upload simply replaces it.
 *
 * A business with no `receipt.logo` key is not touched at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Soft-deleted businesses too: plain query builder, no model scopes.
        DB::table('businesses')->select(['id', 'settings'])->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);

            if (! is_array($settings) || ! isset($settings['receipt']) || ! is_array($settings['receipt'])
                || ! array_key_exists('logo', $settings['receipt'])) {
                return;
            }

            $logo = $settings['receipt']['logo'];
            unset($settings['receipt']['logo']);

            // An empty value (the old default) has nothing to move, but the
            // dead key still goes.
            if (is_string($logo) && $logo !== '' && empty($settings['brand']['logo'])) {
                $settings['brand']['logo'] = $logo;
            }

            DB::table('businesses')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        });
    }

    public function down(): void
    {
        DB::table('businesses')->select(['id', 'settings'])->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);
            $logo = $settings['brand']['logo'] ?? null;

            if (! is_string($logo) || $logo === '') {
                return;
            }

            // An uploaded file (brands/…) has no meaning as a receipt URL.
            if (str_starts_with($logo, 'https://') || str_starts_with($logo, '/')) {
                $settings['receipt']['logo'] = $logo;
                unset($settings['brand']['logo']);

                DB::table('businesses')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
            }
        });
    }
};

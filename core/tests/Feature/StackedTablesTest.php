<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The markup contract of public/css/tables.css: in a stacking table every cell is
 * a labelled pair (`data-label`), the card heading (`.id`), the action row
 * (`.actions`) or a note spanning the row (`.span`). A column added without one of
 * those would ship as an orphaned number on a phone, so it fails here instead.
 */
class StackedTablesTest extends TestCase
{
    /** @return list<array{file: string, html: string}> every <table …stacks…> … </table> */
    private function stackingTables(): array
    {
        $files = [
            ...glob(resource_path('js/dashboard/views/*.vue')),
            ...glob(resource_path('views/admin/businesses/*.blade.php')),
        ];
        $found = [];

        foreach ($files as $file) {
            preg_match_all('#<table\b[^>]*class="[^"]*\bstacks\b[^"]*"[^>]*>.*?</table>#s', file_get_contents($file), $m);
            foreach ($m[0] as $html) {
                $found[] = ['file' => basename($file), 'html' => $html];
            }
        }

        return $found;
    }

    public function test_every_cell_of_a_stacking_table_is_labelled_a_heading_an_action_row_or_a_note(): void
    {
        $tables = $this->stackingTables();
        $this->assertNotEmpty($tables);

        $cells = 0;
        foreach ($tables as ['file' => $file, 'html' => $html]) {
            // Key/value tables carry their label in the <th>; nothing to check on the <td>.
            if (preg_match('#^<table[^>]*\bkv\b#', $html)) {
                continue;
            }

            preg_match_all('#<td\b([^>]*)>#', $html, $tds);
            foreach ($tds[1] as $attrs) {
                $cells++;
                $ok = preg_match('#(?<![\w-])data-label=#', $attrs)
                    || preg_match('#\bclass="[^"]*\b(id|actions|span)\b#', $attrs);

                $this->assertTrue((bool) $ok, "$file: a <td{$attrs}> in a stacking table has no data-label, .id, .actions or .span");
            }
        }

        $this->assertGreaterThan(40, $cells, 'the scan found suspiciously few cells; the pattern may have stopped matching');
    }

    public function test_every_screen_in_the_set_has_a_stacking_table(): void
    {
        $views = resource_path('js/dashboard/views/');
        foreach (['Products', 'Invoices', 'Shifts', 'StockTransfers', 'Movements', 'Stock', 'StockCount', 'Team', 'Branches', 'Devices', 'Reports', 'ProductNew', 'Import'] as $name) {
            $this->assertMatchesRegularExpression('#<table\b[^>]*\bstacks\b#', file_get_contents("$views$name.vue"), "$name.vue has no stacking table");
        }

        foreach (['index', 'created'] as $name) {
            $this->assertMatchesRegularExpression('#<table\b[^>]*\bstacks\b#', file_get_contents(resource_path("views/admin/businesses/$name.blade.php")), "admin/businesses/$name has no stacking table");
        }

        // A wrapper holding a stacking table stops scrolling sideways below the breakpoint.
        $css = file_get_contents(public_path('css/tables.css'));
        $this->assertStringContainsString(':has(> table.stacks)', $css);
        $this->assertMatchesRegularExpression('#@media \(max-width: 640px\)#', $css);
    }

    public function test_numbers_keep_their_digits_in_order_in_an_rtl_card(): void
    {
        $css = file_get_contents(public_path('css/tables.css'));

        $this->assertMatchesRegularExpression('#table\.stacks td\.num[^{]*\{[^}]*unicode-bidi: plaintext#', $css);
    }

    public function test_both_shells_link_the_stylesheet_with_the_version(): void
    {
        foreach (['dashboard', 'admin/layout'] as $view) {
            $this->assertStringContainsString("asset('css/tables.css') }}?v=", file_get_contents(resource_path("views/$view.blade.php")));
        }
    }
}

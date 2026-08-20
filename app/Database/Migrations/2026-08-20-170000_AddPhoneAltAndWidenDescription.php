<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Two unrelated column changes that arrived in the same round of form work.
 *
 * `phone_alt` — a second contact number. Nearly every business here has a
 * landline and a mobile, and until now the second one had to go in the
 * description or be left out. Same width as `phone`: these are typed by hand and
 * 40 characters already holds an international number with spaces.
 *
 * `description` TEXT → MEDIUMTEXT — headroom, not a feature. The editorial cap
 * doubled and a bit (2000 → 5000 plain characters), and the structural ceiling
 * on stored *markup* went with it (20000 → 50000). MySQL's TEXT holds 65,535
 * **bytes**, not characters, so a 50,000-character HTML value carrying multibyte
 * content can overflow it — and the failure mode is MySQL truncating mid-tag on
 * save, producing stored HTML that no longer parses. MEDIUMTEXT removes the
 * question entirely for the price of one extra length byte per row.
 *
 * `description_text` deliberately stays TEXT: 5,000 characters cannot overflow
 * 65,535 bytes even at four bytes a character.
 *
 * Nothing indexes `description` — the FULLTEXT index moved onto
 * `description_text` in 2026-08-20-140000 — so this is a plain column change
 * with no index to drop and rebuild.
 */
class AddPhoneAltAndWidenDescription extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('directory_listings', [
            'phone_alt' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'phone',
                'comment'    => 'Second contact number, e.g. a mobile alongside a landline',
            ],
        ]);

        $this->forge->modifyColumn('directory_listings', [
            'description' => ['type' => 'MEDIUMTEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('directory_listings', 'phone_alt');

        // Narrowing back would truncate any description that has since grown
        // past 65,535 bytes, so this is the one direction that can lose data.
        // Left as-is on purpose: a wider column breaks nothing on the way down.
    }
}

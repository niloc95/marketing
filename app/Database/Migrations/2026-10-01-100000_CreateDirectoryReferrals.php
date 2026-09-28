<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Recommend a business": visitors naming a business that should be listed.
 *
 * `directory_referrals` is a queue, not a mailing list. Nothing here emails the
 * business on a visitor's say-so; an admin reads each row and decides whether
 * to send the one invite (ReferralService::invite()). That is what keeps the
 * form from being a way to make our domain email any address.
 *
 * `directory_invite_suppressions` holds the hashed addresses of businesses that
 * clicked "don't contact me again". Hashed because the row only ever has to
 * answer "is this address blocked?", and it outlives the referral it came from.
 *
 * Additive: nothing reads either table until the code that does ships.
 */
class CreateDirectoryReferrals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'business_name'   => ['type' => 'VARCHAR', 'constraint' => 200],
            'business_email'  => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'business_phone'  => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'website'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'category_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'province'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'note'            => ['type' => 'TEXT', 'null' => true],
            'referrer_name'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'referrer_email'  => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'notify_referrer' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'relationship'    => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'customer', 'comment' => 'customer | owner_or_staff | other'],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending', 'comment' => 'pending | invited | listed | dismissed'],
            'invite_token'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'TokenHash::hash() of the emailed token'],
            'invited_at'      => ['type' => 'DATETIME', 'null' => true],
            'listing_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'ip_hash'         => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'pruned_at'       => ['type' => 'DATETIME', 'null' => true, 'comment' => 'Contact details wiped (POPIA retention)'],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('business_email');
        $this->forge->addUniqueKey('invite_token');
        $this->forge->addForeignKey('category_id', 'directory_categories', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('directory_referrals', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'email_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email_hash');
        $this->forge->createTable('directory_invite_suppressions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_invite_suppressions', true);
        $this->forge->dropTable('directory_referrals', true);
    }
}

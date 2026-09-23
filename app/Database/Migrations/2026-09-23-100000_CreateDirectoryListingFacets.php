<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Structured, filterable facts about a listing — ages taken, curriculum,
 * grades offered, fees from.
 *
 * Sibling of directory_listing_attributes, and deliberately a second table
 * rather than columns on that one. An attribute row is a bare key: the listing
 * has wheelchair access or it does not, and the whole fact fits in the key's
 * existence. A facet row carries a *value* ('ieb'), or two numbers (ages 18 to
 * 72 months), and adding three mostly-null columns to the attributes table
 * would have made every tick-box pay for them.
 *
 * What facets exist, their types, their option lists and which category offers
 * them live in Config\ListingFacets, so the same rule holds as for attributes:
 * rewording a label needs no migration, and a facet removed from the config
 * stops rendering without one either. Written only through
 * App\Services\ListingFacetService, which filters every key and value against
 * that config first.
 *
 * Three shapes share the table:
 *
 *   type 'one'   one row, value set, numbers null
 *   type 'multi' one row per chosen option, value set, numbers null
 *   type 'range' one row, value '', num_low and num_high set
 *
 * value is NOT NULL DEFAULT '' rather than nullable precisely so a range row
 * can sit in the primary key alongside the others — MySQL will not enforce
 * uniqueness across a NULL, so a nullable value column would have let a listing
 * accumulate duplicate range rows silently.
 *
 * Two indexes, for the two ways browse() asks:
 *   (facet_key, value)            "every school with curriculum = ieb"
 *   (facet_key, num_low, num_high) "every preschool whose range covers 36 months"
 * The first mirrors the lone attribute_key index that migration added for the
 * same reason. The second is what makes an age filter an index range scan
 * rather than a scan of every facet row on the site.
 */
class CreateDirectoryListingFacets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'listing_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'comment' => 'FK xs_directory_listings'],
            'facet_key'  => ['type' => 'VARCHAR', 'constraint' => 40, 'comment' => 'Key from Config\ListingFacets'],
            'value'      => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => '', 'comment' => "Option key; '' for a range facet"],
            'num_low'    => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'comment' => 'Range low bound (months, or rand)'],
            'num_high'   => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'comment' => 'Range high bound; null when one-sided'],
        ]);
        $this->forge->addKey(['listing_id', 'facet_key', 'value'], true);
        $this->forge->addKey(['facet_key', 'value']);
        $this->forge->addKey(['facet_key', 'num_low', 'num_high']);
        $this->forge->addForeignKey('listing_id', 'directory_listings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('directory_listing_facets', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('directory_listing_facets', true);
    }
}

<?php

namespace App\Models\Bookings;

use CodeIgniter\Model;
use LogicException;

/**
 * Base for every table that belongs to one business (`listing_id`).
 *
 * Bookings ran one business per install, so none of its queries say which
 * business they mean. Here hundreds share one database, so a forgotten
 * `where('listing_id')` would show one business another's customers. This
 * class makes that mistake loud instead of silent:
 *
 * - open a model with `forBusiness($listingId)`;
 * - every find, update and delete through it is limited to that business, and
 *   every insert is stamped with it;
 * - a model opened without a business throws on its first find, update,
 *   delete or insert.
 *
 * BookingScopeTest pins all of it. Raw query builder calls (`->builder()`,
 * `db_connect()`) are not covered: use the model.
 */
abstract class BookingScopedModel extends Model
{
    protected $returnType = 'array';

    protected $allowCallbacks = true;

    protected $beforeFind   = ['applyScope'];
    protected $beforeInsert = ['stampScope'];
    protected $beforeInsertBatch = ['stampScopeBatch'];
    protected $beforeUpdate = ['applyScope'];
    protected $beforeDelete = ['applyScope'];

    private ?int $scopeListingId = null;

    /** A model limited to one business. */
    public static function forBusiness(int $listingId): static
    {
        if ($listingId <= 0) {
            throw new LogicException('A business scope needs a listing id.');
        }

        $model = new static();
        $model->scopeListingId = $listingId;

        return $model;
    }

    /**
     * countAllResults() fires no model event, so without this a count (and
     * paginate(), which counts first) would cover every business.
     */
    public function countAllResults(bool $reset = true, bool $test = false)
    {
        $this->builder()->where($this->table . '.listing_id', $this->requireScope());

        return parent::countAllResults($reset, $test);
    }

    public function scopeListingId(): ?int
    {
        return $this->scopeListingId;
    }

    /** @param array<string,mixed> $data */
    protected function applyScope(array $data): array
    {
        $this->builder()->where($this->table . '.listing_id', $this->requireScope());

        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function stampScope(array $data): array
    {
        $listingId = $this->requireScope();
        if (isset($data['data']['listing_id']) && (int) $data['data']['listing_id'] !== $listingId) {
            throw new LogicException('Refusing to write a row for another business.');
        }
        $data['data']['listing_id'] = $listingId;

        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function stampScopeBatch(array $data): array
    {
        foreach ($data['data'] as $i => $row) {
            $stamped = $this->stampScope(['data' => $row]);
            $data['data'][$i] = $stamped['data'];
        }

        return $data;
    }

    private function requireScope(): int
    {
        if ($this->scopeListingId === null) {
            throw new LogicException(static::class . ' was used without a business. Open it with forBusiness().');
        }

        return $this->scopeListingId;
    }
}

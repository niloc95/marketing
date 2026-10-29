<?php

namespace Tests\Support;

use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DatabaseTestTrait, with the schema built once per run and the tables
 * emptied before each test (see TestSchema). Use it in place of
 * DatabaseTestTrait in every DB test class; $migrate, $migrateOnce, $refresh
 * and $namespace no longer do anything.
 *
 * The throttler is reset too. It is a shared service, so it keeps the first
 * test's MockCache, and with it every bucket, for the rest of the process.
 * Signups allow 3 an hour per IP: the old 40 minute suite refilled that as it
 * went, but at seconds per run the fourth signup in a row was throttled.
 */
trait FreshDatabase
{
    use DatabaseTestTrait;

    protected function setUpMigrate()
    {
        TestSchema::build($this->db, $this->migrations);
        TestSchema::reset($this->db);
        \Config\Services::resetSingle('throttler');
    }
}

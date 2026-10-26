<?php

// Isolated in-memory database; never connects to production or sends withdrawals.
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

$db = new Manager();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->getContainer()->instance('db', $db->getDatabaseManager());
$db->getContainer()->instance('db.schema', $db->schema());
Facade::setFacadeApplication($db->getContainer());
Schema::create('withdrawals', function (Blueprint $table) {
    $table->increments('id');
    $table->string('account_id');
    $table->integer('amount');
});
$db->table('withdrawals')->insert(['account_id' => 'existing', 'amount' => 2000]);
$migration = require __DIR__.'/../database/migrations/2026_10_21_180000_repair_withdrawal_idempotency_schema.php';
$migration->up();
$migration->up();
if (!Schema::hasColumn('withdrawals', 'idempotency_key')
    || !Schema::hasColumn('withdrawals', 'idempotency_request_hash')
    || !Schema::hasIndex('withdrawals', 'withdrawals_account_id_idempotency_key_unique', 'unique')
    || !Schema::hasIndex('withdrawals', 'withdrawals_account_id_idempotency_hash_index')) {
    throw new RuntimeException('Missing idempotency schema');
}
if ($db->table('withdrawals')->where('account_id', 'existing')->value('amount') !== 2000) {
    throw new RuntimeException('Existing data changed');
}
$record = ['account_id' => 'test', 'amount' => 2000, 'idempotency_key' => 'same-request', 'idempotency_request_hash' => str_repeat('a', 64)];
$db->table('withdrawals')->insert($record);
$blocked = false;
try {
    $db->table('withdrawals')->insert($record);
} catch (Illuminate\Database\QueryException $e) {
    $blocked = str_contains($e->getMessage(), 'UNIQUE constraint failed');
}
if (!$blocked) {
    throw new RuntimeException('Duplicate request was not blocked');
}
$db->table('withdrawals')->insert(array_replace($record, ['account_id' => 'another-account']));
$db->table('withdrawals')->insert(['account_id' => 'existing', 'amount' => 100]);
$migration->down();
if ($db->table('withdrawals')->where('idempotency_key', 'same-request')->count() !== 2) {
    throw new RuntimeException('Keys must be retained on rollback');
}
echo "PASS: repair, repeatability, existing data, unique requests per account, nullable keys and safe rollback.\n";

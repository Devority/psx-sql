<?php
/*
 * PSX is an open source PHP framework to develop RESTful APIs.
 * For the current version and information visit <https://phpsx.org>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace PSX\Sql\MongoDB;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Session;
use PSX\Sql\Condition;
use PSX\Sql\OrderBy;
use PSX\Sql\Storage\StorageInterface;
use PSX\Sql\TableInterface;

/**
 * MongoStorage
 *
 * MongoDB-backed implementation of StorageInterface. Every table maps to a
 * collection of the same name; the column "id" is used as primary key with
 * values generated through a "psx_sequences" counter collection (findOneAndUpdate $inc).
 *
 * Transactions require a replica set (a single-node rs0 is enough); on a
 * standalone mongod they are silently degraded to no-ops.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://phpsx.org
 */
class MongoStorage implements StorageInterface
{
    private Database $database;
    private ?Session $session = null;
    private ?int $lastInsertId = null;
    private ?string $dsn = null;

    public function __construct(Client $client, string $database)
    {
        $this->database = $client->selectDatabase($database);
    }

    public static function fromDsn(string $dsn): self
    {
        // mongodb://user:pass@host:port/dbname
        $parts = parse_url($dsn);
        $db = isset($parts['path']) ? ltrim($parts['path'], '/') : 'fusio';
        $client = new Client($dsn);
        $storage = new self($client, $db !== '' ? $db : 'fusio');
        $storage->dsn = $dsn;
        return $storage;
    }

    /**
     * Returns the DSN this storage was created from or null when constructed manually
     */
    public function getDsn(): ?string
    {
        return $this->dsn;
    }

    public function findAll(TableInterface $table, array $fields, int $startIndex, int $count, ?string $sortBy, OrderBy $sortOrder, ?Condition $condition = null): array
    {
        $options = [
            'projection' => array_fill_keys($fields, 1),
            'skip'       => $startIndex,
        ];
        if ($count > 0) {
            $options['limit'] = $count;
        }
        if ($sortBy !== null) {
            $options['sort'] = [$sortBy => $sortOrder === OrderBy::ASC ? 1 : -1];
        }
        if ($this->session !== null) {
            $options['session'] = $this->session;
        }

        $cursor = $this->collection($table)->find(
            ConditionTranslator::translate($condition),
            $options
        );

        $result = [];
        foreach ($cursor as $doc) {
            $result[] = self::denormalize((array) $doc, true);
        }
        return $result;
    }

    public function findOneBy(TableInterface $table, array $fields, Condition $condition): ?array
    {
        $rows = $this->findAll($table, $fields, 0, 1, null, OrderBy::ASC, $condition);
        return $rows[0] ?? null;
    }

    public function count(TableInterface $table, ?Condition $condition = null): int
    {
        return $this->collection($table)->countDocuments(
            ConditionTranslator::translate($condition),
            $this->session !== null ? ['session' => $this->session] : []
        );
    }

    public function insert(TableInterface $table, array $fields): int
    {
        $fields = self::normalize($fields);
        if (!isset($fields['id'])) {
            $fields['id'] = $this->nextId($table->getName());
        }

        $options = $this->session !== null ? ['session' => $this->session] : [];
        $result = $this->collection($table)->insertOne($fields, $options);
        $this->lastInsertId = $fields['id'];

        return $result->getInsertedCount();
    }

    public function update(TableInterface $table, array $fields, array $criteria): int
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        $result = $this->collection($table)->updateOne(
            self::normalize($criteria),
            ['$set' => self::normalize($fields)],
            $options
        );
        return $result->getModifiedCount();
    }

    public function updateBy(TableInterface $table, Condition $condition, array $fields): int
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        $result = $this->collection($table)->updateMany(
            ConditionTranslator::translate($condition),
            ['$set' => self::normalize($fields)],
            $options
        );
        return $result->getModifiedCount();
    }

    public function delete(TableInterface $table, array $criteria): int
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        return $this->collection($table)->deleteOne(self::normalize($criteria), $options)->getDeletedCount();
    }

    public function deleteBy(TableInterface $table, Condition $condition): int
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        return $this->collection($table)->deleteMany(ConditionTranslator::translate($condition), $options)->getDeletedCount();
    }

    public function lastInsertId(): ?int
    {
        return $this->lastInsertId;
    }

    public function beginTransaction(): void
    {
        if ($this->session === null) {
            $this->session = $this->database->getManager()->startSession();
        }
        try {
            $this->session->startTransaction();
        } catch (\Throwable) {
            // standalone mongod has no transactions — degrade to no-op
            $this->session = null;
        }
    }

    public function commit(): void
    {
        $this->session?->commitTransaction();
        $this->session = null;
    }

    public function rollBack(): void
    {
        try {
            $this->session?->abortTransaction();
        } catch (\Throwable) {
        }
        $this->session = null;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Raw find against an arbitrary collection (for view/reporting code that
     * does not go through a generated Table class).
     */
    public function rawFind(string $collection, array $filter = [], array $options = []): array
    {
        if ($this->session !== null) {
            $options['session'] = $this->session;
        }
        $result = [];
        foreach ($this->database->selectCollection($collection)->find($filter, $options) as $doc) {
            $result[] = self::denormalize((array) $doc);
        }
        return $result;
    }

    /**
     * Mongo aggregation pipeline for report/group queries.
     */
    /**
     * Raw count on an arbitrary collection.
     */
    public function rawCount(string $collection, array $filter = []): int
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        return $this->database->selectCollection($collection)->countDocuments($filter, $options);
    }

    public function aggregate(string $collection, array $pipeline): array
    {
        $options = $this->session !== null ? ['session' => $this->session] : [];
        $result = [];
        foreach ($this->database->selectCollection($collection)->aggregate($pipeline, $options) as $doc) {
            $result[] = self::denormalize((array) $doc);
        }
        return $result;
    }

    /**
     * Group-by-day aggregation for time series (replaces GROUP BY DATE(col)).
     *
     * @return array<string, int|float> map day 'Y-m-d' => value
     */
    public function groupByDay(string $collection, array $filter, string $dateField = 'date', string $aggregate = 'count', string $valueField = 'id'): array
    {
        $groupExpr = match ($aggregate) {
            'count' => ['$sum' => 1],
            'sum'   => ['$sum' => '$' . $valueField],
            'avg'   => ['$avg' => '$' . $valueField],
            default => throw new \LogicException('Unsupported aggregate ' . $aggregate),
        };
        $pipeline = [
            ['$match' => $filter],
            ['$group' => [
                '_id'   => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => ['$toDate' => '$' . $dateField]]],
                'value' => $groupExpr,
            ]],
        ];
        $out = [];
        foreach ($this->aggregate($collection, $pipeline) as $row) {
            $out[$row['_id']] = $row['value'];
        }
        return $out;
    }

    /**
     * Group-by-(day, key) aggregation — replaces GROUP BY DATE(d), fk.
     *
     * @return array<string|int, array<string, int|float>> map key => day => value
     */
    public function groupByDayAndKey(string $collection, array $filter, string $keyField, string $dateField = 'date', string $aggregate = 'count', string $valueField = 'id'): array
    {
        $groupExpr = match ($aggregate) {
            'count' => ['$sum' => 1],
            'sum'   => ['$sum' => '$' . $valueField],
            'avg'   => ['$avg' => '$' . $valueField],
            default => throw new \LogicException('Unsupported aggregate ' . $aggregate),
        };
        $pipeline = [
            ['$match' => $filter],
            ['$group' => [
                '_id'   => ['key' => '$' . $keyField, 'day' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => ['$toDate' => '$' . $dateField]]]],
                'value' => $groupExpr,
            ]],
        ];
        $out = [];
        foreach ($this->aggregate($collection, $pipeline) as $row) {
            $out[$row['_id']['key']][$row['_id']['day']] = $row['value'];
        }
        return $out;
    }

    /**
     * Flat variant of groupByDayAndKey returning SQL-shaped rows
     * [key, date, value] so existing loop code works unchanged.
     */
    public function groupByDayAndKeyFlat(string $collection, array $filter, string $keyField, string $dateField = 'date', string $aggregate = 'count', string $valueField = 'id'): array
    {
        $nested = $this->groupByDayAndKey($collection, $filter, $keyField, $dateField, $aggregate, $valueField);
        $rows = [];
        foreach ($nested as $key => $perDay) {
            foreach ($perDay as $day => $value) {
                $rows[] = [$key, $day, $value];
            }
        }
        return $rows;
    }

    /**
     * Top-N group count with an optional lookup for the human-readable name
     * (replaces GROUP BY fk + INNER JOIN for label resolution).
     *
     * @return array<int, array{id: mixed, name: string|null, cnt: int}>
     */
    public function topGroups(string $collection, array $filter, string $groupField, ?string $joinCollection = null, string $nameField = 'name', int $limit = 6): array
    {
        $pipeline = [
            ['$match' => $filter],
            ['$group' => ['_id' => '$' . $groupField, 'cnt' => ['$sum' => 1]]],
            ['$sort' => ['cnt' => -1]],
            ['$limit' => $limit],
        ];
        if ($joinCollection !== null) {
            $pipeline[] = ['$lookup' => [
                'from' => $joinCollection,
                'localField' => '_id',
                'foreignField' => 'id',
                'as' => 'joined',
            ]];
            $pipeline[] = ['$unwind' => ['path' => '$joined', 'preserveNullAndEmptyArrays' => true]];
        }
        $out = [];
        foreach ($this->aggregate($collection, $pipeline) as $row) {
            $out[] = [
                'id'   => $row['_id'],
                'name' => $row['joined'][$nameField] ?? null,
                'cnt'  => (int) ($row['cnt'] ?? 0),
            ];
        }
        return $out;
    }

    private function collection(TableInterface $table): Collection
    {
        return $this->database->selectCollection($table->getName());
    }

    private function nextId(string $collection): int
    {
        $doc = $this->database->selectCollection('psx_sequences')->findOneAndUpdate(
            ['_id' => $collection],
            ['$inc' => ['seq' => 1]],
            ['upsert' => true, 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        return (int) ($doc['seq'] ?? 1);
    }

    /**
     * Creates the baseline indexes every Fusio-style installation needs:
     * a dense unique index on the sequence-allocated "id" column and
     * name-keyed uniqueness for lookup tables. Called by installers;
     * safe to re-run (createIndexes is idempotent).
     */
    public static function ensureIndexes(Database $db): void
    {
        $tables = self::ALL_TABLES;
        foreach ($db->listCollectionNames() as $name) {
            if (str_starts_with($name, 'fusio_') && !in_array($name, $tables, true)) {
                $tables[] = $name;
            }
        }
        foreach ($tables as $name) {
            // creates the collection implicitly when absent
            $db->selectCollection($name)->createIndex(['id' => 1], ['name' => 'ux_id']);
        }

        foreach (self::NAME_UNIQUE_TABLES as $name) {
            $db->selectCollection($name)->createIndex(
                ['tenant_id' => 1, 'name' => 1],
                ['name' => 'ux_tenant_name']
            );
        }

        foreach (self::FIELD_INDEXES as $name => $fields) {
            foreach ($fields as $spec) {
                $db->selectCollection($name)->createIndex($spec, ['name' => 'ix_' . array_key_first($spec)]);
            }
        }
    }

    /**
     * Records migration bookkeeping so schema-history consumers (upgrade
     * commands, `system:check`) can see which Doctrine versions are
     * logically applied even though DDL is skipped on schemaless stores.
     *
     * @param list<string> $versions
     */
    public static function recordMigrations(Database $db, array $versions): void
    {
        $col = $db->selectCollection('psx_migrations');
        foreach ($versions as $version) {
            $col->updateOne(
                ['_id' => $version],
                ['$setOnInsert' => ['applied_at' => new \MongoDB\BSON\UTCDateTime()]],
                ['upsert' => true]
            );
        }
    }

    private const ALL_TABLES = [
        'fusio_action', 'fusio_action_commit', 'fusio_agent', 'fusio_app',
        'fusio_app_code', 'fusio_app_scope', 'fusio_audit', 'fusio_bundle',
        'fusio_category', 'fusio_config', 'fusio_connection', 'fusio_cronjob',
        'fusio_event', 'fusio_event_response', 'fusio_event_trigger',
        'fusio_firewall', 'fusio_firewall_log', 'fusio_form', 'fusio_form_element',
        'fusio_identity', 'fusio_log', 'fusio_log_error', 'fusio_meta',
        'fusio_operation', 'fusio_page', 'fusio_plan', 'fusio_plan_scope',
        'fusio_rate', 'fusio_rate_allocation', 'fusio_role', 'fusio_role_scope',
        'fusio_schema', 'fusio_schema_commit', 'fusio_scope', 'fusio_scope_operation',
        'fusio_taxonomy', 'fusio_test', 'fusio_token', 'fusio_transaction',
        'fusio_trigger', 'fusio_user', 'fusio_user_grant', 'fusio_user_scope',
        'fusio_webhook', 'fusio_webhook_response', 'psx_sequences', 'psx_migrations',
    ];

    private const NAME_UNIQUE_TABLES = [
        'fusio_action', 'fusio_agent', 'fusio_app', 'fusio_bundle',
        'fusio_category', 'fusio_connection', 'fusio_event', 'fusio_identity',
        'fusio_operation', 'fusio_page', 'fusio_plan', 'fusio_rate',
        'fusio_role', 'fusio_schema', 'fusio_scope', 'fusio_user',
        'fusio_webhook',
    ];

    private const FIELD_INDEXES = [
        'fusio_token'        => [['token' => 1], ['expire' => 1]],
        'fusio_log'          => [['date' => 1], ['ip' => 1], ['operation_id' => 1]],
        'fusio_log_error'    => [['log_id' => 1]],
        'fusio_user_scope'   => [['user_id' => 1], ['scope_id' => 1]],
        'fusio_app_scope'    => [['app_id' => 1], ['scope_id' => 1]],
        'fusio_role_scope'   => [['role_id' => 1], ['scope_id' => 1]],
        'fusio_scope_operation' => [['scope_id' => 1], ['operation_id' => 1]],
        'fusio_rate_allocation' => [['operation_id' => 1]],
        'fusio_app_code'     => [['app_id' => 1], ['code' => 1]],
        'fusio_user_grant'   => [['user_id' => 1], ['app_id' => 1]],
        'fusio_audit'        => [['date' => 1], ['ref_id' => 1]],
        'fusio_meta'         => [['name' => 1]],
    ];

    private static function normalize(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $fields[$key] = new UTCDateTime($value);
            }
        }
        return $fields;
    }

    private static function denormalize(array $row, bool $datesAsObjects = false): array
    {
        // keep scalar _id (aggregation group keys); drop BSON ObjectId _id of stored docs
        if (($row['_id'] ?? null) instanceof \MongoDB\BSON\ObjectId) {
            unset($row['_id']);
        }
        foreach ($row as $key => $value) {
            if ($value instanceof UTCDateTime) {
                $row[$key] = $datesAsObjects
                    ? $value->toDateTime()
                    : $value->toDateTime()->format('Y-m-d H:i:s');
            } elseif ($value instanceof \ArrayObject || $value instanceof \stdClass) {
                $row[$key] = json_decode(json_encode($value), true);
            }
        }
        return $row;
    }
}

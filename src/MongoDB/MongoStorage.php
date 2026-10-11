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
        return new self($client, $db !== '' ? $db : 'fusio');
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
            $result[] = self::denormalize((array) $doc);
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

    private static function normalize(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $fields[$key] = new UTCDateTime($value);
            }
        }
        return $fields;
    }

    private static function denormalize(array $row): array
    {
        unset($row['_id']);
        foreach ($row as $key => $value) {
            if ($value instanceof UTCDateTime) {
                $row[$key] = $value->toDateTime()->format('Y-m-d H:i:s');
            } elseif ($value instanceof \ArrayObject || $value instanceof \stdClass) {
                $row[$key] = json_decode(json_encode($value), true);
            }
        }
        return $row;
    }
}

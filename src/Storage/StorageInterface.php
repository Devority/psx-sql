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

namespace PSX\Sql\Storage;

use PSX\Sql\Condition;
use PSX\Sql\OrderBy;
use PSX\Sql\TableInterface;

/**
 * StorageInterface
 *
 * Engine-agnostic storage backend for TableAbstract. The default SQL
 * implementation delegates to the DBAL connection; alternative backends
 * (e.g. MongoDB) implement the same contract so generated Table classes
 * work unchanged.
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://phpsx.org
 */
interface StorageInterface
{
    /**
     * @return array<int, array<string, mixed>> raw rows for project()
     */
    public function findAll(TableInterface $table, array $fields, int $startIndex, int $count, ?string $sortBy, OrderBy $sortOrder, ?Condition $condition = null): array;

    public function findOneBy(TableInterface $table, array $fields, Condition $condition): ?array;

    public function count(TableInterface $table, ?Condition $condition = null): int;

    /**
     * @return int number of inserted rows; implementations should also remember
     *             the generated id for lastInsertId()
     */
    public function insert(TableInterface $table, array $fields): int;

    public function update(TableInterface $table, array $fields, array $criteria): int;

    public function updateBy(TableInterface $table, Condition $condition, array $fields): int;

    public function delete(TableInterface $table, array $criteria): int;

    public function deleteBy(TableInterface $table, Condition $condition): int;

    public function lastInsertId(): ?int;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}

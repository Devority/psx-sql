<?php
/*
 * PSX is an open source PHP framework to develop RESTful APIs.
 * For the current version and information visit <https://phpsx.org>
 *
 * Copyright (c) Christoph Kappestein <christoph.kappestein@gmail.com>
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

namespace PSX\Sql;

use Doctrine\DBAL\Connection;

/**
 * ViewAbstract
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://phpsx.org
 */
abstract class ViewAbstract implements ViewInterface
{
    use ViewTrait;

    protected ?Connection $connection;
    protected ?Storage\StorageInterface $storage;
    private TableManagerInterface $tableManager;

    public function __construct(TableManagerInterface $tableManager)
    {
        if ($tableManager instanceof Storage\StorageAwareTableManagerInterface) {
            $this->storage = $tableManager->getStorage();
            // non-SQL backends get a harmless in-memory sqlite connection for
            // DBAL-only utilities (identifier quoting, nested result builders)
            $this->connection = \Doctrine\DBAL\DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ]);
        } else {
            $this->storage = null;
            $this->connection = $tableManager->getConnection();
        }
        $this->tableManager = $tableManager;
    }

    /**
     * Returns the active storage backend, or null when the SQL path is used.
     * Views with raw SQL should branch on this (or throw NotSupported) — see
     * StorageInterface::findAll for a portable query path.
     */
    protected function getStorage(): ?Storage\StorageInterface
    {
        return $this->storage;
    }
}

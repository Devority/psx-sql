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

use MongoDB\BSON\Regex;
use PSX\Sql\ComparisonOperator;
use PSX\Sql\Condition;
use PSX\Sql\Condition\Basic;
use PSX\Sql\Condition\Between;
use PSX\Sql\Condition\ExpressionInterface;
use PSX\Sql\Condition\In;
use PSX\Sql\Condition\Nil;
use PSX\Sql\Condition\NotIn;
use PSX\Sql\Condition\NotNil;
use PSX\Sql\Condition\Raw;
use PSX\Sql\Condition\Regexp;
use PSX\Sql\LogicOperator;

/**
 * ConditionTranslator
 *
 * Translates a PSX Sql Condition tree into a MongoDB query filter.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @link    https://phpsx.org
 */
class ConditionTranslator
{
    /**
     * @return array<string, mixed> mongo filter document
     */
    public static function translate(?Condition $condition): array
    {
        if ($condition === null || !$condition->hasCondition()) {
            return [];
        }

        $parts = [];
        foreach ($condition->getConditions() as $expression) {
            $parts[] = self::translateExpression($expression);
        }

        $parts = array_filter($parts, fn ($part) => $part !== []);
        if (empty($parts)) {
            return [];
        }

        $combined = count($parts) === 1
            ? $parts[0]
            : [($condition->getOperator() === LogicOperator::OR ? '$or' : '$and') => $parts];

        if ($condition->isInverse()) {
            return ['$nor' => [$combined]];
        }

        return $combined;
    }

    /**
     * @return array<string, mixed>
     */
    private static function translateExpression(ExpressionInterface $expression): array
    {
        if ($expression instanceof Condition) {
            return self::translate($expression);
        }

        $column = self::columnName($expression);

        if ($expression instanceof Basic) {
            $value = $expression->getValues()[0] ?? null;
            return [$column => self::operatorFilter($expression->getOperator(), $value)];
        }

        if ($expression instanceof In) {
            return [$column => ['$in' => array_map(self::normalizeValue(...), $expression->getValues())]];
        }

        if ($expression instanceof NotIn) {
            return [$column => ['$nin' => array_map(self::normalizeValue(...), $expression->getValues())]];
        }

        if ($expression instanceof Between) {
            [$left, $right] = array_map(self::normalizeValue(...), $expression->getValues());
            return [$column => ['$gte' => $left, '$lte' => $right]];
        }

        if ($expression instanceof Nil) {
            return [$column => ['$eq' => null]];
        }

        if ($expression instanceof NotNil) {
            return [$column => ['$ne' => null]];
        }

        if ($expression instanceof Regexp) {
            $value = (string) ($expression->getValues()[0] ?? '');
            return [$column => ['$regex' => new Regex($value)]];
        }

        if ($expression instanceof Raw) {
            // raw SQL fragments cannot be translated; keep the query fail-fast
            // instead of silently returning wrong data
            throw new \LogicException('Raw conditions are not supported by the MongoDB backend: ' . $expression->getValues()[0]);
        }

        throw new \LogicException('Unsupported condition expression: ' . get_debug_type($expression));
    }

    /**
     * @return array<string, mixed>|scalar|null
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return new \MongoDB\BSON\UTCDateTime($value);
        }
        return $value;
    }

    private static function operatorFilter(ComparisonOperator $operator, mixed $value): mixed
    {
        $value = self::normalizeValue($value);

        return match ($operator) {
            ComparisonOperator::EQUALS => $value,
            ComparisonOperator::NOT_EQUALS => ['$ne' => $value],
            ComparisonOperator::GREATER => ['$gt' => $value],
            ComparisonOperator::GREATER_THAN => ['$gte' => $value],
            ComparisonOperator::LESS => ['$lt' => $value],
            ComparisonOperator::LESS_THAN => ['$lte' => $value],
            ComparisonOperator::LIKE, ComparisonOperator::NOT_LIKE => self::likeFilter($operator, $value),
        };
    }

    /**
     * @return array<string, Regex>
     */
    private static function likeFilter(ComparisonOperator $operator, mixed $value): array
    {
        $pattern = str_replace(['%', '_'], ['.*', '.'], (string) $value);
        $regex = new Regex('^' . $pattern . '$');
        return $operator === ComparisonOperator::LIKE
            ? ['$regex' => $regex]
            : ['$not' => $regex];
    }

    private static function columnName(ExpressionInterface $expression): string
    {
        if ($expression instanceof Condition\ExpressionAbstract) {
            $column = $expression->getColumn();
        } else {
            $accessor = new \ReflectionProperty($expression, 'column');
            $column = $accessor->getValue($expression);
        }
        // SQL table aliases (log.tenant_id) do not exist in documents
        $pos = strrpos($column, '.');
        return $pos !== false ? substr($column, $pos + 1) : $column;
    }
    /**
     * Translates only expressions whose column carries the given alias prefix.
     * Used for $lookup pipelines where conditions span two collections —
     * e.g. translate($condition, 'error') returns a filter on fusio_log_error
     * fields only (alias stripped), while other-alias parts are dropped.
     * Alias-free columns are always kept.
     */
    public static function translateForAlias(?Condition $condition, string $alias, bool $keepAlias = false): array
    {
        if ($condition === null || !$condition->hasCondition()) {
            return [];
        }
        $parts = [];
        foreach ($condition->getConditions() as $expression) {
            $part = self::translateExpressionForAlias($expression, $alias, $keepAlias);
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        return self::combine($parts, $condition->getOperator());
    }

    private static function translateExpressionForAlias($expression, string $alias, bool $keepAlias): ?array
    {
        if ($expression instanceof Condition) {
            $parts = [];
            foreach ($expression->getConditions() as $sub) {
                $part = self::translateExpressionForAlias($sub, $alias, $keepAlias);
                if ($part !== null) {
                    $parts[] = $part;
                }
            }
            return self::combine($parts, $expression->getOperator());
        }
        // leaf — check alias
        if ($expression instanceof Condition\ExpressionAbstract) {
            $col = $expression->getColumn();
            $pos = strrpos($col, '.');
            $colAlias = $pos !== false ? substr($col, 0, $pos) : null;
            if ($colAlias !== null && $colAlias !== $alias) {
                return null; // other table
            }
            $filter = self::translateExpression($expression);
            if ($keepAlias && $colAlias === $alias) {
                // re-add the alias prefix for post-$lookup matches (log.field)
                $prefix = $alias . '.';
                $mapped = [];
                foreach ($filter as $k => $v) {
                    $mapped[str_starts_with($k, '$') || str_starts_with($k, 'or_') ? $k : $prefix . $k] = $v;
                }
                return $mapped;
            }
            return $filter;
        }
        return self::translateExpression($expression);
    }

    private static function combine(array $parts, $operator): ?array
    {
        $parts = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== []));
        if (empty($parts)) {
            return null;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        return [$operator === LogicOperator::OR ? '$or' : '$and' => $parts];
    }
}

<?php

namespace Blockbite\Orm;

use Error;
use Exception;
use WP_Error;

global $wpdb;

class BlockbiteOrm
{
    protected $table;
    protected $select = '*';
    protected $wheres = [];
    protected $limit = null;
    protected $order = '';
    protected $data = [];
    protected $lastResult = null;
    protected $withRelations = [];


    public function __construct($table = null)
    {
        if (!class_exists('wpdb')) {
            throw new Exception('WordPress database class not found.');
        }
        global $wpdb;
        // Default table is {prefix}blockbite; if a table is provided, only
        // prepend the WP prefix when it's not already present to avoid
        // double-prefixing (e.g., wp_wp_blockbite).
        if (!$table) {
            $this->table = $wpdb->prefix . 'blockbite';
        } else {
            $this->table = (strpos($table, $wpdb->prefix) === 0)
                ? $table
                : $wpdb->prefix . $table;
        }
    }

    public static function table($table = null)
    {
        return new static($table);
    }

    public function select($columns)
    {
        $this->select = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }

    /**
     * Eager-load data into the result set.
     *
     * Two modes are supported:
     * 1) Relation mode (joins by keys):
     *    [
     *      'table'       => string,        // related table
     *      'local_key'   => string,        // column on base table rows
     *      'foreign_key' => string,        // column on related table rows
     *      'type'        => 'one'|'many',  // optional, default 'one'
     *      'columns'     => array|string   // optional, default '*'
     *    ]
     *
     * 2) Query mode (independent query with where):
     *    [
     *      'table'   => string,
     *      'where'   => array,           // associative map [col=>val] OR array of [col, op, val]
     *      'type'    => 'one'|'many',    // optional, default 'many'
     *      'columns' => array|string     // optional, default '*'
     *    ]
     * In query mode, the fetched data set is attached to each base row under $relationName.
     *
     * @param string $relationName The JSON key under which related rows will be nested.
     * @param array  $config       See above for supported keys.
     * @return self
     */
    public function with(string $relationName, array $config): self
    {
        // Determine mode: relation (keys) or query (where)
        $isQueryMode = isset($config['where']) && !isset($config['local_key']) && !isset($config['foreign_key']);

        if ($isQueryMode) {
            if (!isset($config['table']) || !is_string($config['table']) || $config['table'] === '') {
                throw new Exception("with(): missing or invalid required config key 'table'");
            }
        } else {
            $required = ['table', 'local_key', 'foreign_key'];
            foreach ($required as $key) {
                if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                    throw new Exception("with(): missing or invalid required config key '$key'");
                }
            }
        }

        $type = isset($config['type']) ? strtolower($config['type']) : 'one';
        if (!in_array($type, ['one', 'many'], true)) {
            throw new Exception("with(): type must be 'one' or 'many'");
        }

        $columns = $config['columns'] ?? '*';
        if (is_array($columns)) {
            $columns = implode(', ', $columns);
        }

        $entry = [
            'name'    => $relationName,
            'table'   => $config['table'],
            'type'    => $type,
            'columns' => $columns,
        ];

        if ($isQueryMode) {
            $entry['mode'] = 'query';
            $entry['where'] = $config['where'];
        } else {
            $entry['mode'] = 'relation';
            $entry['local_key'] = $config['local_key'];
            $entry['foreign_key'] = $config['foreign_key'];
        }

        $this->withRelations[] = $entry;

        return $this;
    }

    public function where($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                // Store with explicit boolean connector for future OR support
                $this->wheres[] = [$k, '=', $v, 'AND'];
            }
        } else {
            $this->wheres[] = [$key, '=', $value, 'AND'];
        }
        return $this;
    }

    /**
     * Add an OR where condition.
     * Consecutive orWhere calls produce a linear sequence relying on SQL precedence (AND before OR).
     * Example: where(A)->orWhere(B)->where(C) => (A OR B) AND C due to precedence rules.
     * For more complex grouping, a future grouping API would be needed.
     */
    public function orWhere($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->wheres[] = [$k, '=', $v, 'OR'];
            }
        } else {
            $this->wheres[] = [$key, '=', $value, 'OR'];
        }
        return $this;
    }

    public function whereId($id)
    {
        return $this->where('id', $id);
    }

    public function whereIn($key, $values)
    {
        $this->wheres[] = [$key, 'IN', $values, 'AND'];
        return $this;
    }

    public function orderBy($column, $direction = 'ASC')
    {
        $this->order = "ORDER BY $column $direction";
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = intval($limit);
        return $this;
    }

    protected function buildWhereClause()
    {
        global $wpdb;
        $segments = [];
        $values = [];

        foreach ($this->wheres as $index => $where) {
            // Backwards compatibility: if only 3 elements, default connector AND
            $column = $where[0] ?? null;
            $operator = $where[1] ?? '=';
            $value = $where[2] ?? null;
            $boolean = strtoupper($where[3] ?? 'AND');

            if ($column === null) {
                continue; // skip malformed entry
            }

            $operatorUpper = strtoupper($operator);
            if ($operatorUpper === 'RAW') {
                // Expect $column to actually contain the raw SQL segment
                // and $value to be an array of bound values for that segment.
                $segmentSql = (string) $column;
                $segmentValues = is_array($value) ? $value : [$value];
            } elseif ($operatorUpper === 'IN' && is_array($value)) {
                $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                $segmentSql = "$column IN ($placeholders)";
                $segmentValues = $value;
            } else {
                $segmentSql = "$column $operator %s";
                $segmentValues = [$value];
            }

            // Do not prepend boolean on first segment
            if ($index === 0) {
                $segments[] = $segmentSql;
            } else {
                $segments[] = "$boolean $segmentSql";
            }
            $values = array_merge($values, $segmentValues);
        }

        $clauseString = implode(' ', $segments); // segments already include connectors/spaces

        return [
            'clause' => $clauseString,
            'values' => $values
        ];
    }

    public function get()
    {
        global $wpdb;
        $sql = "SELECT {$this->select} FROM {$this->table}";

        $where = $this->buildWhereClause();
        if (!empty($where['clause'])) {
            $sql .= " WHERE {$where['clause']}";
        }

        if ($this->order) {
            $sql .= " {$this->order}";
        }

        if ($this->limit) {
            $sql .= " LIMIT {$this->limit}";
        }

        $prepared = $wpdb->prepare($sql, ...$where['values']);
        $rows = $wpdb->get_results($prepared);
        $rows = $this->applyWithRelations($rows);
        return $rows;
    }


    public function getJson($decodeJsonFields = ['data'])
    {
        $rows = $this->get();
        $decoded = [];

        // Normalize input in case string was passed
        if (is_string($decodeJsonFields)) {
            $decodeJsonFields = [$decodeJsonFields];
        }

        foreach ($rows as $row) {
            $result = (array) $row;

            foreach ($decodeJsonFields as $field) {
                if (isset($result[$field]) && is_string($result[$field])) {
                    $decodedField = json_decode($result[$field], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $result[$field] = $decodedField;
                    }
                }
            }

            // Decode JSON fields on nested relations if present
            foreach ($this->withRelations as $rel) {
                $name = $rel['name'];
                if (array_key_exists($name, $result)) {
                    if ($rel['type'] === 'one') {
                        if (is_object($result[$name])) {
                            $nested = (array) $result[$name];
                            foreach ($decodeJsonFields as $field) {
                                if (isset($nested[$field]) && is_string($nested[$field])) {
                                    $df = json_decode($nested[$field], true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $nested[$field] = $df;
                                    }
                                }
                            }
                            $result[$name] = (object) $nested;
                        }
                    } else { // many
                        if (is_array($result[$name])) {
                            $decodedChildren = [];
                            foreach ($result[$name] as $child) {
                                $nested = (array) $child;
                                foreach ($decodeJsonFields as $field) {
                                    if (isset($nested[$field]) && is_string($nested[$field])) {
                                        $df = json_decode($nested[$field], true);
                                        if (json_last_error() === JSON_ERROR_NONE) {
                                            $nested[$field] = $df;
                                        }
                                    }
                                }
                                $decodedChildren[] = (object) $nested;
                            }
                            $result[$name] = $decodedChildren;
                        }
                    }
                }
            }

            $decoded[] = (object) $result;
        }

        return $decoded;
    }

    /*
        Convert JSON columns to string
        @param array $data
    */
    protected function normalizeJsonColumns(array $data, array $columns = ['data']): array
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $data)) {
                continue; // Don't touch it if it's not in the update payload
            }

            if (is_array($data[$column])) {
                $data[$column] = json_encode($data[$column]);
            } elseif (!is_string($data[$column]) || trim($data[$column]) === '') {
                // Set to '{}' if it's explicitly provided but empty
                $data[$column] = '{}';
            }
        }

        return $data;
    }



    public function first()
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function insert($data)
    {
        global $wpdb;


        if (!isset($data['data'])) {
            $data['data'] = json_encode([]);
        }

        $data = $this->normalizeJsonColumns($data, ['data']);
        $data = self::prepAndAddTimestamps($data);
        $inserted = $wpdb->insert($this->table, $data);

        if ($inserted === false) {
            $this->lastResult = null;
            return $this;
        }

        $id = $wpdb->insert_id;
        $this->lastResult = self::table($this->table)->where('id', $id)->first();

        return $this;
    }


    public function update($data)
    {
        global $wpdb;

        $data = $this->normalizeJsonColumns($data, ['data']);
        $data = self::prepAndAddTimestamps($data);
        $where = $this->buildWhereClause();

        if (empty($where['clause'])) {
            $this->lastResult = null;
            return $this;
        }

        $existing = $this->first();

        if (!$existing) {
            $this->lastResult = null;
            return $this;
        }

        $merged = (array) $existing;

        foreach ($data as $key => $value) {
            // Only overwrite keys that were explicitly passed
            $merged[$key] = $value;
        }

        unset($merged['id']);

        $updated = $wpdb->update($this->table, $merged, ['id' => $existing->id]);

        // Refetch and store the updated row
        $this->lastResult = $updated !== false
            ? self::table($this->table)->where('id', $existing->id)->first()
            : null;

        return $this;
    }


    public function delete()
    {
        global $wpdb;
        $where = $this->buildWhereClause();
        return $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE {$where['clause']}", ...$where['values']));
    }

    public static function deleteById($id)
    {
        global $wpdb;
        $table = (new static())->table;
        return $wpdb->delete($table, ['id' => intval($id)]);
    }





    public function upsert($data, $unique)
    {
        global $wpdb;

        $existing = self::table($this->table)->where($unique)->first();

        if ($existing) {
            self::table($this->table)->where('id', $existing->id)->update($data);
            $this->lastResult = self::table($this->table)->where('id', $existing->id)->first();
        } else {
            $inserted = array_merge($data, $unique);
            self::table($this->table)->insert($inserted);
            $id = $wpdb->insert_id;
            $this->lastResult = self::table($this->table)->where('id', $id)->first();
        }

        return $this;
    }



    public function upsertHandle($data, $handle)
    {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE handle = %s ORDER BY updated_at DESC LIMIT 1",
            $handle
        );
        $record = $wpdb->get_row($query);

        if ($record) {
            $merged = array_merge((array) $record, $data);
            unset($merged['id']);
            $wpdb->update($this->table, $merged, ['id' => $record->id]);
            $merged['id'] = $record->id;
            $this->lastResult = (object) $merged;
        } else {
            $data['handle'] = $handle;
            $this->insert($data);
            $data['id'] = $wpdb->insert_id;
            $this->lastResult = (object) $data;
        }

        return $this;
    }


    public function upsertWhere(array $data, array $where)
    {
        global $wpdb;
        $this->where($where);
        $existing = $this->first();
        if ($existing) {
            $this->where('id', $existing->id);
            $this->update($data);
        } else {
            $this->insert($data);
        }
        return $this;
    }




    public function extractJsonField(string $field, array $where = [])
    {
        global $wpdb;

        $this->where($where);
        $where_data = $this->buildWhereClause();

        $sql = "SELECT JSON_EXTRACT(data, '$." . $field . "') as extracted FROM {$this->table}";

        if (!empty($where_data['clause'])) {
            $sql .= " WHERE {$where_data['clause']}";
        }

        $prepared = $wpdb->prepare($sql, ...$where_data['values']);
        $results = $wpdb->get_results($prepared);

        $merged = [];
        foreach ($results as $row) {
            $decoded = json_decode($row->extracted, true);
            if (is_array($decoded)) {
                $merged = array_merge($merged, $decoded);
            }
        }

        return $merged;
    }

    protected static function prepAndAddTimestamps($data)
    {
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }



        return $data;
    }

    public function id()
    {
        return $this->lastResult->id ?? null;
    }



    // normalize json data columns
    public function json($decodeJsonFields = ['data'])
    {
        if (!$this->lastResult) return null;

        if (is_string($decodeJsonFields)) {
            $decodeJsonFields = [$decodeJsonFields];
        }

        $result = (array) $this->lastResult;

        foreach ($decodeJsonFields as $field) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $decoded = json_decode($result[$field], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result[$field] = $decoded;
                }
            }
        }

        // Decode nested relation JSON fields if eager-loaded
        foreach ($this->withRelations as $rel) {
            $name = $rel['name'];
            if (isset($result[$name])) {
                if ($rel['type'] === 'one') {
                    if (is_object($result[$name])) {
                        $nested = (array) $result[$name];
                        foreach ($decodeJsonFields as $field) {
                            if (isset($nested[$field]) && is_string($nested[$field])) {
                                $df = json_decode($nested[$field], true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $nested[$field] = $df;
                                }
                            }
                        }
                        $result[$name] = (object) $nested;
                    }
                } else {
                    if (is_array($result[$name])) {
                        $decodedChildren = [];
                        foreach ($result[$name] as $child) {
                            $nested = (array) $child;
                            foreach ($decodeJsonFields as $field) {
                                if (isset($nested[$field]) && is_string($nested[$field])) {
                                    $df = json_decode($nested[$field], true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $nested[$field] = $df;
                                    }
                                }
                            }
                            $decodedChildren[] = (object) $nested;
                        }
                        $result[$name] = $decodedChildren;
                    }
                }
            }
        }

        return (object) $result;
    }



    // normalize json data columns for first
    public function firstJson($decodeJsonFields = ['data'])
    {
        $row = $this->first();
        $this->lastResult = $row;

        // Normalize to array if a string is passed
        if (is_string($decodeJsonFields)) {
            $decodeJsonFields = [$decodeJsonFields];
        }

        return $this->json($decodeJsonFields);
    }


    public function success()
    {
        return !empty($this->lastResult);
    }

    /**
     * Add a JSON contains condition for a JSON column.
     * Example input: jsonPath 'data->post_type' checks JSON_EXTRACT(data, '$.post_type').
     * Uses JSON_CONTAINS, suitable when the target field is an array; for scalar matching,
     * you can pass a plain PHP scalar (e.g., 'post'); it will be JSON-encoded automatically.
     */
    public function whereJsonContains(string $jsonPath, $value, string $boolean = 'AND')
    {

  
        // Parse "column->path->nested" into column and JSON path
        $parts = explode('->', $jsonPath);
        $column = array_shift($parts);
        $path = '$.' . implode('.', $parts);

        // Build RAW SQL segment for JSON_CONTAINS(JSON_EXTRACT(...), %s)
        $segment = "JSON_CONTAINS(JSON_EXTRACT({$column}, '{$path}'), %s)";

        // Ensure value is correctly bound as valid JSON
        // - Arrays/objects: json_encode directly
        // - Scalars (e.g., 'page', 1, true): json_encode to make valid JSON ("page", 1, true)
        // - If the caller already passed a JSON-looking string, use it as-is
        $bound = null;
        if (is_array($value) || is_object($value)) {
            $bound = json_encode($value);
        } elseif (is_string($value)) {
            $trim = trim($value);
            $looksJson = $trim === 'null'
                || $trim === 'true'
                || $trim === 'false'
                || (strlen($trim) >= 2 && (
                    ($trim[0] === '"' && substr($trim, -1) === '"') ||
                    ($trim[0] === '[' && substr($trim, -1) === ']') ||
                    ($trim[0] === '{' && substr($trim, -1) === '}')
                ))
                || is_numeric($trim);

            $bound = $looksJson ? $value : json_encode($value);
        } else {
            // bool, int, float, null
            $bound = json_encode($value);
        }

        $this->wheres[] = [$segment, 'RAW', [$bound], strtoupper($boolean)];
        return $this;
    }

    /**
     * OR variant for JSON contains conditions.
     */
    public function orWhereJsonContains(string $jsonPath, $value)
    {
        return $this->whereJsonContains($jsonPath, $value, 'OR');
    }

    /**
     * Internal: apply eager-loaded relations to the base rows.
     * @param array $rows Array of stdClass rows from base query
     * @return array Rows with nested relations attached
     */
    protected function applyWithRelations(array $rows): array
    {
        if (empty($this->withRelations)) {
            return $rows;
        }

        global $wpdb;

        foreach ($this->withRelations as $rel) {
            $relationName = $rel['name'];
            $relatedTable = $rel['table'];
            $type = $rel['type'];
            $columns = $rel['columns'] ?? '*';

            // Query mode: run an independent query with provided where and attach to each row
            if (($rel['mode'] ?? 'relation') === 'query') {
                $relatedQuery = self::table($relatedTable)->select($columns);

                $conds = $rel['where'] ?? [];
                if (is_array($conds) && !empty($conds)) {
                    $isAssoc = array_keys($conds) !== range(0, count($conds) - 1);
                    if ($isAssoc) {
                        foreach ($conds as $k => $v) {
                            $relatedQuery->where($k, $v);
                        }
                    } else {
                        foreach ($conds as $c) {
                            $col = $c[0] ?? null;
                            if ($col === null) continue;
                            $op = strtoupper($c[1] ?? '=');
                            $val = $c[2] ?? null;

                            if ($op === 'IN' && is_array($val)) {
                                $relatedQuery->whereIn($col, $val);
                            } elseif (in_array($op, ['JSON_CONTAINS', 'JSONCONTAINS'], true)) {
                                $relatedQuery->whereJsonContains($col, $val);
                            } else {
                                // Fallback to equality where; operators other than '=' are not supported here
                                $relatedQuery->where($col, $val);
                            }
                        }
                    }
                }

                $relatedRows = $relatedQuery->get();
                $attach = ($type === 'one') ? ($relatedRows[0] ?? null) : $relatedRows;

                foreach ($rows as $i => $row) {
                    if (is_object($row)) {
                        $row->{$relationName} = $attach;
                    } else {
                        $row[$relationName] = $attach;
                    }
                    $rows[$i] = $row;
                }

                // Done with this relation, continue to next
                continue;
            }

            // Relation mode (default)
            $localKey = $rel['local_key'];
            $foreignKey = $rel['foreign_key'];

            // Collect local key values
            $values = [];
            foreach ($rows as $row) {
                $val = null;
                if (is_object($row) && isset($row->{$localKey})) {
                    $val = $row->{$localKey};
                } elseif (is_array($row) && isset($row[$localKey])) {
                    $val = $row[$localKey];
                }
                if ($val !== null) {
                    $values[] = $val;
                }
            }

            $values = array_values(array_unique($values));

            // If no values, attach empty structures
            if (empty($values)) {
                foreach ($rows as $i => $row) {
                    if (is_object($row)) {
                        $row->{$relationName} = ($type === 'one') ? null : [];
                    } elseif (is_array($row)) {
                        $row[$relationName] = ($type === 'one') ? null : [];
                    }
                    $rows[$i] = $row;
                }
                continue;
            }

            // Fetch related rows with one query using WHERE IN
            $placeholders = implode(', ', array_fill(0, count($values), '%s'));
            $sql = "SELECT {$columns} FROM {$relatedTable} WHERE {$foreignKey} IN ({$placeholders})";
            $prepared = $wpdb->prepare($sql, ...$values);
            $relatedRows = $wpdb->get_results($prepared);

            // Index related rows by foreign key
            $index = [];
            foreach ($relatedRows as $rr) {
                $fkVal = $rr->{$foreignKey} ?? null;
                if ($fkVal === null) continue;
                if (!isset($index[$fkVal])) {
                    $index[$fkVal] = [];
                }
                $index[$fkVal][] = $rr;
            }

            // Attach per base row
            foreach ($rows as $i => $row) {
                $lkVal = is_object($row) ? ($row->{$localKey} ?? null) : ($row[$localKey] ?? null);
                $matches = ($lkVal !== null && isset($index[$lkVal])) ? $index[$lkVal] : [];
                if ($type === 'one') {
                    $attach = empty($matches) ? null : $matches[0];
                } else {
                    $attach = $matches; // array of stdClass
                }

                if (is_object($row)) {
                    $row->{$relationName} = $attach;
                } else {
                    $row[$relationName] = $attach;
                }
                $rows[$i] = $row;
            }
        }

        return $rows;
    }
}

/*
Usage examples:

// 1) Forward relation: wp_blockbite_content -> wp_blockbite (one)
$records = Db::table('wp_blockbite_content')
    ->where(['post_id' => 14])
    ->with('blockbite', [
        'table'       => 'wp_blockbite',
        'local_key'   => 'blockbite_id',
        'foreign_key' => 'id',
        'type'        => 'one',
    ])
    ->getJson();

// 2) Reverse relation: wp_blockbite -> wp_blockbite_content (many)
$record = Db::table('wp_blockbite')
    ->where(['id' => 7])
    ->with('contents', [
        'table'       => 'wp_blockbite_content',
        'local_key'   => 'id',
        'foreign_key' => 'blockbite_id',
        'type'        => 'many',
    ])
    ->firstJson();

// 3) Self relation: wp_blockbite -> parent (one)
$record = Db::table('wp_blockbite')
    ->where(['id' => 7])
    ->with('parent', [
        'table'       => 'wp_blockbite',
        'local_key'   => 'parent_id',
        'foreign_key' => 'id',
        'type'        => 'one',
    ])
    ->firstJson();
*/

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


    public function __construct($table = null)
    {
        if (!class_exists('wpdb')) {
            throw new Exception('WordPress database class not found.');
        }
        global $wpdb;
        $this->table = $table ?: $wpdb->prefix . 'blockbite';
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

    public function where($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->wheres[] = [$k, '=', $v];
            }
        } else {
            $this->wheres[] = [$key, '=', $value];
        }
        return $this;
    }

    public function whereId($id)
    {
        return $this->where('id', $id);
    }

    public function whereIn($key, $values)
    {
        $this->wheres[] = [$key, 'IN', $values];
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
        $clause = [];
        $values = [];

        foreach ($this->wheres as $where) {
            list($column, $operator, $value) = $where;
            if (strtoupper($operator) === 'IN') {
                $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                $clause[] = "$column IN ($placeholders)";
                $values = array_merge($values, $value);
            } else {
                $clause[] = "$column $operator %s";
                $values[] = $value;
            }
        }

        return [
            'clause' => implode(' AND ', $clause),
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
        return $wpdb->get_results($prepared);
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

        if (!isset($data['data'])) {
            $data['data'] = json_encode([]);
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
}

# Blockbite ORM

A lightweight query-builder style ORM for WordPress `$wpdb`, focused on simplicity and explicitness. Supports chaining operations like `table()`, `where()`, `whereIn()`, `orderBy()`, `get()`, `getJson()`, `first()`, `firstJson()`, `insert()`, `update()`, `delete()`, and now `with()` for minimal eager-loading.

## Where / OrWhere

You can chain `where()` and `orWhere()` conditions. Consecutive `orWhere()` calls rely on SQL precedence (AND before OR). For complex groupings, use multiple calls intentionally.

```php
// Basic chaining: (A OR B) AND C
$rows = Db::table('wp_blockbite')
    ->where(['type' => 'layout'])       // A
    ->orWhere(['type' => 'module'])     // B
    ->where(['status' => 'published'])  // C
    ->get();

// Multiple ORs followed by AND
$rows = Db::table('wp_blockbite')
    ->orWhere('handle', 'home')
    ->orWhere('handle', 'blog')
    ->where('tenant_id', 42)
    ->orderBy('updated_at', 'DESC')
    ->get();

// Mixed array and scalar forms
$rows = Db::table('wp_blockbite_content')
    ->where(['post_id' => 14])
    ->orWhere('post_id', 15)
    ->whereIn('blockbite_id', [7, 9, 11])
    ->get();
```

## Eager-loading with `with()` (read-only)

The `with()` method allows you to nest related rows into the returned data without introducing full model relationships or any implicit write behavior. It performs one additional `SELECT ... WHERE IN (...)` per relation and merges the results under the provided relation name.

### API

```php
public function with(string $relationName, array $config): self
```

- `relationName`: The JSON key under which the related data will be nested.
- `config`:
  - `table` (string): Related table name.
  - `local_key` (string): Column on the base table rows.
  - `foreign_key` (string): Column on the related table rows.
  - `type` ('one'|'many', optional): Defaults to `'one'`.
  - `columns` (array|string, optional): Columns to select; defaults to `'*'`.

### Behavior

- One-to-one attaches a single object or `null` under `relationName`.
- One-to-many attaches an array (possibly empty) under `relationName`.
- Supports relations to the same table and to other tables.
- Read-only: no relational updates are performed.
- Works with `get()`, `getJson()`, `first()`, `firstJson()`.

### Examples

#### Forward relation (content -> blockbite, type one)
```php
$records = Db::table('wp_blockbite_content')
    ->where(['post_id' => 14])
    ->with('blockbite', [
        'table'       => 'wp_blockbite',
        'local_key'   => 'blockbite_id',
        'foreign_key' => 'id',
        'type'        => 'one',
    ])
    ->getJson();
```

#### Reverse relation (blockbite -> contents, type many)
```php
$record = Db::table('wp_blockbite')
    ->where(['id' => 7])
    ->with('contents', [
        'table'       => 'wp_blockbite_content',
        'local_key'   => 'id',
        'foreign_key' => 'blockbite_id',
        'type'        => 'many',
    ])
    ->firstJson();
```

#### Self relation (wp_blockbite -> parent, type one)
```php
$record = Db::table('wp_blockbite')
    ->where(['id' => 7])
    ->with('parent', [
        'table'       => 'wp_blockbite',
        'local_key'   => 'parent_id',
        'foreign_key' => 'id',
        'type'        => 'one',
    ])
    ->firstJson();
```

### Notes
- Eager-loading runs one extra query per relation using `WHERE IN` over all collected local key values.
- Related rows are nested; related columns are not flattened into the base row.
- Updates remain explicit per table (e.g., `Db::table('wp_blockbite_content')->where(['blockbite_id' => 7])->update([...]);`).

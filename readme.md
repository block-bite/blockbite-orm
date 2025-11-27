# 🧩 Blockbite ORM

**Blockbite ORM** is a lightweight, WordPress-aware query builder and ORM designed specifically for working with custom database tables in WordPress plugins. It brings expressive, chainable syntax and modern PHP features to your data layer — without heavy dependencies.

---

## ✨ Features

- 🔄 Upsert support (`updateOrCreate`, `upsertHandle`)
- 🔍 Fluent query builder: `where`, `whereIn`, `orderBy`, `limit`
- 🔐 Safe SQL with `wpdb->prepare`
- 📦 Minimal, zero-dependency
- 🧠 Auto-handles JSON `data` columns
- 🕒 Auto-adds `updated_at` timestamp
- 💾 Designed for a `blockbite` table, but works with any

---

## 🛠️ Installation

```bash
composer require blockbite/orm
```

# 🚀 Usage

## Basic Query

```
use Blockbite\ORM\BlockbiteOrm;

$records = BlockbiteOrm::table()
    ->where('handle', 'bites')
    ->get();
```

with json decoded data column

```
 $records = Db::table()
        ->where(['handle' => $handle])
        ->getJson();
```

## Get First Record

```
$item = BlockbiteOrm::table()
    ->where('slug', 'hero-block')
    ->first();
```

## Upsert

```
BlockbiteOrm::table()->upsert(
    ['content' => 'Hello world'],
    ['slug' => 'hero', 'handle' => 'bites']
);
```

## Upsert by Handle (latest updated)

```
BlockbiteOrm::table()->upsertHandle(
    ['data' => json_encode($someArray)],
    'dynamic-content'
);
```

## Select Specific Column

```
BlockbiteOrm::table()
    ->select(['id', 'slug', 'title'])
    ->where('handle', 'bites')
    ->get();
```

## Delete Record

```
BlockbiteOrm::table()->where('id', 42)->delete();
```

or by a static method

```
BlockbiteOrm::deleteById(42);
```

## 🔍 JSON Field Query

Extract and merge a JSON subkey from the **data** column:

```
$utils = BlockbiteOrm::table()->extractJsonField('utils', [
    'handle' => 'bites'
]);
```

## 🔗 Chain Multiple Conditions

```
$latest = BlockbiteOrm::table()
    ->where('handle', 'bites')
    ->where('parent', 'footer')
    ->orderBy('updated_at', 'DESC')
    ->limit(5)
    ->get();
```



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



## Update Results

```
$result = BlockbiteOrm::table('my_table')->upsert($data, ['slug' => 'home']);

// Get the ID
$id = $result->id();

// Get all fields as array
$data = $result->json();

// Check if anything actually happened
if ($result->success()) {
    // Success logic here
}
```

## First Json

Decodes the data field by default

```
$dynamicContent = Db::table()
    ->where(['slug' => $slug, 'handle' => 'dynamic_content'])
    ->firstJson();

$dynamicDesign = Db::table()
    ->where(['slug' => $designId, 'handle' => 'dynamic_design'])
    ->firstJson();
```

or if the json column is a different column(s)

```
$meta = Db::table('settings')->whereId(1)->firstJson(['meta', 'payload']);
```


# Blockbite ORM

A lightweight query-builder style ORM for WordPress `$wpdb`, focused on simplicity and explicitness. Supports chaining operations like `table()`, `where()`, `whereIn()`, `orderBy()`, `get()`, `getJson()`, `first()`, `firstJson()`, `insert()`, `update()`, `delete()`, and now `with()` for minimal eager-loading.

# Blockbite ORM — Eager Loading with `with()`

This lightweight query-builder supports read-only eager loading of related rows via `with(relationName, config)`. It nests related data under the provided relation name without introducing relational writes.

Key properties:
- one-to-one and one-to-many
- forward and reverse relations
- self-relations (same table)
- read-only loading only (no automatic updates)

## API

`with(string $relationName, array $config)` where `config` includes:
- `table`: related table name
- `local_key`: column on the base table rows
- `foreign_key`: column on the related table rows
- `type`: `'one' | 'many'` (optional, defaults to `'one'`)
- `columns`: array|string of columns to select (optional, defaults to `*`)

Eager loading occurs after the base query in `get()/first()` and also works with `getJson()/firstJson()` to decode JSON fields on nested relations.

## Examples

### Forward relation: `wp_blockbite_content` → `wp_blockbite` (one)

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

Conceptual JSON:

```json
[
    {
        "id": 22,
        "post_id": 14,
        "blockbite_id": 7,
        "content": "metadata",
        "blockbite": {
            "id": 7,
            "handle": "example",
            "type": "layout"
        }
    }
]
```

### Reverse relation: `wp_blockbite` → `wp_blockbite_content` (many)

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

Conceptual JSON:

```json
{
    "id": 7,
    "handle": "example",
    "type": "layout",
    "contents": [
        {
            "id": 22,
            "post_id": 14,
            "blockbite_id": 7,
            "content": "First meta block"
        },
        {
            "id": 23,
            "post_id": 15,
            "blockbite_id": 7,
            "content": "Second meta block"
        }
    ]
}
```

### Self relation: `wp_blockbite` → parent (one)

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

## Notes
- `with()` is read-only: it only adds additional `SELECT`s and nests results.
- Updates remain explicit per table via `update()`; no relational writes are inferred.
- Queries without `with()` behave exactly as before.
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


## Search Json

```
$dynamic_content = Db::table()
        ->where('handle', 'dynamic-content')
        ->whereJsonContains('data->post_type', $post_type)
        ->getJson();
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

### Explicit per-table update example

```php
Db::table('wp_blockbite_content')
    ->where(['blockbite_id' => 7])
    ->update([
        'content' => 'New content value'
    ]);
```

## 📦 Recommended Table Schema

```
CREATE TABLE wp_blockbite (
  id INT AUTO_INCREMENT PRIMARY KEY,
  handle VARCHAR(255),
  slug VARCHAR(255),
  content LONGTEXT,
  data JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```



### ✅ Requirements

- PHP 7.4+
- WordPress (using $wpdb)

## 🧠 License

MIT — open and free.



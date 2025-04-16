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

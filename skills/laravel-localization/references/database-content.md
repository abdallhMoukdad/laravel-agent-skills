# Translating Database Content

User-generated and admin-curated content (product names, article bodies, CMS pages) belongs in the database, not in `lang/` files. Two storage strategies dominate: JSON columns via `spatie/laravel-translatable`, or a separate translation table.

---

## Approach 1 — JSON Column with `spatie/laravel-translatable`

This package is on Packagist as `spatie/laravel-translatable`. Internals live under the `Spatie\Translatable\` namespace, so symbol verifiers should treat it as third-party.

```bash
composer require spatie/laravel-translatable
```

### Migration

The translatable column must be `json` (or `text` on databases without JSON support).

```php
Schema::create('products', function (Blueprint $table): void {
    $table->id();
    $table->json('name');
    $table->json('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->timestamps();
});
```

### Model

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['name', 'description', 'price'];
}
```

### Reading and Writing

```php
$product = Product::create([
    'name'        => ['en' => 'Phone', 'ar' => 'هاتف'],
    'description' => ['en' => 'A great phone', 'ar' => 'هاتف رائع'],
    'price'       => 499.00,
]);

$product->name;                                     // current app locale
$product->getTranslation('name', 'en');             // explicit
$product->getTranslations('name');                  // ['en' => '...', 'ar' => '...']

$product->setTranslation('name', 'ar', 'هاتف ذكي')->save();
$product->setTranslations('description', [
    'en' => 'A smart phone',
    'ar' => 'هاتف ذكي',
])->save();

$product->forgetTranslation('name', 'ar');
$product->save();
```

### Default Locale Fallback

The package's default behaviour: when the requested locale has no translation, it returns the value for `config('app.fallback_locale')`. Disable with `useFallbackLocale = false` on the model when the missing-translation case must be visible (admin dashboards).

---

## Querying Translatable JSON Columns

JSON path syntax works directly in `where()`:

```php
Product::where('name->ar', 'هاتف')->first();
Product::where('name->en', 'like', "%{$search}%")->get();
```

For cross-locale search ("find a product whose name in *any* locale matches"), fan out across the supported locales:

```php
$query = Product::query();
foreach (config('app.locales') as $locale) {
    $query->orWhere("name->{$locale}", 'like', "%{$search}%");
}
$results = $query->get();
```

Postgres and MySQL 8 both support this syntax. SQLite supports it from version 3.38 with the `JSON1` extension.

---

## Indexing JSON Translations

Indexing a whole JSON column rarely helps — most queries target a single locale path. Use generated columns instead:

```php
// Migration
DB::statement("
    ALTER TABLE products
    ADD COLUMN name_ar VARCHAR(255)
        GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar'))) STORED,
    ADD INDEX products_name_ar_idx (name_ar)
");
```

For Postgres, use a functional index:

```php
DB::statement("CREATE INDEX products_name_ar_idx ON products ((name->>'ar'))");
```

Generate one index per locale that participates in search, not a single index covering all locales.

---

## Translatable Slugs

For SEO-friendly multilingual URLs, the companion package `spatie/laravel-sluggable` ships `HasTranslatableSlug`.

```php
use Spatie\Sluggable\HasTranslatableSlug;
use Spatie\Sluggable\SlugOptions;

class Article extends Model
{
    use HasTranslations;
    use HasTranslatableSlug;

    public array $translatable = ['title', 'slug'];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug');
    }
}
```

Now `/articles/phone-review` and `/articles/مراجعة-الهاتف` both resolve to the same model.

---

## Approach 2 — Separate Translation Table

Choose this over JSON when datasets are large, when full-text search per locale matters, or when translations have their own lifecycle (review state, translator authorship, audit history).

```php
Schema::create('product_translations', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('product_id')->constrained()->cascadeOnDelete();
    $table->string('locale', 8);
    $table->string('name');
    $table->text('description')->nullable();
    $table->timestamps();

    $table->unique(['product_id', 'locale']);
    $table->index('locale');
});
```

```php
class Product extends Model
{
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function translation(?string $locale = null): HasOne
    {
        return $this->hasOne(ProductTranslation::class)
            ->where('locale', $locale ?? app()->getLocale());
    }
}
```

Eager-load to avoid N+1:

```php
Product::with(['translation' => fn ($q) => $q->where('locale', app()->getLocale())])->get();
```

### Trade-offs

| Concern                     | JSON column                          | Separate table                       |
|-----------------------------|--------------------------------------|--------------------------------------|
| Schema simplicity           | One column, one row per record       | One row per (record, locale) pair    |
| Search per locale           | Generated column or JSON index       | Native index, full-text supported    |
| Adding a new locale         | Free (no schema change)              | Free (no schema change)              |
| Deleting all of one locale  | Update every row's JSON              | Single `DELETE WHERE locale = ?`     |
| Eager-load N+1 risk         | None (single column)                 | Requires explicit eager-load         |
| Tooling for translators     | Custom UI required                   | Maps cleanly to translation services |

Default to JSON until search performance or translator tooling forces the separate-table choice.

---

## When Not To Use Either

A small fixed set of strings ("status: draft / sent / paid") belongs in `lang/` files, not the database. Storing it in the database forces a query for every request and prevents translators from working from the same workflow as the rest of the UI strings.

The dividing line: if the values are added by your team in code, use `lang/`. If they're added by users or admins through a UI, use the database.

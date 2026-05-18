# Registration Wizard + Website Auto-Indexing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert single-page registration into a 3-step wizard that optionally captures a website URL, then auto-indexes the entire site via a polite sitemap-first crawler with budget gating and daily diff-only refresh.

**Architecture:** Frontend wizard collects fields including optional `website_url`. `RegisterController` validates format-only and dispatches `CrawlWebsiteJob` (queue=`crawls`, Spatie `NotTenantAware`). `SiteCrawler` orchestrates: `SitemapDiscoverer` → `RobotsTxtPolicy` → per-URL loop gated by `UsageTracker::canRecordUsage`, normalized via `UrlNormalizer`, diff-skipped via Last-Modified/ETag/content-hash, upserted as `KnowledgeItem` (type=webpage) keyed on a new top-level `url_normalized` indexed column. Daily `crawls:refresh-all` command rescheduled by `routes/console.php`. KB UI gets a `crawl_session_id` filter and the destroy flow inserts into `crawl_url_blocklist`.

**Tech Stack:** Laravel 13, PHP 8.3, Vue 3 (Composition API + Inertia), Spatie Multitenancy, Redis queue (`crawls`), PHPUnit (class-style), Tailwind v4, shadcn/ui.

**Spec:** `docs/superpowers/specs/2026-05-18-registration-wizard-and-site-scraping-design.md` (commits `852414a`, `59a4255`, `67750a9`).

---

## Branch + Worktree

- [ ] **Step 0: Create feature branch**

```bash
git checkout -b feat/registration-wizard-site-scraping
```

---

## Task 1: Database — tenants website columns

**Files:**
- Create: `database/migrations/2026_05_18_000001_add_website_url_and_auto_recrawl_to_tenants_table.php`
- Modify: `app/Models/Tenant.php`
- Test: `tests/Feature/Migrations/AddWebsiteColumnsToTenantsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddWebsiteColumnsToTenantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenants_table_has_website_url_and_auto_recrawl_columns(): void
    {
        $tenant = Tenant::factory()->create([
            'website_url' => 'https://example.com',
            'auto_recrawl' => false,
        ]);

        $this->assertSame('https://example.com', $tenant->fresh()->website_url);
        $this->assertFalse($tenant->fresh()->auto_recrawl);
    }

    public function test_website_url_is_nullable_and_auto_recrawl_defaults_true(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($tenant->website_url);
        $this->assertTrue($tenant->auto_recrawl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=AddWebsiteColumnsToTenantsTest`

Expected: FAIL — "Unknown column 'website_url'" or "Mass assignment of website_url".

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('website_url', 2048)->nullable()->after('domain');
            $table->boolean('auto_recrawl')->default(true)->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['website_url', 'auto_recrawl']);
        });
    }
};
```

- [ ] **Step 4: Add columns to Tenant fillable + casts**

In `app/Models/Tenant.php`, add to `$fillable`:

```php
'website_url',
'auto_recrawl',
```

In the `casts()` method, add:

```php
'auto_recrawl' => 'boolean',
```

- [ ] **Step 5: Run test to verify it passes**

`php artisan test --filter=AddWebsiteColumnsToTenantsTest`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_18_000001_*.php app/Models/Tenant.php tests/Feature/Migrations/AddWebsiteColumnsToTenantsTest.php
git commit -m "feat(crawler): add website_url + auto_recrawl columns to tenants"
```

---

## Task 2: Database — crawl_sessions + enums + model

**Files:**
- Create: `database/migrations/2026_05_18_000002_create_crawl_sessions_table.php`
- Create: `app/Enums/CrawlSessionStatus.php`
- Create: `app/Enums/CrawlMode.php`
- Create: `app/Models/CrawlSession.php`
- Create: `database/factories/CrawlSessionFactory.php`
- Test: `tests/Unit/Models/CrawlSessionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $session = CrawlSession::factory()->forTenant($tenant)->create();

        $this->assertSame($tenant->id, $session->tenant_id);
    }

    public function test_status_and_mode_are_enum_casts(): void
    {
        $session = CrawlSession::factory()->create([
            'status' => CrawlSessionStatus::Running,
            'mode' => CrawlMode::Initial,
        ]);

        $this->assertSame(CrawlSessionStatus::Running, $session->fresh()->status);
        $this->assertSame(CrawlMode::Initial, $session->fresh()->mode);
    }

    public function test_counts_default_to_zero(): void
    {
        $session = CrawlSession::factory()->create();

        $this->assertSame(0, $session->pages_discovered);
        $this->assertSame(0, $session->pages_indexed);
        $this->assertSame(0, $session->pages_failed);
        $this->assertSame(0, $session->pages_skipped_budget);
        $this->assertSame(0, $session->pages_skipped_unchanged);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=CrawlSessionTest`

Expected: FAIL — class CrawlSession not found.

- [ ] **Step 3: Create the enums**

`app/Enums/CrawlSessionStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlSessionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
```

`app/Enums/CrawlMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlMode: string
{
    case Initial = 'initial';
    case Refresh = 'refresh';
    case Manual = 'manual';
}
```

- [ ] **Step 4: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('pages_discovered')->default(0);
            $table->unsignedInteger('pages_indexed')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->unsignedInteger('pages_skipped_budget')->default(0);
            $table->unsignedInteger('pages_skipped_unchanged')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_sessions');
    }
};
```

- [ ] **Step 5: Create the model**

`app/Models/CrawlSession.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CrawlSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property CrawlSessionStatus $status
 * @property CrawlMode $mode
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class CrawlSession extends Model
{
    /** @use HasFactory<CrawlSessionFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'mode',
        'status',
        'started_at',
        'completed_at',
        'pages_discovered',
        'pages_indexed',
        'pages_failed',
        'pages_skipped_budget',
        'pages_skipped_unchanged',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CrawlSessionStatus::class,
            'mode' => CrawlMode::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'pages_discovered' => 'integer',
            'pages_indexed' => 'integer',
            'pages_failed' => 'integer',
            'pages_skipped_budget' => 'integer',
            'pages_skipped_unchanged' => 'integer',
        ];
    }
}
```

- [ ] **Step 6: Create the factory**

`database/factories/CrawlSessionFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlSession>
 */
class CrawlSessionFactory extends Factory
{
    protected $model = CrawlSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Queued,
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

`php artisan test --filter=CrawlSessionTest`

Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_05_18_000002_*.php app/Enums/Crawl*.php app/Models/CrawlSession.php database/factories/CrawlSessionFactory.php tests/Unit/Models/CrawlSessionTest.php
git commit -m "feat(crawler): add CrawlSession model + status/mode enums"
```

---

## Task 3: Database — crawl_url_blocklist + model

**Files:**
- Create: `database/migrations/2026_05_18_000003_create_crawl_url_blocklist_table.php`
- Create: `app/Models/CrawlUrlBlocklist.php`
- Create: `database/factories/CrawlUrlBlocklistFactory.php`
- Test: `tests/Unit/Models/CrawlUrlBlocklistTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CrawlUrlBlocklist;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlUrlBlocklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $row = CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_tenant_url_pair_is_unique(): void
    {
        $tenant = Tenant::factory()->create();
        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);

        $this->expectException(QueryException::class);

        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=CrawlUrlBlocklistTest`

Expected: FAIL — model not found.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_url_blocklist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url_normalized', 2048);
            $table->timestamp('excluded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'url_normalized'], 'cubl_tenant_url_unique');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_url_blocklist');
    }
};
```

Note: MySQL caps unique-index key length on `VARCHAR(2048) utf8mb4` (4 bytes/char). To stay under the 3072-byte limit on a single-column unique with `tenant_id`, the unique index uses the URL as-is in MySQL 8.0+ (its default `innodb_large_prefix` allows 3072 bytes). If migration fails on a constrained MySQL version, switch to `$table->string('url_normalized', 768)` — 768×4 = 3072 bytes fits.

- [ ] **Step 4: Create the model**

`app/Models/CrawlUrlBlocklist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CrawlUrlBlocklistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $excluded_at
 */
class CrawlUrlBlocklist extends Model
{
    /** @use HasFactory<CrawlUrlBlocklistFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'crawl_url_blocklist';

    protected $fillable = [
        'tenant_id',
        'url_normalized',
        'excluded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Create the factory**

`database/factories/CrawlUrlBlocklistFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CrawlUrlBlocklist;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlUrlBlocklist>
 */
class CrawlUrlBlocklistFactory extends Factory
{
    protected $model = CrawlUrlBlocklist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'url_normalized' => 'https://example.com/some-page',
            'excluded_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

`php artisan test --filter=CrawlUrlBlocklistTest`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_18_000003_*.php app/Models/CrawlUrlBlocklist.php database/factories/CrawlUrlBlocklistFactory.php tests/Unit/Models/CrawlUrlBlocklistTest.php
git commit -m "feat(crawler): add crawl_url_blocklist table + model"
```

---

## Task 4: Database — knowledge_items.url_normalized column + index

**Files:**
- Create: `database/migrations/2026_05_18_000004_add_crawl_columns_to_knowledge_items_table.php`
- Modify: `app/Models/KnowledgeItem.php` (add `url_normalized` to fillable)
- Create: `database/factories/KnowledgeItemFactory.php` (new — referenced by later crawler tests)
- Test: `tests/Feature/Migrations/AddCrawlColumnsToKnowledgeItemsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddCrawlColumnsToKnowledgeItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_normalized_column_exists_and_is_writable(): void
    {
        $tenant = Tenant::factory()->create();
        $item = KnowledgeItem::factory()->forTenant($tenant)->create([
            'type' => 'webpage',
            'url_normalized' => 'https://example.com/about',
        ]);

        $this->assertSame('https://example.com/about', $item->fresh()->url_normalized);
    }

    public function test_composite_index_exists(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            $this->markTestSkipped('Index introspection assertion targets MySQL only.');
        }

        $indexes = DB::select('SHOW INDEXES FROM knowledge_items WHERE Key_name = ?', ['kn_items_tenant_type_norm_idx']);
        $this->assertNotEmpty($indexes);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=AddCrawlColumnsToKnowledgeItemsTest`

Expected: FAIL — `url_normalized` column unknown / factory missing.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('url_normalized', 2048)->nullable()->after('source_url');
            $table->index(['tenant_id', 'type', 'url_normalized'], 'kn_items_tenant_type_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropIndex('kn_items_tenant_type_norm_idx');
            $table->dropColumn('url_normalized');
        });
    }
};
```

If the composite index fails on long-key constraints, shorten to `->string('url_normalized', 768)`.

- [ ] **Step 4: Add `url_normalized` to KnowledgeItem fillable**

In `app/Models/KnowledgeItem.php`, add `'url_normalized'` to the `$fillable` array (alphabetically near `source_url`).

- [ ] **Step 5: Create KnowledgeItemFactory**

`database/factories/KnowledgeItemFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KnowledgeItemStatus;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeItem>
 */
class KnowledgeItemFactory extends Factory
{
    protected $model = KnowledgeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(4),
            'type' => 'text',
            'content' => fake()->paragraph(),
            'status' => KnowledgeItemStatus::Ready,
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function webpage(string $url, string $normalized): self
    {
        return $this->state([
            'type' => 'webpage',
            'source_url' => $url,
            'url_normalized' => $normalized,
        ]);
    }
}
```

- [ ] **Step 6: Wire the factory to the model**

In `app/Models/KnowledgeItem.php`, add `HasFactory`:

```php
use Database\Factories\KnowledgeItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// ...
class KnowledgeItem extends Model
{
    /** @use HasFactory<KnowledgeItemFactory> */
    use BelongsToTenant, BustsTenantUsageCache, HasFactory;
```

- [ ] **Step 7: Run test to verify it passes**

`php artisan test --filter=AddCrawlColumnsToKnowledgeItemsTest`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_05_18_000004_*.php app/Models/KnowledgeItem.php database/factories/KnowledgeItemFactory.php tests/Feature/Migrations/AddCrawlColumnsToKnowledgeItemsTest.php
git commit -m "feat(crawler): add url_normalized column + composite index to knowledge_items"
```

---

## Task 5: UrlNormalizer service

**Files:**
- Create: `app/Services/Crawler/UrlNormalizer.php`
- Test: `tests/Unit/Services/Crawler/UrlNormalizerTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Crawler/UrlNormalizerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UrlNormalizer;
    }

    public function test_lowercases_host(): void
    {
        $this->assertSame(
            'https://example.com/About',
            $this->normalizer->normalize('https://EXAMPLE.com/About'),
        );
    }

    public function test_strips_fragment(): void
    {
        $this->assertSame(
            'https://example.com/page',
            $this->normalizer->normalize('https://example.com/page#section-1'),
        );
    }

    public function test_strips_tracking_params(): void
    {
        $url = 'https://example.com/page?utm_source=foo&utm_medium=bar&fbclid=123&gclid=abc&ref=test&_ga=GA1&mc_eid=eid&mc_cid=cid';
        $this->assertSame(
            'https://example.com/page',
            $this->normalizer->normalize($url),
        );
    }

    public function test_keeps_non_tracking_params(): void
    {
        $this->assertSame(
            'https://example.com/page?id=42',
            $this->normalizer->normalize('https://example.com/page?utm_source=foo&id=42'),
        );
    }

    public function test_collapses_trailing_slash_on_root(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('https://example.com/'));
    }

    public function test_keeps_trailing_slash_elsewhere(): void
    {
        $this->assertSame('https://example.com/about/', $this->normalizer->normalize('https://example.com/about/'));
    }

    public function test_removes_default_ports(): void
    {
        $this->assertSame('https://example.com/path', $this->normalizer->normalize('https://example.com:443/path'));
        $this->assertSame('http://example.com/path', $this->normalizer->normalize('http://example.com:80/path'));
    }

    public function test_keeps_non_default_ports(): void
    {
        $this->assertSame('https://example.com:8443/path', $this->normalizer->normalize('https://example.com:8443/path'));
    }

    public function test_sorts_query_string_params(): void
    {
        $this->assertSame(
            'https://example.com/page?a=1&b=2&c=3',
            $this->normalizer->normalize('https://example.com/page?c=3&a=1&b=2'),
        );
    }

    public function test_normalizes_www_prefix_for_host_comparison(): void
    {
        // www-strip applies only when checking host equality, NOT in the normalized URL.
        // The normalized URL preserves www so we don't collapse two distinct sites.
        $this->assertSame('https://www.example.com/x', $this->normalizer->normalize('https://www.example.com/x'));
        $this->assertSame('https://example.com/x', $this->normalizer->normalize('https://example.com/x'));
    }

    public function test_handles_invalid_input(): void
    {
        $this->assertSame('not-a-url', $this->normalizer->normalize('not-a-url'));
        $this->assertSame('', $this->normalizer->normalize(''));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=UrlNormalizerTest`

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

`app/Services/Crawler/UrlNormalizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

class UrlNormalizer
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'ref', '_ga', 'mc_eid', 'mc_cid',
    ];

    public function normalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($port !== null && ! self::isDefaultPort($scheme, (int) $port)) {
            $host .= ':'.$port;
        }

        if ($path === '/' || $path === '') {
            $path = '';
        }

        $queryString = '';
        if ($query !== '') {
            parse_str($query, $params);
            $filtered = array_filter(
                $params,
                fn (string $key) => ! in_array($key, self::TRACKING_PARAMS, true),
                ARRAY_FILTER_USE_KEY,
            );
            ksort($filtered);
            if ($filtered !== []) {
                $queryString = '?'.http_build_query($filtered);
            }
        }

        return "{$scheme}://{$host}{$path}{$queryString}";
    }

    /**
     * Returns true if $a and $b refer to the same host (case-insensitive, www-stripped).
     * Used by the crawler to filter same-host links.
     */
    public function sameHost(string $a, string $b): bool
    {
        return self::canonicalHost($a) === self::canonicalHost($b);
    }

    private static function canonicalHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

`php artisan test --filter=UrlNormalizerTest`

Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Crawler/UrlNormalizer.php tests/Unit/Services/Crawler/UrlNormalizerTest.php
git commit -m "feat(crawler): add UrlNormalizer service"
```

---

## Task 6: RobotsTxtPolicy + RobotsPolicy DTO

**Files:**
- Create: `app/Services/Crawler/RobotsPolicy.php` (DTO)
- Create: `app/Services/Crawler/RobotsTxtPolicy.php` (fetcher/parser)
- Test: `tests/Unit/Services/Crawler/RobotsTxtPolicyTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Crawler/RobotsTxtPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\RobotsTxtPolicy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotsTxtPolicyTest extends TestCase
{
    public function test_missing_robots_returns_permissive_policy(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('Not found', 404),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertTrue($policy->isAllowed('https://example.com/anything'));
        $this->assertSame(1, $policy->crawlDelaySeconds());
    }

    public function test_disallow_for_our_user_agent(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nDisallow: /admin\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertFalse($policy->isAllowed('https://example.com/admin'));
        $this->assertFalse($policy->isAllowed('https://example.com/admin/users'));
        $this->assertTrue($policy->isAllowed('https://example.com/about'));
    }

    public function test_specific_user_agent_overrides_wildcard(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /\n\nUser-agent: ChatbotIndexer\nAllow: /public\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertTrue($policy->isAllowed('https://example.com/public/page'));
        $this->assertFalse($policy->isAllowed('https://example.com/private'));
    }

    public function test_crawl_delay_parsed(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nCrawl-delay: 5\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertSame(5, $policy->crawlDelaySeconds());
    }

    public function test_returns_sitemap_urls(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "Sitemap: https://example.com/sitemap.xml\nSitemap: https://example.com/news.xml\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://example.com/news.xml'],
            $policy->sitemapUrls(),
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=RobotsTxtPolicyTest`

Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the DTO**

`app/Services/Crawler/RobotsPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

final class RobotsPolicy
{
    /**
     * @param  list<string>  $disallowPaths
     * @param  list<string>  $allowPaths
     * @param  list<string>  $sitemapUrls
     */
    public function __construct(
        private readonly array $disallowPaths,
        private readonly array $allowPaths,
        private readonly int $crawlDelaySeconds,
        private readonly array $sitemapUrls,
    ) {}

    public function isAllowed(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $longestMatch = '';
        $allowed = true;

        foreach ($this->disallowPaths as $disallow) {
            if ($disallow !== '' && str_starts_with($path, $disallow) && strlen($disallow) > strlen($longestMatch)) {
                $longestMatch = $disallow;
                $allowed = false;
            }
        }

        foreach ($this->allowPaths as $allow) {
            if ($allow !== '' && str_starts_with($path, $allow) && strlen($allow) > strlen($longestMatch)) {
                $longestMatch = $allow;
                $allowed = true;
            }
        }

        return $allowed;
    }

    public function crawlDelaySeconds(): int
    {
        return $this->crawlDelaySeconds;
    }

    /** @return list<string> */
    public function sitemapUrls(): array
    {
        return $this->sitemapUrls;
    }
}
```

- [ ] **Step 4: Implement the fetcher/parser**

`app/Services/Crawler/RobotsTxtPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RobotsTxtPolicy
{
    private const USER_AGENT = 'ChatbotIndexer';

    private const DEFAULT_CRAWL_DELAY_SECONDS = 1;

    public function fetchFor(string $rootUrl): RobotsPolicy
    {
        $host = parse_url($rootUrl, PHP_URL_SCHEME).'://'.parse_url($rootUrl, PHP_URL_HOST);

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT.'/1.0'])
                ->get($host.'/robots.txt');

            if (! $response->successful()) {
                return $this->permissivePolicy();
            }

            return $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::debug('[RobotsTxt] (IS $) Fetch failed; using permissive policy', [
                'root' => $rootUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->permissivePolicy();
        }
    }

    private function permissivePolicy(): RobotsPolicy
    {
        return new RobotsPolicy(
            disallowPaths: [],
            allowPaths: [],
            crawlDelaySeconds: self::DEFAULT_CRAWL_DELAY_SECONDS,
            sitemapUrls: [],
        );
    }

    private function parse(string $body): RobotsPolicy
    {
        $lines = preg_split('/\r\n|\r|\n/', $body);

        $specificDisallow = [];
        $specificAllow = [];
        $specificDelay = null;

        $wildcardDisallow = [];
        $wildcardAllow = [];
        $wildcardDelay = null;

        $sitemaps = [];

        $currentAgent = null;

        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/#.*$/', '', $rawLine));
            if ($line === '') {
                continue;
            }

            [$directive, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $directive = strtolower($directive);

            if ($directive === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;

                continue;
            }

            if ($directive === 'user-agent') {
                $currentAgent = strtolower($value);

                continue;
            }

            $isSpecific = $currentAgent === strtolower(self::USER_AGENT);
            $isWildcard = $currentAgent === '*';

            if (! $isSpecific && ! $isWildcard) {
                continue;
            }

            switch ($directive) {
                case 'disallow':
                    if ($value !== '') {
                        $isSpecific ? $specificDisallow[] = $value : $wildcardDisallow[] = $value;
                    }
                    break;
                case 'allow':
                    if ($value !== '') {
                        $isSpecific ? $specificAllow[] = $value : $wildcardAllow[] = $value;
                    }
                    break;
                case 'crawl-delay':
                    $parsed = (int) $value;
                    if ($parsed > 0) {
                        $isSpecific ? $specificDelay = $parsed : $wildcardDelay = $parsed;
                    }
                    break;
            }
        }

        $disallow = $specificDisallow !== [] || $specificAllow !== [] ? $specificDisallow : $wildcardDisallow;
        $allow = $specificDisallow !== [] || $specificAllow !== [] ? $specificAllow : $wildcardAllow;
        $delay = $specificDelay ?? $wildcardDelay ?? self::DEFAULT_CRAWL_DELAY_SECONDS;

        return new RobotsPolicy(
            disallowPaths: $disallow,
            allowPaths: $allow,
            crawlDelaySeconds: $delay,
            sitemapUrls: $sitemaps,
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

`php artisan test --filter=RobotsTxtPolicyTest`

Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Crawler/RobotsPolicy.php app/Services/Crawler/RobotsTxtPolicy.php tests/Unit/Services/Crawler/RobotsTxtPolicyTest.php
git commit -m "feat(crawler): add RobotsTxtPolicy + RobotsPolicy DTO"
```

---

## Task 7: SitemapDiscoverer

**Files:**
- Create: `app/Services/Crawler/SitemapDiscoverer.php`
- Test: `tests/Unit/Services/Crawler/SitemapDiscovererTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Crawler/SitemapDiscovererTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\RobotsTxtPolicy;
use App\Services\Crawler\SitemapDiscoverer;
use App\Services\Crawler\UrlNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapDiscovererTest extends TestCase
{
    private SitemapDiscoverer $discoverer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discoverer = new SitemapDiscoverer(
            new RobotsTxtPolicy,
            new UrlNormalizer,
        );
    }

    public function test_discovers_from_root_sitemap_xml(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/</loc></url>'
                .'<url><loc>https://example.com/about</loc></url>'
                .'<url><loc>https://example.com/services</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/services', $urls);
    }

    public function test_falls_back_to_bfs_when_no_sitemap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com' => Http::response(
                '<html><body><a href="/about">About</a><a href="/blog">Blog</a><a href="https://other.com/x">External</a></body></html>',
                200,
            ),
            'https://example.com/about' => Http::response(
                '<html><body><a href="/team">Team</a></body></html>',
                200,
            ),
            'https://example.com/blog' => Http::response('<html><body></body></html>', 200),
            'https://example.com/team' => Http::response('<html><body></body></html>', 200),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/blog', $urls);
        $this->assertContains('https://example.com/team', $urls);

        foreach ($urls as $u) {
            $this->assertStringStartsWith('https://example.com', $u, "External link leaked: {$u}");
        }
    }

    public function test_uses_sitemap_referenced_in_robots(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "Sitemap: https://example.com/news-sitemap.xml\n",
                200,
            ),
            'https://example.com/news-sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/news/1</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/news/1', $urls);
    }

    public function test_bfs_respects_depth_cap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com' => Http::response('<html><body><a href="/d1">D1</a></body></html>', 200),
            'https://example.com/d1' => Http::response('<html><body><a href="/d2">D2</a></body></html>', 200),
            'https://example.com/d2' => Http::response('<html><body><a href="/d3">D3</a></body></html>', 200),
            'https://example.com/d3' => Http::response('<html><body><a href="/d4">D4</a></body></html>', 200),
            'https://example.com/d4' => Http::response('<html><body></body></html>', 200),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/d3', $urls, 'depth 3 should be included');
        $this->assertNotContains('https://example.com/d4', $urls, 'depth 4 exceeds cap');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=SitemapDiscovererTest`

Expected: FAIL — class not found.

- [ ] **Step 3: Implement SitemapDiscoverer**

`app/Services/Crawler/SitemapDiscoverer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Rules\SafeExternalUrl;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SitemapDiscoverer
{
    private const MAX_BFS_DEPTH = 3;

    private const MAX_BFS_PAGES = 100;

    public function __construct(
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
    ) {}

    /**
     * @return Generator<int, string>
     */
    public function discover(string $rootUrl): Generator
    {
        $robots = $this->robotsTxt->fetchFor($rootUrl);

        $sitemapUrls = $robots->sitemapUrls();
        if ($sitemapUrls === []) {
            $sitemapUrls = [rtrim($rootUrl, '/').'/sitemap.xml'];
        }

        $seen = [];
        $yielded = 0;

        foreach ($sitemapUrls as $sitemapUrl) {
            foreach ($this->fetchSitemap($sitemapUrl) as $url) {
                if (! $this->normalizer->sameHost($url, $rootUrl)) {
                    continue;
                }
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $yielded++;
                yield $url;

                if ($yielded >= self::MAX_BFS_PAGES) {
                    return;
                }
            }
        }

        if ($yielded > 0) {
            return;
        }

        // BFS fallback
        yield from $this->bfs($rootUrl);
    }

    /**
     * @return Generator<int, string>
     */
    private function fetchSitemap(string $sitemapUrl): Generator
    {
        if (! SafeExternalUrl::isSafe($sitemapUrl)) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ChatbotIndexer/1.0'])
                ->get($sitemapUrl);
            if (! $response->successful()) {
                return;
            }

            $xml = @simplexml_load_string($response->body());
            if ($xml === false) {
                return;
            }

            foreach ($xml->url ?? [] as $url) {
                $loc = (string) ($url->loc ?? '');
                if ($loc !== '') {
                    yield $loc;
                }
            }

            // Sitemap index (nested sitemaps)
            foreach ($xml->sitemap ?? [] as $sitemap) {
                $nested = (string) ($sitemap->loc ?? '');
                if ($nested !== '') {
                    yield from $this->fetchSitemap($nested);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[Sitemap] (IS $) Fetch failed', ['url' => $sitemapUrl, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function bfs(string $rootUrl): Generator
    {
        $queue = [[$rootUrl, 0]];
        $seen = [$rootUrl => true];
        $yielded = 0;

        while ($queue !== [] && $yielded < self::MAX_BFS_PAGES) {
            [$current, $depth] = array_shift($queue);

            yield $current;
            $yielded++;

            if ($depth >= self::MAX_BFS_DEPTH) {
                continue;
            }

            foreach ($this->extractLinks($current, $rootUrl) as $link) {
                if (isset($seen[$link])) {
                    continue;
                }
                $seen[$link] = true;
                $queue[] = [$link, $depth + 1];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $url, string $rootUrl): array
    {
        if (! SafeExternalUrl::isSafe($url)) {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ChatbotIndexer/1.0'])
                ->get($url);
            if (! $response->successful()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->body());
        libxml_clear_errors();

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $url);
            if ($resolved === null) {
                continue;
            }
            if (! $this->normalizer->sameHost($resolved, $rootUrl)) {
                continue;
            }

            $links[] = $resolved;
        }

        return $links;
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.'/'.ltrim($href, '/');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

`php artisan test --filter=SitemapDiscovererTest`

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Crawler/SitemapDiscoverer.php tests/Unit/Services/Crawler/SitemapDiscovererTest.php
git commit -m "feat(crawler): add SitemapDiscoverer with sitemap-first + BFS fallback"
```

---

## Task 8: SiteCrawler orchestrator

**Files:**
- Create: `app/Services/Crawler/SiteCrawler.php`
- Test: `tests/Unit/Services/Crawler/SiteCrawlerTest.php`

This is the largest task. The service has multiple responsibilities (budget gate, diff skip, hash compare, upsert) so tests are grouped by scenario.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Crawler/SiteCrawlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

    private SiteCrawler $crawler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crawler = app(SiteCrawler::class);
        Bus::fake();
    }

    public function test_happy_path_indexes_pages(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Running,
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/about', 'https://example.com/services']),
                200,
            ),
            'https://example.com/about*' => Http::response('<html><body><p>About us page content here, long enough to exceed the minimum chunk size threshold easily.</p></body></html>', 200, ['Last-Modified' => 'Wed, 12 Mar 2025 10:00:00 GMT']),
            'https://example.com/services*' => Http::response('<html><body><p>Services page content here, long enough to exceed the minimum chunk size threshold easily.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Completed, $session->status);
        $this->assertGreaterThanOrEqual(2, $session->pages_indexed);
        $this->assertSame(2, KnowledgeItem::forTenant($tenant)->where('type', 'webpage')->count());

        Bus::assertDispatched(ProcessKnowledgeItem::class, 2);
    }

    public function test_budget_cap_truncates_crawl(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
        ]);

        // Pre-fill knowledge_items to trial cap (10) so the very first crawl page is over.
        KnowledgeItem::factory()->forTenant($tenant)->count(10)->create();

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/p1', 'https://example.com/p2']),
                200,
            ),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Partial, $session->status);
        $this->assertGreaterThan(0, $session->pages_skipped_budget);
    }

    public function test_diff_skip_for_unchanged_content_hash(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        $html = '<html><body><p>Stable content for diff-skip test, well over the minimum chunk threshold so it parses cleanly.</p></body></html>';
        // Pre-existing item with content_hash matching what we're about to fetch.
        KnowledgeItem::factory()
            ->forTenant($tenant)
            ->webpage('https://example.com/about', 'https://example.com/about')
            ->create([
                'metadata' => [
                    'crawl_session_id' => 1,
                    'content_hash' => 'sha256:'.hash('sha256', $html),
                    'last_modified' => null,
                    'etag' => null,
                ],
            ]);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/about']),
                200,
            ),
            'https://example.com/about*' => Http::response($html, 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(1, $session->pages_skipped_unchanged);
        $this->assertSame(0, $session->pages_indexed);
        Bus::assertNotDispatched(ProcessKnowledgeItem::class);
    }

    public function test_blocklist_silently_skips_url(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);
        $session = CrawlSession::factory()->forTenant($tenant)->create(['status' => CrawlSessionStatus::Running]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/admin', 'https://example.com/public']),
                200,
            ),
            'https://example.com/public*' => Http::response('<html><body><p>Public page content here, well above the minimum chunk size threshold.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $this->assertSame(0, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/admin')->count());
        $this->assertSame(1, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/public')->count());
    }

    public function test_robots_disallow_blocks_pages(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create(['status' => CrawlSessionStatus::Running]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nDisallow: /admin\n",
                200,
            ),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/admin', 'https://example.com/about']),
                200,
            ),
            'https://example.com/about*' => Http::response('<html><body><p>About us page content here, long enough to satisfy the chunk threshold easily for tests.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $this->assertSame(0, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/admin')->count());
        $session->refresh();
        $this->assertGreaterThanOrEqual(1, $session->pages_failed);
    }

    /**
     * @param  list<string>  $urls
     */
    private function sitemapWith(array $urls): string
    {
        $entries = '';
        foreach ($urls as $u) {
            $entries .= '<url><loc>'.$u.'</loc></url>';
        }

        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>';
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=SiteCrawlerTest`

Expected: FAIL — class not found.

- [ ] **Step 3: Implement SiteCrawler**

`app/Services/Crawler/SiteCrawler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Enums\CrawlSessionStatus;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Rules\SafeExternalUrl;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SiteCrawler
{
    private const MAX_PAGES = 100;

    private const MAX_BYTES_PER_PAGE = 5 * 1024 * 1024;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly SitemapDiscoverer $discoverer,
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
        private readonly UsageTracker $usage,
    ) {}

    public function crawl(Tenant $tenant, CrawlSession $session): void
    {
        $rootUrl = $tenant->website_url;
        if ($rootUrl === null || $rootUrl === '') {
            $this->finalize($session, CrawlSessionStatus::Failed, 'Tenant has no website_url');

            return;
        }

        $session->update([
            'status' => CrawlSessionStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $robots = $this->robotsTxt->fetchFor($rootUrl);
            $crawlDelay = max(1, $robots->crawlDelaySeconds());

            $blocklist = CrawlUrlBlocklist::forTenant($tenant)
                ->pluck('url_normalized')
                ->all();
            $blocked = array_fill_keys($blocklist, true);

            $pagesIndexed = 0;
            $pagesFailed = 0;
            $pagesSkippedBudget = 0;
            $pagesSkippedUnchanged = 0;
            $pagesDiscovered = 0;
            $emptyExtractCount = 0;

            foreach ($this->discoverer->discover($rootUrl) as $url) {
                $pagesDiscovered++;
                if ($pagesDiscovered > self::MAX_PAGES) {
                    break;
                }

                $normalized = $this->normalizer->normalize($url);

                if (isset($blocked[$normalized])) {
                    continue;
                }

                if (! $robots->isAllowed($url)) {
                    $pagesFailed++;

                    continue;
                }

                if (! $this->usage->canRecordUsage($tenant, UsageTracker::TYPE_KNOWLEDGE_ITEMS)
                    || ! $this->usage->canRecordUsage($tenant, UsageTracker::TYPE_TOKENS)) {
                    $pagesSkippedBudget++;
                    break;
                }

                if (! SafeExternalUrl::isSafe($url)) {
                    $pagesFailed++;

                    continue;
                }

                $existing = KnowledgeItem::forTenant($tenant)
                    ->where('type', 'webpage')
                    ->where('url_normalized', $normalized)
                    ->first();

                $headResult = $this->probeHeaders($url, $existing);
                if ($headResult['skip']) {
                    $pagesSkippedUnchanged++;

                    continue;
                }

                $body = $this->fetchBody($url);
                if ($body === null) {
                    $pagesFailed++;

                    continue;
                }
                if (trim(strip_tags($body)) === '') {
                    $emptyExtractCount++;
                }

                $contentHash = 'sha256:'.hash('sha256', $body);
                if ($existing && ($existing->metadata['content_hash'] ?? null) === $contentHash) {
                    $metadata = array_merge((array) $existing->metadata, [
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                        'crawl_session_id' => $session->id,
                    ]);
                    $existing->update(['metadata' => $metadata]);
                    $pagesSkippedUnchanged++;

                    continue;
                }

                $title = $this->extractTitle($body) ?: $url;

                $item = KnowledgeItem::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'type' => 'webpage',
                        'url_normalized' => $normalized,
                    ],
                    [
                        'title' => $title,
                        'source_url' => $url,
                        'content' => $body,
                        'status' => 'pending',
                        'metadata' => [
                            'crawl_session_id' => $session->id,
                            'content_hash' => $contentHash,
                            'last_modified' => $headResult['last_modified'],
                            'etag' => $headResult['etag'],
                        ],
                    ],
                );

                ProcessKnowledgeItem::dispatch($item);
                $pagesIndexed++;

                if ($crawlDelay > 0) {
                    $this->sleep($crawlDelay);
                }
            }

            $session->update([
                'pages_discovered' => $pagesDiscovered,
                'pages_indexed' => $pagesIndexed,
                'pages_failed' => $pagesFailed,
                'pages_skipped_budget' => $pagesSkippedBudget,
                'pages_skipped_unchanged' => $pagesSkippedUnchanged,
            ]);

            $status = match (true) {
                $pagesSkippedBudget > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $emptyExtractCount > 0 => CrawlSessionStatus::Partial,
                $pagesFailed > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesFailed > 0 => CrawlSessionStatus::Failed,
                default => CrawlSessionStatus::Completed,
            };

            $this->finalize($session, $status);
        } catch (\Throwable $e) {
            Log::error('[SiteCrawler] (IS $) Crawl failed', [
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            $this->finalize($session, CrawlSessionStatus::Failed, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array{skip: bool, last_modified: ?string, etag: ?string}
     */
    private function probeHeaders(string $url, ?KnowledgeItem $existing): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ChatbotIndexer/1.0'])
                ->head($url);

            $lastModified = $response->header('Last-Modified') ?: null;
            $etag = $response->header('ETag') ?: null;

            if ($existing !== null) {
                $priorLm = $existing->metadata['last_modified'] ?? null;
                $priorEtag = $existing->metadata['etag'] ?? null;
                if (($priorLm !== null && $priorLm === $lastModified)
                    || ($priorEtag !== null && $priorEtag === $etag)) {
                    return ['skip' => true, 'last_modified' => $lastModified, 'etag' => $etag];
                }
            }

            return ['skip' => false, 'last_modified' => $lastModified, 'etag' => $etag];
        } catch (\Throwable) {
            return ['skip' => false, 'last_modified' => null, 'etag' => null];
        }
    }

    private function fetchBody(string $url): ?string
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'ChatbotIndexer/1.0'])
                ->withOptions(['allow_redirects' => true])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES_PER_PAGE) {
                return null;
            }

            return $body;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return null;
    }

    private function finalize(CrawlSession $session, CrawlSessionStatus $status, ?string $error = null): void
    {
        $session->update([
            'status' => $status,
            'completed_at' => now(),
            'error_message' => $error,
        ]);
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
```

Note: the `sleep()` method is `protected` so tests can subclass-override it to skip real sleep. (Not needed for current tests; included so future tests don't slow down.)

- [ ] **Step 4: Run tests to verify they pass**

`php artisan test --filter=SiteCrawlerTest`

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Crawler/SiteCrawler.php tests/Unit/Services/Crawler/SiteCrawlerTest.php
git commit -m "feat(crawler): add SiteCrawler orchestrator with budget gating + diff skip"
```

---

## Task 9: CrawlWebsiteJob

**Files:**
- Create: `app/Jobs/CrawlWebsiteJob.php`
- Test: `tests/Unit/Jobs/CrawlWebsiteJobTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Jobs/CrawlWebsiteJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Tests\TestCase;

class CrawlWebsiteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_implements_not_tenant_aware(): void
    {
        $this->assertInstanceOf(
            NotTenantAware::class,
            new CrawlWebsiteJob(Tenant::factory()->make(), 'initial'),
        );
    }

    public function test_creates_session_then_delegates_to_site_crawler(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        $crawler = Mockery::mock(SiteCrawler::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with(
                Mockery::on(fn ($t) => $t->id === $tenant->id),
                Mockery::on(fn ($s) => $s instanceof CrawlSession && $s->mode === CrawlMode::Initial),
            );
        $this->app->instance(SiteCrawler::class, $crawler);

        $job = new CrawlWebsiteJob($tenant, 'initial');
        $job->handle(app(SiteCrawler::class));

        $this->assertSame(1, CrawlSession::forTenant($tenant)->count());
        $session = CrawlSession::forTenant($tenant)->first();
        $this->assertSame(CrawlMode::Initial, $session->mode);
    }

    public function test_failed_callback_marks_session_failed(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
        ]);

        $job = new CrawlWebsiteJob($tenant, 'initial');
        $job->failed(new \RuntimeException('boom'));

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Failed, $session->status);
        $this->assertStringContainsString('boom', (string) $session->error_message);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=CrawlWebsiteJobTest`

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the job**

`app/Jobs/CrawlWebsiteJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class CrawlWebsiteJob implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public string $queue = 'crawls';

    public function __construct(
        public Tenant $tenant,
        public string $mode = 'initial',
    ) {}

    public function handle(SiteCrawler $crawler): void
    {
        $modeEnum = CrawlMode::from($this->mode);

        // On retry, mark any in-flight session for this tenant as Failed
        // before starting a fresh session — see spec retry semantics.
        CrawlSession::forTenant($this->tenant)
            ->where('status', CrawlSessionStatus::Running->value)
            ->update([
                'status' => CrawlSessionStatus::Failed->value,
                'error_message' => 'Superseded by retry',
                'completed_at' => now(),
            ]);

        $session = CrawlSession::create([
            'tenant_id' => $this->tenant->id,
            'mode' => $modeEnum,
            'status' => CrawlSessionStatus::Queued,
        ]);

        Log::debug('[CrawlWebsiteJob] (NO $) Starting crawl', [
            'tenant_id' => $this->tenant->id,
            'session_id' => $session->id,
            'mode' => $modeEnum->value,
        ]);

        $crawler->crawl($this->tenant, $session);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[CrawlWebsiteJob] (NO $) Final failure', [
            'tenant_id' => $this->tenant->id,
            'error' => $exception->getMessage(),
        ]);

        $latest = CrawlSession::forTenant($this->tenant)
            ->whereIn('status', [
                CrawlSessionStatus::Queued->value,
                CrawlSessionStatus::Running->value,
            ])
            ->latest('id')
            ->first();

        $latest?->update([
            'status' => CrawlSessionStatus::Failed,
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

`php artisan test --filter=CrawlWebsiteJobTest`

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/CrawlWebsiteJob.php tests/Unit/Jobs/CrawlWebsiteJobTest.php
git commit -m "feat(crawler): add CrawlWebsiteJob on crawls queue (NotTenantAware)"
```

---

## Task 10: RefreshAllCrawls command + scheduler

**Files:**
- Create: `app/Console/Commands/RefreshAllCrawls.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/RefreshAllCrawlsCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RefreshAllCrawlsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_for_eligible_tenants(): void
    {
        Bus::fake();

        $eligible = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        Tenant::factory()->create([
            'status' => 'active',
            'website_url' => null,
            'auto_recrawl' => true,
        ]);
        Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => false,
        ]);
        Tenant::factory()->create([
            'status' => 'suspended',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
        Bus::assertDispatched(CrawlWebsiteJob::class, function (CrawlWebsiteJob $job) use ($eligible) {
            return $job->tenant->id === $eligible->id && $job->mode === 'refresh';
        });
    }

    public function test_skips_tenant_with_recent_in_flight_session(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_dispatches_when_prior_session_is_stale(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
            'created_at' => now()->subHours(8),
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=RefreshAllCrawlsCommandTest`

Expected: FAIL — command not found.

- [ ] **Step 3: Implement the command**

`app/Console/Commands/RefreshAllCrawls.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Console\Command;

class RefreshAllCrawls extends Command
{
    protected $signature = 'crawls:refresh-all';

    protected $description = 'Dispatch a CrawlWebsiteJob (mode=refresh) for every eligible tenant';

    private const IN_FLIGHT_WINDOW_HOURS = 6;

    public function handle(): int
    {
        $dispatched = 0;
        $skipped = 0;

        Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('website_url')
            ->where('auto_recrawl', true)
            ->chunkById(100, function ($tenants) use (&$dispatched, &$skipped): void {
                foreach ($tenants as $tenant) {
                    if ($this->hasInFlightSession($tenant->id)) {
                        $skipped++;

                        continue;
                    }
                    CrawlWebsiteJob::dispatch($tenant, 'refresh');
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} crawl jobs ({$skipped} skipped — in-flight).");

        return self::SUCCESS;
    }

    private function hasInFlightSession(int $tenantId): bool
    {
        return CrawlSession::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                CrawlSessionStatus::Queued->value,
                CrawlSessionStatus::Running->value,
            ])
            ->where('created_at', '>', now()->subHours(self::IN_FLIGHT_WINDOW_HOURS))
            ->exists();
    }
}
```

- [ ] **Step 4: Register the daily schedule**

In `routes/console.php`, add below the existing dk:cleanup entry:

```php
Schedule::command('crawls:refresh-all')->daily();
```

- [ ] **Step 5: Run tests to verify they pass**

`php artisan test --filter=RefreshAllCrawlsCommandTest`

Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/RefreshAllCrawls.php routes/console.php tests/Feature/Console/RefreshAllCrawlsCommandTest.php
git commit -m "feat(crawler): add daily crawls:refresh-all scheduler command"
```

---

## Task 11: RegisterRequest + RegisterController updates

**Files:**
- Modify: `app/Http/Requests/Auth/RegisterRequest.php`
- Modify: `app/Http/Controllers/Auth/RegisterController.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`
- Create: `tests/Feature/Auth/RegistrationWizardTest.php` (matches spec File Map naming)

- [ ] **Step 1: Write the failing test (new website-url feature test)**

`tests/Feature/Auth/RegistrationWizardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\CrawlWebsiteJob;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RegistrationWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_website_url_does_not_dispatch_crawl(): void
    {
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'noweb@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect('/dashboard');
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
        $this->assertNull(Tenant::where('name', 'Co')->first()->website_url);
    }

    public function test_registration_with_website_url_dispatches_crawl_and_saves_url(): void
    {
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'with@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'https://example.com',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertSame('https://example.com', Tenant::where('name', 'Co')->first()->website_url);
        Bus::assertDispatched(CrawlWebsiteJob::class, function (CrawlWebsiteJob $job) {
            return $job->tenant->website_url === 'https://example.com' && $job->mode === 'initial';
        });
    }

    public function test_malformed_url_rejected_at_validation(): void
    {
        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'bad@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'not a url',
        ]);

        $response->assertSessionHasErrors('website_url');
        $this->assertDatabaseMissing('tenants', ['name' => 'Co']);
    }

    public function test_private_url_rejected_by_safe_external_url(): void
    {
        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'ssrf@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'http://localhost/admin',
        ]);

        $response->assertSessionHasErrors('website_url');
    }

    public function test_unreachable_but_well_formed_url_still_creates_tenant(): void
    {
        // No HEAD check at submit — even a fake-looking URL should pass validation.
        Bus::fake();

        $response = $this->post('/register', [
            'name' => 'User',
            'email' => 'unreach@example.com',
            'company_name' => 'Co',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => 'https://this-host-does-not-exist-12345.example',
        ]);

        $response->assertRedirect('/dashboard');
        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php artisan test --filter=RegistrationWizardTest`

Expected: FAIL — `website_url` validation / dispatch missing.

- [ ] **Step 3: Update RegisterRequest**

Add the `website_url` rule in `app/Http/Requests/Auth/RegisterRequest.php`:

```php
use App\Rules\SafeExternalUrl;
// ...
public function rules(): array
{
    return [
        'company_name' => ['required', 'string', 'max:255'],
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'confirmed', Password::defaults()],
        'website_url' => ['nullable', 'url:http,https', 'max:2048', new SafeExternalUrl],
    ];
}
```

- [ ] **Step 4: Update RegisterController to set website_url + dispatch crawl**

Modify `store()` in `app/Http/Controllers/Auth/RegisterController.php`:

```php
public function store(RegisterRequest $request): RedirectResponse
{
    Log::debug('[Register] (NO $) Creating tenant', [
        'company' => $request->company_name,
        'has_website_url' => $request->filled('website_url'),
    ]);

    $tenant = DB::transaction(function () use ($request) {
        $tenant = Tenant::create([
            'name' => $request->company_name,
            'website_url' => $request->website_url,
            'auto_recrawl' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'owner',
        ]);

        event(new Registered($user));
        Auth::login($user);

        return $tenant;
    });

    if ($tenant->website_url) {
        \App\Jobs\CrawlWebsiteJob::dispatch($tenant, 'initial');
        session()->flash('website_indexing_started', true);
    }

    return redirect()->route('dashboard');
}
```

- [ ] **Step 5: Update existing RegistrationTest**

In `tests/Feature/Auth/RegistrationTest.php`, leave existing tests intact (none of the new optional `website_url` field should break them). Add one regression-safety assertion in `test_new_users_can_register` that `website_url` defaults to null and no crawl job is dispatched:

```php
use App\Jobs\CrawlWebsiteJob;
use Illuminate\Support\Facades\Bus;
// ...
public function test_new_users_can_register(): void
{
    Bus::fake();

    $response = $this->post('/register', [
        // ... existing payload ...
    ]);

    // ... existing assertions ...

    Bus::assertNotDispatched(CrawlWebsiteJob::class);
}
```

- [ ] **Step 6: Run all registration tests**

`php artisan test --filter=RegistrationTest --filter=RegistrationWizardTest`

Then run the full suite to catch regressions:

`php artisan test`

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Auth/RegisterRequest.php app/Http/Controllers/Auth/RegisterController.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/RegistrationWizardTest.php
git commit -m "feat(crawler): accept optional website_url at registration; dispatch crawl"
```

---

## Task 12: Register.vue wizard rewrite

**Files:**
- Modify: `resources/js/Pages/Auth/Register.vue` (full rewrite to 3-step wizard)
- Modify: `app/Http/Controllers/Auth/RegisterController.php::create()` (share trial limit)

- [ ] **Step 1: Modify `create()` to share trial-limit hint**

```php
public function create(): Response
{
    return Inertia::render('Auth/Register', [
        'trialKnowledgeItemsLimit' => (int) config('billing.trial_limits.knowledge_items', 10),
    ]);
}
```

- [ ] **Step 2: Rewrite Register.vue to a 3-step wizard**

Full file (replaces existing):

```vue
<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Input } from '@/Components/ui/input'
import { Label } from '@/Components/ui/label'

const props = defineProps({
  trialKnowledgeItemsLimit: { type: Number, default: 10 },
})

const route = useRoute()
const currentStep = ref(1)

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  company_name: '',
  website_url: '',
})

const STEP_FIELDS = {
  1: ['name', 'email', 'password', 'password_confirmation'],
  2: ['company_name'],
  3: ['website_url'],
}

const step1Valid = computed(() =>
  form.name && form.email && form.password && form.password === form.password_confirmation
)
const step2Valid = computed(() => !!form.company_name)

const next = () => {
  if (currentStep.value === 1 && !step1Valid.value) return
  if (currentStep.value === 2 && !step2Valid.value) return
  currentStep.value++
}

const back = () => {
  if (currentStep.value > 1) currentStep.value--
}

const submit = () => {
  form.post(route('register.store'), {
    onError: errors => {
      const fieldErrorStep = Object.keys(errors)
        .map(field => Number(Object.entries(STEP_FIELDS).find(([, fs]) => fs.includes(field))?.[0]))
        .filter(Boolean)
        .sort()[0]
      if (fieldErrorStep) currentStep.value = fieldErrorStep
    },
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}

const skipWebsite = () => {
  form.website_url = ''
  submit()
}
</script>

<template>
  <Head title="Register" />

  <div class="min-h-screen flex items-center justify-center bg-background py-12 px-4 sm:px-6 lg:px-8">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Create your account</CardTitle>
        <CardDescription>
          Step {{ currentStep }} of 3
          <span class="block text-xs mt-1">
            Already have an account?
            <Link :href="route('login')" class="font-medium text-primary hover:text-primary/80">Sign in</Link>
          </span>
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form @submit.prevent="submit" class="space-y-4">
          <!-- Step 1: Account -->
          <template v-if="currentStep === 1">
            <div class="space-y-2">
              <Label for="name">Your Name</Label>
              <Input id="name" v-model="form.name" type="text" required autofocus placeholder="John Doe" />
              <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
            </div>
            <div class="space-y-2">
              <Label for="email">Email address</Label>
              <Input id="email" v-model="form.email" type="email" required placeholder="you@example.com" />
              <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <div class="space-y-2">
              <Label for="password">Password</Label>
              <Input id="password" v-model="form.password" type="password" required placeholder="Password" />
              <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
            </div>
            <div class="space-y-2">
              <Label for="password_confirmation">Confirm Password</Label>
              <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required placeholder="Confirm password" />
            </div>
            <Button type="button" class="w-full" :disabled="!step1Valid" @click="next">Next</Button>
          </template>

          <!-- Step 2: Company -->
          <template v-if="currentStep === 2">
            <div class="space-y-2">
              <Label for="company_name">Company Name</Label>
              <Input id="company_name" v-model="form.company_name" type="text" required autofocus placeholder="Your Company" />
              <p v-if="form.errors.company_name" class="text-sm text-destructive">{{ form.errors.company_name }}</p>
            </div>
            <div class="flex gap-2">
              <Button type="button" variant="outline" @click="back" class="flex-1">Back</Button>
              <Button type="button" class="flex-1" :disabled="!step2Valid" @click="next">Next</Button>
            </div>
          </template>

          <!-- Step 3: Website (optional) -->
          <template v-if="currentStep === 3">
            <div class="space-y-2">
              <Label for="website_url">Website URL (optional)</Label>
              <Input id="website_url" v-model="form.website_url" type="url" placeholder="https://yourcompany.com" />
              <p class="text-xs text-muted-foreground">
                We'll automatically index your site so the bot can answer questions about your products and content.
                Free trial indexes up to {{ trialKnowledgeItemsLimit }} pages.
              </p>
              <p v-if="form.errors.website_url" class="text-sm text-destructive">{{ form.errors.website_url }}</p>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex gap-2">
                <Button type="button" variant="outline" @click="back" class="flex-1">Back</Button>
                <Button type="submit" class="flex-1" :disabled="form.processing">
                  {{ form.processing ? 'Creating account...' : 'Create account' }}
                </Button>
              </div>
              <Button type="button" variant="ghost" @click="skipWebsite" :disabled="form.processing">
                Skip — I'll add this later
              </Button>
            </div>
          </template>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
```

- [ ] **Step 3: Run feature tests (the wizard payload is functionally identical for the API)**

`php artisan test --filter=Registration`

Expected: PASS.

- [ ] **Step 4: Browser smoke (manual)**

Open `http://127.0.0.1:8001/register` and:
1. Step 1 → fill account fields → Next
2. Step 2 → fill company name → Next
3. Step 3 → either fill URL and submit, or click Skip
4. Verify redirect to `/dashboard`

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Auth/Register.vue app/Http/Controllers/Auth/RegisterController.php
git commit -m "feat(crawler): convert registration to 3-step wizard with optional website URL"
```

---

## Task 13: HandleInertiaRequests share + IndexingStatusBanner + Dashboard

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/Components/IndexingStatusBanner.vue`
- Modify: `resources/js/Pages/Client/Dashboard.vue`
- Test: `tests/Feature/Middleware/HandleInertiaRequestsShareLatestCrawlSessionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleInertiaRequestsShareLatestCrawlSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_receives_latest_crawl_session(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $session = CrawlSession::factory()->forTenant($tenant)->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page->has('latest_crawl_session', fn ($s) => $s->where('id', $session->id)->etc()));
    }

    public function test_unrelated_route_does_not_share_session(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CrawlSession::factory()->forTenant($tenant)->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertInertia(fn ($page) => $page->where('latest_crawl_session', null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=HandleInertiaRequestsShareLatestCrawlSessionTest`

Expected: FAIL — `latest_crawl_session` not shared.

- [ ] **Step 3: Update HandleInertiaRequests**

In `app/Http/Middleware/HandleInertiaRequests.php`, add inside `share()`:

```php
use App\Models\CrawlSession;
use Illuminate\Http\Request;
// ...
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        // ... existing shares ...
        'latest_crawl_session' => fn () => $this->latestCrawlSession($request),
    ]);
}

private function latestCrawlSession(Request $request): ?array
{
    $name = $request->route()?->getName() ?? '';
    $eligible = str_starts_with($name, 'dashboard')
        || str_starts_with($name, 'knowledge.')
        || str_starts_with($name, 'widget.');
    if (! $eligible) {
        return null;
    }

    $user = $request->user();
    if ($user === null || $user->tenant_id === null) {
        return null;
    }

    return once(function () use ($user) {
        $session = CrawlSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        if ($session === null) {
            return null;
        }

        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'mode' => $session->mode->value,
            'pages_indexed' => $session->pages_indexed,
            'pages_discovered' => $session->pages_discovered,
            'pages_skipped_budget' => $session->pages_skipped_budget,
            'error_message' => $session->error_message,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
        ];
    });
}
```

(The `once()` helper memoizes per request — Laravel ≥10.34 ships this.)

- [ ] **Step 4: Create IndexingStatusBanner component**

`resources/js/Components/IndexingStatusBanner.vue`:

```vue
<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Card } from '@/Components/ui/card'

const page = usePage()
const session = computed(() => page.props.latest_crawl_session)

const banner = computed(() => {
  if (!session.value) return null
  const s = session.value
  switch (s.status) {
    case 'queued':
    case 'running':
      return { tone: 'info', text: `Indexing your site… ${s.pages_indexed}${s.pages_discovered ? ` of ${s.pages_discovered}` : ''} pages indexed so far.` }
    case 'completed':
      return { tone: 'success', text: `Indexed ${s.pages_indexed} pages from your site.`, link: { href: `/knowledge-base?crawl_session_id=${s.id}`, label: 'View' } }
    case 'partial':
      if (s.pages_skipped_budget > 0) {
        return { tone: 'warning', text: `Indexed ${s.pages_indexed} pages — plan limit reached. Upgrade to crawl more.`, link: { href: '/billing', label: 'Upgrade' } }
      }
      return { tone: 'warning', text: `Indexed ${s.pages_indexed} pages — some pages could not be processed.`, link: { href: `/knowledge-base?crawl_session_id=${s.id}`, label: 'View' } }
    case 'failed':
      return { tone: 'error', text: s.error_message ? `Indexing failed: ${s.error_message}` : 'Indexing failed.', link: { href: '/widget-settings', label: 'Retry' } }
    default:
      return null
  }
})

const toneClasses = {
  info: 'bg-blue-50 text-blue-900 border-blue-200',
  success: 'bg-emerald-50 text-emerald-900 border-emerald-200',
  warning: 'bg-amber-50 text-amber-900 border-amber-200',
  error: 'bg-rose-50 text-rose-900 border-rose-200',
}
</script>

<template>
  <Card v-if="banner" :class="['p-4 border', toneClasses[banner.tone]]">
    <div class="flex items-center justify-between gap-4">
      <span>{{ banner.text }}</span>
      <Link v-if="banner.link" :href="banner.link.href" class="text-sm font-medium underline">{{ banner.link.label }}</Link>
    </div>
  </Card>
</template>
```

- [ ] **Step 5: Add banner to Dashboard.vue**

In `resources/js/Pages/Client/Dashboard.vue`, import and render the banner near the top of the content:

```vue
import IndexingStatusBanner from '@/Components/IndexingStatusBanner.vue'
// ...
<IndexingStatusBanner class="mb-4" />
```

- [ ] **Step 6: Run tests to verify they pass**

`php artisan test --filter=HandleInertiaRequestsShareLatestCrawlSessionTest`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Components/IndexingStatusBanner.vue resources/js/Pages/Client/Dashboard.vue tests/Feature/Middleware/HandleInertiaRequestsShareLatestCrawlSessionTest.php
git commit -m "feat(crawler): share latest crawl session on dashboard; banner component"
```

---

## Task 14: WebsiteIndexingController (settings)

**Files:**
- Create: `app/Http/Controllers/Client/WebsiteIndexingController.php`
- Create: `app/Http/Requests/Client/UpdateWebsiteIndexingRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Client/WebsiteIndexingControllerTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Client/WebsiteIndexingControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebsiteIndexingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_changes_website_url(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://newsite.com',
            'auto_recrawl' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('https://newsite.com', $tenant->fresh()->website_url);
        $this->assertTrue($tenant->fresh()->auto_recrawl);
    }

    public function test_manual_recrawl_dispatches_job(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        Bus::assertDispatched(CrawlWebsiteJob::class, fn (CrawlWebsiteJob $job) => $job->mode === 'manual');
    }

    public function test_manual_recrawl_blocked_within_cooldown(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'started_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasErrors('cooldown');
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_manual_recrawl_allowed_after_cooldown(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'started_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        Bus::assertDispatched(CrawlWebsiteJob::class);
    }

    public function test_clearing_website_url_does_not_dispatch(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://old.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => null,
            'auto_recrawl' => false,
        ]);

        $this->assertNull($tenant->fresh()->website_url);
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=WebsiteIndexingControllerTest`

Expected: FAIL — route not defined.

- [ ] **Step 3: Create the request**

`app/Http/Requests/Client/UpdateWebsiteIndexingRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteIndexingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'website_url' => ['nullable', 'url:http,https', 'max:2048', new SafeExternalUrl],
            'auto_recrawl' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/Client/WebsiteIndexingController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\UpdateWebsiteIndexingRequest;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteIndexingController extends Controller
{
    private const MANUAL_COOLDOWN_MINUTES = 60;

    public function update(UpdateWebsiteIndexingRequest $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->update([
            'website_url' => $request->website_url,
            'auto_recrawl' => $request->boolean('auto_recrawl'),
        ]);

        return back()->with('status', 'Website indexing settings saved.');
    }

    public function recrawl(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant->website_url === null) {
            return back()->withErrors(['website_url' => 'No website URL set.']);
        }

        $recent = CrawlSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('started_at', '>', now()->subMinutes(self::MANUAL_COOLDOWN_MINUTES))
            ->exists();

        if ($recent) {
            return back()->withErrors(['cooldown' => 'Please wait — your last crawl started less than an hour ago.']);
        }

        CrawlWebsiteJob::dispatch($tenant, 'manual');

        return back()->with('status', 'Re-crawl queued.');
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, inside the auth group near the existing `widget-settings` block:

```php
Route::patch('/widget-settings/website-indexing', [WebsiteIndexingController::class, 'update'])->name('widget.indexing.update');
Route::post('/widget-settings/website-indexing/recrawl', [WebsiteIndexingController::class, 'recrawl'])->name('widget.indexing.recrawl');
```

(Include `use App\Http\Controllers\Client\WebsiteIndexingController;` at the top.)

- [ ] **Step 6: Run tests to verify they pass**

`php artisan test --filter=WebsiteIndexingControllerTest`

Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Client/WebsiteIndexingController.php app/Http/Requests/Client/UpdateWebsiteIndexingRequest.php routes/web.php tests/Feature/Client/WebsiteIndexingControllerTest.php
git commit -m "feat(crawler): add WebsiteIndexingController with manual recrawl + cooldown"
```

---

## Task 15: KnowledgeBaseController — filter + delete blocklist

**Files:**
- Modify: `app/Http/Controllers/Client/KnowledgeBaseController.php` (index filter + destroy blocklist)
- Test: `tests/Feature/Client/KnowledgeBaseCrawlIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseCrawlIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_crawl_session_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $session = CrawlSession::factory()->forTenant($tenant)->create();

        KnowledgeItem::factory()->forTenant($tenant)->count(2)->create([
            'type' => 'webpage',
            'metadata' => ['crawl_session_id' => $session->id],
        ]);
        KnowledgeItem::factory()->forTenant($tenant)->count(3)->create();

        $response = $this->actingAs($user)->get('/knowledge-base?crawl_session_id='.$session->id);

        $response->assertInertia(fn ($p) => $p->where('items.total', 2));
    }

    public function test_destroying_webpage_item_adds_to_blocklist_when_confirmed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->webpage('https://example.com/x', 'https://example.com/x')->create();

        $this->actingAs($user)->delete("/knowledge-base/{$item->id}", ['blocklist' => true]);

        $this->assertDatabaseHas('crawl_url_blocklist', [
            'tenant_id' => $tenant->id,
            'url_normalized' => 'https://example.com/x',
        ]);
    }

    public function test_destroying_webpage_item_skips_blocklist_when_not_confirmed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->webpage('https://example.com/y', 'https://example.com/y')->create();

        $this->actingAs($user)->delete("/knowledge-base/{$item->id}", ['blocklist' => false]);

        $this->assertDatabaseMissing('crawl_url_blocklist', [
            'tenant_id' => $tenant->id,
            'url_normalized' => 'https://example.com/y',
        ]);
    }

    public function test_destroying_non_webpage_does_not_touch_blocklist(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->create(['type' => 'text']);

        $this->actingAs($user)->delete("/knowledge-base/{$item->id}", ['blocklist' => true]);

        $this->assertSame(0, CrawlUrlBlocklist::forTenant($tenant)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

`php artisan test --filter=KnowledgeBaseCrawlIntegrationTest`

Expected: FAIL — filter / blocklist behavior missing.

- [ ] **Step 3: Update KnowledgeBaseController**

In `app/Http/Controllers/Client/KnowledgeBaseController.php`:

`index()`: read current code, then add to the existing query — before pagination:

```php
$query = KnowledgeItem::forTenant($request->user()->tenant);

if ($crawlSessionId = $request->integer('crawl_session_id')) {
    $query->where('metadata->crawl_session_id', $crawlSessionId);
}

$items = $query->latest()->paginate(20);
```

(Adjust to fit the controller's existing structure — keep filter idempotent and additive.)

`destroy()`: after the existing delete logic, insert blocklist row when applicable:

```php
use App\Models\CrawlUrlBlocklist;
// ...
public function destroy(Request $request, KnowledgeItem $knowledgeItem): RedirectResponse
{
    $this->authorize('delete', $knowledgeItem);

    if ($knowledgeItem->type === 'webpage'
        && $knowledgeItem->url_normalized !== null
        && $request->boolean('blocklist')) {
        CrawlUrlBlocklist::firstOrCreate(
            [
                'tenant_id' => $knowledgeItem->tenant_id,
                'url_normalized' => $knowledgeItem->url_normalized,
            ],
            [
                'excluded_at' => now(),
            ],
        );
    }

    $knowledgeItem->delete();

    return redirect()->route('knowledge.index')->with('status', 'Knowledge item deleted.');
}
```

(Method signature may differ — keep existing authorization + redirect; add the blocklist branch above the delete call.)

- [ ] **Step 4: Run tests to verify they pass**

`php artisan test --filter=KnowledgeBaseCrawlIntegrationTest`

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/KnowledgeBaseController.php tests/Feature/Client/KnowledgeBaseCrawlIntegrationTest.php
git commit -m "feat(crawler): KB index filter by crawl_session_id; blocklist on webpage delete"
```

---

## Task 16: WidgetSettings.vue — Website indexing section

**Files:**
- Modify: `resources/js/Pages/Client/WidgetSettings.vue`
- Modify: `app/Http/Controllers/Client/WidgetController.php::index` — pass `website_url`, `auto_recrawl`, and `last_crawl_session` to the view

- [ ] **Step 1: Update WidgetController::index to share extra props**

Add to the props returned by `index()`:

```php
'website_url' => $tenant->website_url,
'auto_recrawl' => $tenant->auto_recrawl,
'last_crawl_session' => \App\Models\CrawlSession::forTenant($tenant)->latest('id')->first()?->only([
    'id', 'status', 'mode', 'pages_indexed', 'pages_discovered', 'started_at', 'completed_at',
]),
```

- [ ] **Step 2: Add Website indexing section to WidgetSettings.vue**

Insert a new card section (read existing file for placement; this is additive):

```vue
<script setup>
import { useForm, router } from '@inertiajs/vue3'
// ...

const props = defineProps({
  website_url: { type: String, default: '' },
  auto_recrawl: { type: Boolean, default: true },
  last_crawl_session: { type: Object, default: null },
  // ... existing props
})

const indexingForm = useForm({
  website_url: props.website_url || '',
  auto_recrawl: props.auto_recrawl,
})

const saveIndexing = () => {
  indexingForm.patch(route('widget.indexing.update'), { preserveScroll: true })
}

const recrawlNow = () => {
  router.post(route('widget.indexing.recrawl'), {}, { preserveScroll: true })
}
</script>

<!-- inside template, after existing settings cards -->
<Card>
  <CardHeader>
    <CardTitle>Website indexing</CardTitle>
    <CardDescription>
      Automatically index your website so your chatbot can answer questions about your content.
    </CardDescription>
  </CardHeader>
  <CardContent>
    <form @submit.prevent="saveIndexing" class="space-y-4">
      <div class="space-y-2">
        <Label for="website_url">Website URL</Label>
        <Input id="website_url" v-model="indexingForm.website_url" type="url" placeholder="https://yourcompany.com" />
        <p v-if="indexingForm.errors.website_url" class="text-sm text-destructive">{{ indexingForm.errors.website_url }}</p>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" id="auto_recrawl" v-model="indexingForm.auto_recrawl" />
        <Label for="auto_recrawl">Re-crawl my site daily</Label>
      </div>
      <div class="flex gap-2">
        <Button type="submit" :disabled="indexingForm.processing">Save</Button>
        <Button type="button" variant="outline" @click="recrawlNow" :disabled="!indexingForm.website_url">
          Re-crawl now
        </Button>
      </div>
    </form>

    <div v-if="last_crawl_session" class="mt-4 text-sm text-muted-foreground">
      Last crawl: <span class="font-medium">{{ last_crawl_session.status }}</span>
      ({{ last_crawl_session.pages_indexed }} pages)
      <span v-if="last_crawl_session.completed_at">on {{ new Date(last_crawl_session.completed_at).toLocaleDateString() }}</span>
    </div>
  </CardContent>
</Card>
```

- [ ] **Step 3: Manual smoke**

Visit `http://127.0.0.1:8001/widget-settings` as `test@example.com`, set a website URL, click Save, then click Re-crawl now. Verify a `CrawlSession` row is created (check DB or `/knowledge-base` after a few seconds).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Client/WidgetController.php resources/js/Pages/Client/WidgetSettings.vue
git commit -m "feat(crawler): add Website indexing section to WidgetSettings page"
```

---

## Task 17: KnowledgeBase Index — crawl_session filter chip + delete confirm

**Files:**
- Modify: `resources/js/Pages/Client/KnowledgeBase/Index.vue`

- [ ] **Step 1: Add crawl_session_id query-string-aware filter chip**

In `Index.vue`, read existing list code, then add:

```vue
<script setup>
import { router } from '@inertiajs/vue3'
// ...

const props = defineProps({
  items: Object,
  crawl_session_id: { type: [Number, null], default: null },
})

const clearCrawlFilter = () => {
  router.get(route('knowledge.index'), {}, { preserveScroll: true })
}
</script>

<!-- in template, near the list header -->
<div v-if="crawl_session_id" class="mb-4 flex items-center gap-2">
  <span class="px-2 py-1 rounded bg-muted text-xs">Filtered: Website crawl session #{{ crawl_session_id }}</span>
  <button class="text-xs underline" @click="clearCrawlFilter">Clear filter</button>
</div>
```

- [ ] **Step 2: Update destroy confirmation to ask about blocklist on webpage items**

Wherever the delete confirm is wired in `Index.vue` (or its delete modal), if `item.type === 'webpage'`, show a checkbox:

```vue
<!-- inside delete confirm dialog -->
<div v-if="itemBeingDeleted?.type === 'webpage'" class="mt-2">
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" v-model="blocklistOnDelete" />
    Don't re-create this page on the next crawl
  </label>
</div>
```

When submitting:

```js
router.delete(route('knowledge.destroy', itemBeingDeleted.id), {
  data: { blocklist: blocklistOnDelete.value },
})
```

- [ ] **Step 3: Update KnowledgeBaseController::index to pass `crawl_session_id` back to the page**

Add to the Inertia render array:

```php
'crawl_session_id' => $request->integer('crawl_session_id') ?: null,
```

- [ ] **Step 4: Manual smoke**

1. Visit `/knowledge-base?crawl_session_id=1` — verify chip displays and clears.
2. Delete a webpage item — verify the "Don't re-create" checkbox appears.
3. Confirm with checkbox checked — verify `crawl_url_blocklist` row exists in DB.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/KnowledgeBaseController.php resources/js/Pages/Client/KnowledgeBase/Index.vue
git commit -m "feat(crawler): KB filter chip + delete-with-blocklist confirmation"
```

---

## Task 18: CONTEXT.md update

**Files:**
- Modify: `CONTEXT.md`

- [ ] **Step 1: Add CrawlSession + CrawlUrlBlocklist to domain glossary**

Append (under the existing entity list):

```markdown
### CrawlSession

A single execution of the website crawler for a tenant. Lifecycle: `Queued` → `Running` → `Completed` | `Partial` | `Failed`. Modes: `Initial` (created at registration), `Refresh` (daily scheduled), `Manual` (user-triggered with 1-hour cooldown). Stores discovery and budget-skip counts for surfacing in the dashboard banner.

### CrawlUrlBlocklist

Per-tenant set of URLs the tenant has explicitly removed from their indexed knowledge. Persists across crawl sessions so the daily refresh does not re-create deleted pages. Populated when a tenant deletes a `type=webpage` KnowledgeItem with the "don't re-create" confirmation.
```

- [ ] **Step 2: Commit**

```bash
git add CONTEXT.md
git commit -m "docs: add CrawlSession + CrawlUrlBlocklist to domain glossary"
```

---

## Final Quality Gates (per CLAUDE.md workflow)

- [ ] **Step 1: Full test suite**

```bash
php artisan test
```

Expected: all tests pass. Investigate any breakage immediately (most likely candidate: existing `RegistrationTest` if assertions don't account for the new field).

- [ ] **Step 2: Pint — check**

```bash
./vendor/bin/pint --test
```

- [ ] **Step 3: Pint — fix (only if Step 2 flagged anything)**

```bash
./vendor/bin/pint
php artisan test
git add -A
git commit -m "style(pint): apply auto-fixes after crawler feature"
```

- [ ] **Step 4: /simplify pass 1**

Run the `simplify` skill (3 parallel reviewers — reuse, quality, efficiency). Apply real fixes; skip stylistic noise with a one-line reason in the response. Commit any changes as `refactor: apply /simplify pass 1 findings`.

- [ ] **Step 5: Pint pass 2**

```bash
./vendor/bin/pint --test
./vendor/bin/pint   # if needed
php artisan test
```

Commit any style fixes.

- [ ] **Step 6: /simplify pass 2**

Catch issues introduced by the first cleanup. Same protocol.

- [ ] **Step 7: PHPStan baseline-zero check**

```bash
./vendor/bin/phpstan analyse
```

Expected: zero errors (matches the project's baseline-zero invariant per `arch_phpstan_baseline_zero.md` memory).

- [ ] **Step 8: Browser smoke (manual, end-to-end)**

1. Start dev server: `php artisan serve --host=127.0.0.1 --port=8001` and `npm run dev`.
2. Register a new tenant with `https://example.com` as website URL — confirm wizard flow.
3. Land on dashboard — confirm banner appears.
4. Wait for crawl to finish (or hit `php artisan queue:work --queue=crawls` manually if not running).
5. Visit `/knowledge-base` — confirm webpage items exist with `crawl_session_id` metadata.
6. Visit `/widget-settings` — confirm Website indexing section displays last crawl summary.
7. Delete a webpage item with "Don't re-create" checked — verify `crawl_url_blocklist` row exists.
8. Run `php artisan crawls:refresh-all` manually — confirm only the right tenants are dispatched.

- [ ] **Step 9: Open PR**

```bash
gh pr create --title "Registration wizard + website auto-indexing" --body "$(cat <<'EOF'
## Summary
- Converted registration to a 3-step wizard (Account → Company → optional Website)
- Built sitemap-first, BFS-fallback site crawler (≤100 pages, depth 3, robots-strict, polite)
- Daily `crawls:refresh-all` scheduler with diff-only re-fetch via Last-Modified/ETag/content-hash
- Per-page KnowledgeItem (type=webpage) keyed on new indexed `url_normalized` column
- Tenant-scoped `crawl_url_blocklist` so deleted pages stay deleted across refreshes
- Budget-gated via `UsageTracker::canRecordUsage` for KB items + tokens
- New `WebsiteIndexingController` settings + KB filter chip + dashboard banner

## Deploy steps
1. Run migrations (4 new): `php artisan migrate`
2. Ensure Redis queue worker handles `crawls` queue: `php artisan queue:work --queue=crawls,default`
3. Verify scheduler runs `crawls:refresh-all` daily

## ⚠️ Behavior changes
- Registration now has 3 steps. Existing form-fill scripts that POST `/register` still work (no breaking API changes — `website_url` is optional).
- Tenants who set a website URL will see daily background crawl traffic to their site.

## Test plan
- [ ] Full PHPUnit suite passes
- [ ] Pint clean
- [ ] PHPStan baseline zero
- [ ] Browser smoke: end-to-end registration → crawl → KB filter → settings → manual recrawl

## Architecture notes
Spec: `docs/superpowers/specs/2026-05-18-registration-wizard-and-site-scraping-design.md`
Plan: `docs/superpowers/plans/2026-05-18-registration-wizard-and-site-scraping.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Memory update (post-merge)

After PR merges to main, save a memory entry:

```markdown
- [Registration wizard + site crawler shipped](registration_wizard_site_crawler.md) — PR #N merged YYYY-MM-DD; 3-step wizard, sitemap-first crawler, daily refresh; key invariants: url_normalized indexed column on knowledge_items, crawl_url_blocklist persists deletions, CrawlWebsiteJob is NotTenantAware on crawls queue, trial cap 10 surfaces as Partial.
```

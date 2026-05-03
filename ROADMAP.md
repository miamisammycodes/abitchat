# Chatbot SaaS - Development Roadmap

> **Development Workflow**: Always follow "Make it work → Make it right → Make it fast"
> **Rules Reference**: See `CLAUDE.md` for coding standards, debugging rules, and conventions

---

## Quick Reference

### Before Each Task
- [ ] Read relevant section in `CLAUDE.md`
- [ ] Check `prd.md` for requirements
- [ ] State current step: "Step: Make it work/right/fast"

### After Each Task
- [ ] Run `php artisan test`
- [ ] Run `npm run build` (if frontend changes)
- [ ] Verify in browser (if UI changes)
- [ ] Check Laravel Debugbar for issues

### Debug Logging Convention
```php
// Billed external calls
Log::debug('[ServiceName] (IS $) Description', ['data' => $value]);

// Free local operations
Log::debug('[ServiceName] (NO $) Description', ['data' => $value]);
```

---

## Phase 1: Foundation (COMPLETE)

### M1.1 ✅ Create Laravel 12 Project
- **Status**: Complete
- **Location**: `/Users/sam/Dev/laravel/chatbot`

### M1.2 ✅ Install VILT Stack
- **Status**: Complete
- **Packages**: Vue 3, Inertia.js, Tailwind CSS v4

### M1.3 ✅ Install Core Packages
- **Status**: Complete
- **Packages**:
  - `spatie/laravel-multitenancy`
  - `prism-php/prism`
  - `laravel/cashier`
  - `barryvdh/laravel-debugbar`

### M1.5 ✅ Install Code Quality Tools
- **Status**: Complete
- **Packages**:
  - `larastan/larastan` - Static analysis
  - `laravel/pint` - Code formatting
- **Config Files**: `phpstan.neon`, `pint.json`
- **Commands**:
  - `./vendor/bin/pint` - Format code
  - `./vendor/bin/phpstan analyse` - Run static analysis

### M1.4 ✅ Create Rules Files
- **Status**: Complete
- **Files**: `CLAUDE.md`, `prd.md`, `ROADMAP.md`

---

## Phase 2: Database & Multi-Tenancy (COMPLETE)

### M2.1 ✅ Publish Spatie Multitenancy Config
**Status**: Complete

**Subtasks**:
1. Publish config file
2. Configure tenant model
3. Set up tenant-aware middleware

**Commands**:
```bash
php artisan vendor:publish --provider="Spatie\Multitenancy\MultitenancyServiceProvider"
```

**Files to Modify**:
- `config/multitenancy.php`
- `bootstrap/app.php`

**Testing**:
```bash
php artisan config:clear
php artisan test
```

**Debug Checklist**:
- [ ] Config file exists at `config/multitenancy.php`
- [ ] No errors on `php artisan config:cache`

**Rules Reference**: `CLAUDE.md` → Architecture → Multi-Tenancy Pattern

---

### M2.2 ✅ Create Tenant Migration & Model
**Status**: Complete

**Subtasks**:
1. Create migration for `tenants` table
2. Create Tenant model
3. Add tenant identification columns

**Commands**:
```bash
php artisan make:model Tenant -m
```

**Migration Schema**:
```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('domain')->nullable()->unique();
    $table->string('api_key', 64)->unique();
    $table->enum('plan', ['starter', 'business', 'enterprise'])->default('starter');
    $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
    $table->json('settings')->nullable();
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

**Files to Create**:
- `database/migrations/xxxx_create_tenants_table.php`
- `app/Models/Tenant.php`

**Testing**:
```bash
php artisan migrate
php artisan tinker
# > Tenant::create(['name' => 'Test', 'slug' => 'test', 'api_key' => Str::random(64)])
php artisan test
```

**Debug Checklist**:
- [ ] Migration runs without errors
- [ ] Can create tenant in tinker
- [ ] `api_key` is unique and 64 chars

**Rules Reference**: `CLAUDE.md` → Database Conventions

---

### M2.3 ✅ Update Users Table for Multi-Tenancy
**Status**: Complete

**Subtasks**:
1. Add `tenant_id` foreign key to users
2. Add role column
3. Update User model with tenant relationship

**Commands**:
```bash
php artisan make:migration add_tenant_id_to_users_table
```

**Migration Schema**:
```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['owner', 'manager', 'agent'])->default('agent')->after('email');
    $table->index('tenant_id');
});
```

**Files to Modify**:
- `database/migrations/xxxx_add_tenant_id_to_users_table.php`
- `app/Models/User.php`

**User Model Updates**:
```php
// Add to User.php
protected $fillable = ['name', 'email', 'password', 'tenant_id', 'role'];

public function tenant(): BelongsTo
{
    return $this->belongsTo(Tenant::class);
}
```

**Testing**:
```bash
php artisan migrate
php artisan test
```

**Debug Checklist**:
- [ ] Foreign key constraint works
- [ ] User belongs to tenant
- [ ] Role enum accepts valid values only

**Rules Reference**: `CLAUDE.md` → Database Conventions → Foreign Keys

---

### M2.4 ✅ Create Admin Users Table
**Status**: Complete

**Subtasks**:
1. Create migration for `admin_users` table
2. Create AdminUser model
3. Configure separate auth guard

**Commands**:
```bash
php artisan make:model AdminUser -m
```

**Migration Schema**:
```php
Schema::create('admin_users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->enum('role', ['super_admin', 'admin', 'support'])->default('admin');
    $table->rememberToken();
    $table->timestamps();
});
```

**Files to Create**:
- `database/migrations/xxxx_create_admin_users_table.php`
- `app/Models/AdminUser.php`

**Files to Modify**:
- `config/auth.php` (add admin guard)

**Testing**:
```bash
php artisan migrate
php artisan tinker
# > AdminUser::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'super_admin'])
```

**Debug Checklist**:
- [ ] Admin guard configured in `config/auth.php`
- [ ] Can create admin user
- [ ] Password is hashed (not plaintext)

**Rules Reference**: `CLAUDE.md` → Security Requirements

---

### M2.5 ✅ Create Core Domain Migrations
**Status**: Complete

**Subtasks**:
1. Create `conversations` table
2. Create `messages` table
3. Create `leads` table
4. Create `knowledge_items` table
5. Create `knowledge_chunks` table
6. Create `usage_records` table

**Commands**:
```bash
php artisan make:model Conversation -m
php artisan make:model Message -m
php artisan make:model Lead -m
php artisan make:model KnowledgeItem -m
php artisan make:model KnowledgeChunk -m
php artisan make:model UsageRecord -m
```

**Migration Schemas**:

**conversations** (as actually shipped — the original example here listed `visitor_id`/`started_at`/`ended_at`/`lead_score`, but those never made it into the real migration):
```php
Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
    $table->string('session_id', 64)->index();
    $table->enum('status', ['active', 'closed', 'archived'])->default('active');
    $table->json('metadata')->nullable(); // user_agent, ip, started_at (ISO string)
    $table->timestamps();
    $table->index(['tenant_id', 'session_id']);
    $table->index(['tenant_id', 'status']);
});
```

**messages**:
```php
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
    $table->enum('role', ['visitor', 'assistant']);
    $table->text('content');
    $table->integer('tokens_used')->default(0);
    $table->float('confidence_score')->nullable();
    $table->timestamps();
    $table->index('conversation_id');
});
```

**leads**:
```php
Schema::create('leads', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name')->nullable();
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->integer('score')->default(0);
    $table->enum('status', ['new', 'contacted', 'qualified', 'converted', 'lost'])->default('new');
    $table->json('custom_fields')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'status']);
    $table->index(['tenant_id', 'score']);
    $table->index(['tenant_id', 'email']);
});
```

**knowledge_items**:
```php
Schema::create('knowledge_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->enum('type', ['document', 'faq', 'webpage', 'text']);
    $table->string('title');
    $table->string('source_url')->nullable();
    $table->longText('content')->nullable();
    $table->string('content_hash', 64)->nullable();
    $table->enum('status', ['processing', 'active', 'failed'])->default('processing');
    $table->text('error_message')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'type']);
    $table->index(['tenant_id', 'status']);
});
```

**knowledge_chunks**:
```php
Schema::create('knowledge_chunks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
    $table->text('content');
    $table->binary('embedding')->nullable(); // Postgres pgvector column added in later migration
    $table->integer('chunk_index')->default(0);
    $table->timestamps();
    $table->index('knowledge_item_id');
});
```

**usage_records**:
```php
Schema::create('usage_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('type', ['tokens', 'conversations', 'documents']);
    $table->integer('quantity');
    $table->string('period', 7); // YYYY-MM format
    $table->timestamps();
    $table->index(['tenant_id', 'type', 'period']);
});
```

**Testing**:
```bash
php artisan migrate
php artisan migrate:status
php artisan test
```

**Debug Checklist**:
- [ ] All migrations run successfully
- [ ] Foreign key constraints are correct
- [ ] Indexes created on frequently queried columns

**Rules Reference**: `CLAUDE.md` → Database Conventions → Tables

---

## Phase 3: Authentication (COMPLETE)

### M3.1 ✅ Create Tenant Registration
**Status**: Complete

**Subtasks**:
1. Create RegisterController for tenants
2. Create registration form (Vue)
3. Create tenant + owner user on registration
4. Generate API key
5. Send verification email

**Commands**:
```bash
php artisan make:controller Auth/RegisterController
php artisan make:request Auth/RegisterRequest
```

**Files to Create**:
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Requests/Auth/RegisterRequest.php`
- `resources/js/Pages/Auth/Register.vue`

**Files to Modify**:
- `routes/web.php`

**RegisterRequest Validation**:
```php
public function rules(): array
{
    return [
        'company_name' => ['required', 'string', 'max:255'],
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ];
}
```

**Testing**:
```bash
php artisan test
# Manual: Visit /register, fill form, verify tenant + user created
```

**Debug Checklist**:
```php
Log::debug('[Register] (NO $) Creating tenant', ['company' => $request->company_name]);
Log::debug('[Register] (NO $) Creating owner user', ['email' => $request->email]);
```
- [ ] Tenant created with unique slug
- [ ] User created with role='owner'
- [ ] User has tenant_id set
- [ ] API key generated (64 chars)

**Rules Reference**: `CLAUDE.md` → Security Requirements

---

### M3.2 ✅ Create Tenant Login/Logout
**Status**: Complete

**Subtasks**:
1. Create LoginController
2. Create login form (Vue)
3. Set tenant context on login
4. Implement logout

**Commands**:
```bash
php artisan make:controller Auth/LoginController
php artisan make:request Auth/LoginRequest
```

**Files to Create**:
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `resources/js/Pages/Auth/Login.vue`

**Testing**:
```bash
php artisan test
# Manual:
# 1. Login with valid credentials → should redirect to /dashboard
# 2. Login with invalid credentials → should show error
# 3. Logout → should redirect to /
```

**Debug Checklist**:
```php
Log::debug('[Auth] (NO $) Login attempt', ['email' => $request->email]);
Log::debug('[Auth] (NO $) Login success', ['user_id' => $user->id, 'tenant_id' => $user->tenant_id]);
```
- [ ] Session contains user
- [ ] Tenant context is set
- [ ] Remember me works

**Rules Reference**: `CLAUDE.md` → Security Requirements

---

### M3.3 ✅ Create Admin Authentication
**Status**: Complete

**Subtasks**:
1. Configure admin guard in `config/auth.php`
2. Create AdminLoginController
3. Create admin login form (Vue)
4. Create admin middleware

**Commands**:
```bash
php artisan make:controller Admin/Auth/LoginController
php artisan make:middleware AdminAuthenticate
```

**Files to Create**:
- `app/Http/Controllers/Admin/Auth/LoginController.php`
- `app/Http/Middleware/AdminAuthenticate.php`
- `resources/js/Pages/Admin/Auth/Login.vue`

**Files to Modify**:
- `config/auth.php`
- `bootstrap/app.php`

**Auth Config Addition**:
```php
'guards' => [
    // ... existing guards
    'admin' => [
        'driver' => 'session',
        'provider' => 'admin_users',
    ],
],

'providers' => [
    // ... existing providers
    'admin_users' => [
        'driver' => 'eloquent',
        'model' => App\Models\AdminUser::class,
    ],
],
```

**Testing**:
```bash
php artisan test
# Manual: Visit /admin/login, login as admin
```

**Debug Checklist**:
- [ ] Admin guard works separately from user guard
- [ ] Admin middleware protects admin routes
- [ ] Admin session is separate from user session

**Rules Reference**: `CLAUDE.md` → Architecture → Route Structure

---

### M3.4 ✅ Create Password Reset Flow
**Status**: Complete

**Subtasks**:
1. Create ForgotPasswordController
2. Create ResetPasswordController
3. Create forgot password form (Vue)
4. Create reset password form (Vue)
5. Configure mail

**Commands**:
```bash
php artisan make:controller Auth/ForgotPasswordController
php artisan make:controller Auth/ResetPasswordController
```

**Files to Create**:
- `app/Http/Controllers/Auth/ForgotPasswordController.php`
- `app/Http/Controllers/Auth/ResetPasswordController.php`
- `resources/js/Pages/Auth/ForgotPassword.vue`
- `resources/js/Pages/Auth/ResetPassword.vue`

**Testing**:
```bash
php artisan test
# Manual: Request reset, check email (use Mailpit/Mailtrap), reset password
```

**Debug Checklist**:
```php
Log::debug('[PasswordReset] (NO $) Reset requested', ['email' => $email]);
Log::debug('[PasswordReset] (NO $) Reset completed', ['user_id' => $user->id]);
```
- [ ] Reset email is sent
- [ ] Token expires after use
- [ ] Old password no longer works

**Rules Reference**: `CLAUDE.md` → Security Requirements

---

## Phase 4: LLM Integration (COMPLETE)

### M4.1 ✅ Configure Prism
**Status**: Complete

**Subtasks**:
1. Publish Prism config
2. Configure Ollama for development
3. Configure Groq for production
4. Create LLM service class

**Commands**:
```bash
php artisan vendor:publish --provider="Prism\Prism\PrismServiceProvider"
```

**Files to Create**:
- `app/Services/LLM/ChatService.php`

**Files to Modify**:
- `config/prism.php`

**ChatService Example**:
```php
<?php

declare(strict_types=1);

namespace App\Services\LLM;

use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function generateResponse(string $systemPrompt, string $userMessage): array
    {
        $provider = config('app.env') === 'production'
            ? Provider::Groq
            : Provider::Ollama;

        $model = config('app.env') === 'production'
            ? 'llama-3.1-8b-instant'
            : config('services.ollama.model', 'gemma3:4b');

        Log::debug('[LLM] (IS $) Sending prompt', [
            'provider' => $provider->value,
            'model' => $model,
            'prompt_length' => strlen($userMessage),
        ]);

        $response = Prism::text()
            ->using($provider, $model)
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($userMessage)
            ->asText();

        Log::debug('[LLM] (IS $) Response received', [
            'tokens' => $response->usage->totalTokens,
        ]);

        return [
            'text' => $response->text,
            'tokens' => $response->usage->totalTokens,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ];
    }
}
```

**Testing**:
```bash
php artisan tinker
# > app(App\Services\LLM\ChatService::class)->generateResponse('You are helpful.', 'Hello!')
```

**Debug Checklist**:
- [ ] Ollama responds locally
- [ ] Token counts are returned
- [ ] Logs show `(IS $)` for LLM calls

**Rules Reference**: `CLAUDE.md` → LLM Integration (Prism)

---

### M4.2 ✅ Implement Streaming Responses
**Status**: Complete

**Subtasks**:
1. Create streaming endpoint
2. Handle SSE in controller
3. Create Vue component for streaming display

**Files to Create**:
- `app/Http/Controllers/Api/V1/Widget/ChatController.php`
- `resources/js/Components/StreamingMessage.vue`

**Controller Example**:
```php
public function stream(Request $request)
{
    Log::debug('[Chat] (IS $) Starting stream', ['conversation_id' => $request->conversation_id]);

    return Prism::text()
        ->using($this->getProvider(), $this->getModel())
        ->withSystemPrompt($this->buildSystemPrompt())
        ->withPrompt($request->message)
        ->asEventStreamResponse();
}
```

**Testing**:
```bash
# Use curl to test SSE endpoint
curl -N http://localhost:8000/api/v1/widget/chat/stream?message=Hello
```

**Debug Checklist**:
- [ ] SSE events are sent correctly
- [ ] Stream can be cancelled
- [ ] Token usage tracked after stream completes

**Rules Reference**: `CLAUDE.md` → LLM Integration (Prism)

---

### M4.3 ✅ Create Token Usage Tracking
**Status**: Complete

**Subtasks**:
1. Create UsageTracker service
2. Track tokens per conversation
3. Track tokens per tenant/period
4. Add usage to Conversation/Message models

**Files to Create**:
- `app/Services/UsageTrackerService.php`

**Service Example**:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UsageRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class UsageTrackerService
{
    public function trackTokens(Tenant $tenant, int $tokens, ?int $conversationId = null): void
    {
        Log::debug('[Usage] (NO $) Tracking tokens', [
            'tenant_id' => $tenant->id,
            'tokens' => $tokens,
        ]);

        UsageRecord::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'type' => 'tokens',
            'quantity' => $tokens,
            'period' => now()->format('Y-m'),
        ]);
    }

    public function getMonthlyUsage(Tenant $tenant, string $type = 'tokens'): int
    {
        return UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', $type)
            ->where('period', now()->format('Y-m'))
            ->sum('quantity');
    }
}
```

**Testing**:
```bash
php artisan test
php artisan tinker
# > $tenant = Tenant::first()
# > app(UsageTrackerService::class)->trackTokens($tenant, 100)
# > app(UsageTrackerService::class)->getMonthlyUsage($tenant)
```

**Debug Checklist**:
- [ ] Usage records created correctly
- [ ] Monthly aggregation works
- [ ] Conversation ID linked when available

**Rules Reference**: `CLAUDE.md` → LLM Integration → Token Tracking

---

## Phase 5: Knowledge Base (COMPLETE)

### M5.1 ✅ Create Document Upload
**Status**: Complete

**Subtasks**:
1. Create upload controller
2. Handle file validation (PDF, DOCX, TXT, MD)
3. Store files
4. Queue processing job

**Commands**:
```bash
php artisan make:controller Client/KnowledgeController
php artisan make:request Client/UploadDocumentRequest
php artisan make:job ProcessKnowledgeDocument
```

**Files to Create**:
- `app/Http/Controllers/Client/KnowledgeController.php`
- `app/Http/Requests/Client/UploadDocumentRequest.php`
- `app/Jobs/ProcessKnowledgeDocument.php`
- `resources/js/Pages/Client/Knowledge/Index.vue`
- `resources/js/Pages/Client/Knowledge/Upload.vue`

**Testing**:
```bash
php artisan test
php artisan queue:work --once
# Manual: Upload a PDF, check it appears in list with "processing" status
```

**Debug Checklist**:
```php
Log::debug('[Knowledge] (NO $) Document uploaded', [
    'tenant_id' => $tenant->id,
    'filename' => $file->getClientOriginalName(),
    'size' => $file->getSize(),
]);
Log::debug('[Knowledge] (NO $) Processing job dispatched', ['knowledge_item_id' => $item->id]);
```
- [ ] File stored in correct location
- [ ] KnowledgeItem record created
- [ ] Job dispatched to queue

**Rules Reference**: `CLAUDE.md` → Coding Standards → PHP

---

### M5.2 ✅ Create Text Extraction Service
**Status**: Complete

**Subtasks**:
1. Create TextExtractor service
2. Implement PDF extraction (using Smalot/PdfParser)
3. Implement DOCX extraction (using PhpWord)
4. Handle TXT/MD directly

**Commands**:
```bash
composer require smalot/pdfparser
composer require phpoffice/phpword
```

**Files to Create**:
- `app/Services/Knowledge/TextExtractorService.php`

**Testing**:
```bash
php artisan test
php artisan tinker
# Test with sample files
```

**Debug Checklist**:
```php
Log::debug('[TextExtractor] (NO $) Extracting text', [
    'type' => $fileType,
    'path' => $filePath,
]);
Log::debug('[TextExtractor] (NO $) Extraction complete', [
    'chars' => strlen($text),
]);
```
- [ ] PDF text extracted correctly
- [ ] DOCX text extracted correctly
- [ ] Special characters handled

**Rules Reference**: `CLAUDE.md` → Development Workflow

---

### M5.3 ✅ Create Content Chunking Service
**Status**: Complete

**Subtasks**:
1. Create ChunkingService
2. Implement semantic chunking (by paragraphs/sections)
3. Handle chunk overlap for context
4. Store chunks in database

**Files to Create**:
- `app/Services/Knowledge/ChunkingService.php`

**Service Example**:
```php
public function chunkText(string $text, int $maxChunkSize = 500, int $overlap = 50): array
{
    Log::debug('[Chunking] (NO $) Starting chunking', [
        'text_length' => strlen($text),
        'max_chunk_size' => $maxChunkSize,
    ]);

    // Implementation...

    Log::debug('[Chunking] (NO $) Chunking complete', [
        'chunks_created' => count($chunks),
    ]);

    return $chunks;
}
```

**Testing**:
```bash
php artisan test
```

**Debug Checklist**:
- [ ] Chunks don't exceed max size
- [ ] Overlap preserved between chunks
- [ ] No content lost

**Rules Reference**: `CLAUDE.md` → Development Workflow

---

### M5.4 ✅ Set Up Embeddings Service
**Status**: Complete (Postgres + pgvector; embeddings via Ollama `nomic-embed-text`)

**Subtasks**:
1. Enable pgvector extension on Postgres
2. Create embedding service
3. Store embeddings as `vector` column on `knowledge_chunks`
4. Implement cosine-distance similarity search (with keyword `ILIKE` fallback)

**Commands**:
```bash
# Postgres extension (enabled by migration 2025_11_27_000000_enable_pgvector_extension)
psql -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

**Files**:
- `app/Services/Knowledge/EmbeddingService.php`
- `app/Services/Knowledge/RetrievalService.php` (cosine + keyword fallback)
- `database/migrations/2025_11_27_000000_enable_pgvector_extension.php`

**Testing**:
```bash
php artisan test
php artisan tinker
# Test vector similarity search
```

**Debug Checklist**:
```php
Log::debug('[Embedding] (IS $) Generating embedding', ['chunk_id' => $chunk->id]);
Log::debug('[VectorSearch] (NO $) Searching vectors', ['query_length' => strlen($query)]);
```
- [ ] Embeddings generated and stored
- [ ] Similarity search returns relevant chunks
- [ ] Performance acceptable (<500ms)

**Rules Reference**: `CLAUDE.md` → LLM Integration

---

### M5.5 ✅ Create RAG Retrieval Service
**Status**: Complete

**Subtasks**:
1. Create RAGService
2. Combine vector search with LLM
3. Include source attribution
4. Handle confidence scoring

**Files to Create**:
- `app/Services/Knowledge/RAGService.php`

**Service Example**:
```php
public function generateAnswer(Tenant $tenant, string $question): array
{
    Log::debug('[RAG] (NO $) Starting RAG pipeline', [
        'tenant_id' => $tenant->id,
        'question' => $question,
    ]);

    // 1. Vector search for relevant chunks
    $chunks = $this->vectorSearch->search($tenant, $question, limit: 5);

    Log::debug('[RAG] (NO $) Retrieved chunks', ['count' => count($chunks)]);

    // 2. Build context from chunks
    $context = $this->buildContext($chunks);

    // 3. Generate answer with LLM
    Log::debug('[RAG] (IS $) Calling LLM with context');
    $response = $this->chatService->generateResponse(
        $this->buildSystemPrompt($context),
        $question
    );

    return [
        'answer' => $response['text'],
        'sources' => $chunks->pluck('knowledge_item_id')->unique(),
        'tokens' => $response['tokens'],
        'confidence' => $this->calculateConfidence($response),
    ];
}
```

**Testing**:
```bash
php artisan test
# Add test knowledge, then query
```

**Debug Checklist**:
- [ ] Relevant chunks retrieved
- [ ] Context properly formatted
- [ ] Sources correctly attributed
- [ ] Confidence score reasonable

**Rules Reference**: `CLAUDE.md` → LLM Integration

---

## Phase 6: Widget API (COMPLETE)

### M6.1 ✅ Create Widget Init Endpoint
**Status**: Complete

**Subtasks**:
1. Validate API key
2. Return widget configuration
3. Track widget load

**Files to Create**:
- `app/Http/Controllers/Api/V1/Widget/InitController.php`
- `app/Http/Middleware/ValidateWidgetApiKey.php`

**Testing**:
```bash
curl -H "X-API-Key: your-api-key" http://localhost:8000/api/v1/widget/init
```

**Debug Checklist**:
```php
Log::debug('[Widget] (NO $) Init request', ['api_key' => substr($apiKey, 0, 8) . '...']);
Log::debug('[Widget] (NO $) Tenant found', ['tenant_id' => $tenant->id]);
```
- [ ] Invalid API key returns 401
- [ ] Valid API key returns config
- [ ] Widget settings included

**Rules Reference**: `CLAUDE.md` → Security Requirements

---

### M6.2 ✅ Create Conversation Endpoint
**Status**: Complete

**Subtasks**:
1. Start new conversation
2. Generate visitor ID
3. Return conversation ID

**Files to Create**:
- `app/Http/Controllers/Api/V1/Widget/ConversationController.php`

**Testing**:
```bash
curl -X POST -H "X-API-Key: your-api-key" http://localhost:8000/api/v1/widget/conversations
```

**Debug Checklist**:
```php
Log::debug('[Widget] (NO $) New conversation', [
    'tenant_id' => $tenant->id,
    'visitor_id' => $visitorId,
]);
```
- [ ] Conversation created
- [ ] Visitor ID generated
- [ ] Metadata captured

**Rules Reference**: `CLAUDE.md` → Architecture

---

### M6.3 ✅ Create Message Endpoint
**Status**: Complete

**Subtasks**:
1. Receive visitor message
2. Call RAG service
3. Return assistant response
4. Track tokens

**Files to Create**:
- `app/Http/Controllers/Api/V1/Widget/MessageController.php`

**Testing**:
```bash
curl -X POST -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"conversation_id": 1, "message": "Hello"}' \
  http://localhost:8000/api/v1/widget/messages
```

**Debug Checklist**:
```php
Log::debug('[Widget] (NO $) Message received', [
    'conversation_id' => $conversationId,
    'message_length' => strlen($message),
]);
Log::debug('[Widget] (IS $) RAG response generated', [
    'tokens' => $tokens,
    'confidence' => $confidence,
]);
```
- [ ] Message stored
- [ ] Response generated via RAG
- [ ] Tokens tracked

**Rules Reference**: `CLAUDE.md` → LLM Integration

---

## Phase 7: Lead Capture (COMPLETE)

### M7.1 ✅ Implement Lead Scoring
**Status**: Complete

**Subtasks**:
1. Create LeadScoringService
2. Implement scoring rules from PRD
3. Update score during conversation
4. Track scoring signals

**Files to Create**:
- `app/Services/Leads/LeadScoringService.php`

**Scoring Rules (from PRD)**:
| Signal | Score Impact |
|--------|--------------|
| Provided email | +20 |
| Provided phone | +15 |
| Provided name | +10 |
| Asked about pricing | +25 |
| Asked about demo/trial | +30 |
| Multiple sessions | +10 |
| High engagement (>5 messages) | +15 |
| Mentioned competitor | +20 |
| Mentioned timeline/urgency | +25 |
| Negative sentiment detected | -10 |

**Testing**:
```bash
php artisan test
```

**Debug Checklist**:
```php
Log::debug('[LeadScoring] (NO $) Scoring conversation', [
    'conversation_id' => $conversation->id,
    'signals' => $signals,
    'score' => $score,
]);
```
- [ ] Score calculated correctly
- [ ] Signals detected from message content
- [ ] Score categories (Cold/Warm/Hot) correct

**Rules Reference**: `CLAUDE.md` → Development Workflow

---

### M7.2 ✅ Create Lead Capture Flow
**Status**: Complete

**Subtasks**:
1. Detect lead capture opportunities
2. Prompt for contact info naturally
3. Validate and store lead data
4. Detect duplicates by email

**Files to Create**:
- `app/Services/Leads/LeadCaptureService.php`

**Testing**:
```bash
php artisan test
# Manual: Have conversation, provide email, verify lead created
```

**Debug Checklist**:
```php
Log::debug('[LeadCapture] (NO $) Capture triggered', [
    'conversation_id' => $conversation->id,
    'trigger' => $trigger,
]);
Log::debug('[LeadCapture] (NO $) Lead created', [
    'lead_id' => $lead->id,
    'email' => $lead->email,
]);
```
- [ ] Lead created from conversation
- [ ] Duplicate detection works
- [ ] Score transferred to lead

**Rules Reference**: `CLAUDE.md` → Database Conventions

---

## Phase 8: Client Dashboard (COMPLETE)

### M8.1 ✅ Create Dashboard Layout
**Status**: Complete

**Subtasks**:
1. Create authenticated layout
2. Create sidebar navigation
3. Create header with user info
4. Set up tenant context

**Files to Create**:
- `resources/js/Layouts/ClientLayout.vue`
- `resources/js/Components/Sidebar.vue`
- `resources/js/Components/Header.vue`

**Testing**:
```bash
npm run build
# Manual: Login and verify layout renders
```

**Debug Checklist**:
- [ ] Layout renders correctly
- [ ] Navigation works
- [ ] Tenant name displayed
- [ ] User info shown

**Rules Reference**: `CLAUDE.md` → Vue 3 (Composition API)

---

### M8.2 ✅ Create Dashboard Home
**Status**: Complete

**Subtasks**:
1. Create DashboardController
2. Fetch key stats (conversations, leads, tokens)
3. Display recent activity
4. Show alerts/notifications

**Files to Create**:
- `app/Http/Controllers/Client/DashboardController.php`
- `resources/js/Pages/Client/Dashboard.vue`
- `resources/js/Components/StatsCard.vue`

**Testing**:
```bash
php artisan test
npm run build
# Manual: Verify stats display correctly
```

**Debug Checklist**:
```php
Log::debug('[Dashboard] (NO $) Loading stats', ['tenant_id' => $tenant->id]);
```
- [ ] Stats accurate
- [ ] Recent conversations shown
- [ ] Token usage displayed

**Rules Reference**: `CLAUDE.md` → Vue 3 (Composition API)

---

### M8.3-M8.9 Dashboard Pages
**Status**: Partial

**Pages Implemented**:
- M8.3: ❌ Conversation list & detail — **not built** (no controller, route, or Vue page)
- M8.4: ✅ Lead management (`/leads`)
- M8.5: ✅ Knowledge base management (`/knowledge`)
- M8.6: ⚠️ Chatbot settings — folded into Widget settings + Bot Personality (Phase 10), no standalone page
- M8.7: ✅ Widget customization & preview (`/widget-settings`)
- M8.8: ✅ Analytics (`/analytics`)
- M8.9: ❌ Team management — **not built**

---

## Phase 9: Admin Dashboard (PARTIAL)

### M9.1-M9.8 Admin Pages
**Status**: Partial

**Pages Implemented**:
- M9.1: ✅ Platform stats overview (`/admin/dashboard`)
- M9.2: ✅ Client list & management (`/admin/clients`, `/admin/clients/{client}`)
- M9.3: ❌ Impersonate client — **not built** (no impersonation route or controller method)
- M9.4: ⚠️ Token usage monitoring — surfaced on the admin Dashboard stats only; no dedicated page
- M9.5: ❌ System health — **not built**
- M9.6: ❌ Failed jobs — **not built**
- M9.7: ❌ Broadcasts — **not built**
- M9.8: ✅ Activity logs (`/admin/logs`, `ActivityLogController`)

**Also shipped in Phase 11 (admin side, not originally listed in Phase 9):**
- ✅ Plans CRUD (`/admin/plans`)
- ✅ Transactions review/approve/reject (`/admin/transactions`)
- ✅ Enterprise inquiries (`/admin/inquiries`)

---

## Phase 10: Bot Personality (COMPLETE)

### M10.1 ✅ Bot Type Selection
- **Status**: Complete
- **Types**: Support, Sales, Information, Hybrid (default)
- **DB Column**: `tenants.bot_type`

### M10.2 ✅ Bot Tone Selection
- **Status**: Complete
- **Tones**: Formal, Friendly (default), Casual
- **DB Column**: `tenants.bot_tone`

### M10.3 ✅ Custom Instructions
- **Status**: Complete
- **Feature**: Optional text field for additional bot behavior
- **DB Column**: `tenants.bot_custom_instructions`

### M10.4 ✅ Dynamic System Prompts
- **Status**: Complete
- **File**: `app/Services/LLM/ChatService.php`
- **Methods**: `getBotTypePrompt()`, `getToneModifier()`

---

## Phase 10.5: UI/UX Enhancements (COMPLETE)

### M10.5.1 ✅ Dark/Light Theme Toggle
- **Status**: Complete
- **Features**:
  - Theme toggle button in header (sun/moon icons)
  - localStorage persistence
  - System preference detection
  - CSS variable-based theming
- **Files**:
  - `resources/js/composables/useTheme.js`
  - `resources/js/Layouts/ClientLayout.vue`
  - `resources/js/Layouts/AdminLayout.vue`
  - All Admin pages updated with theme-aware CSS classes

### M10.5.2 ✅ Currency Localization (Bhutanese Ngultrum)
- **Status**: Complete
- **Currency**: Nu. (Bhutanese Ngultrum)
- **Format**: Indian number format (en-IN)
- **Files Updated**:
  - Admin/Dashboard.vue
  - Admin/Clients/Index.vue
  - Admin/Clients/Show.vue
  - Admin/Transactions/Index.vue
  - Client/Billing/Index.vue
  - Client/Billing/Plans.vue
  - Client/Billing/Subscribe.vue
  - Client/Billing/Transactions.vue

### M10.5.3 ✅ Navigation Fixes
- **Status**: Complete
- **Issue**: ClientLayout.vue had incorrect navigation paths causing 404 errors
- **Fix**: Updated navigation array to match actual route definitions:
  - `/dashboard` → Dashboard
  - `/widget-settings` → Widget
  - `/knowledge` → Knowledge Base
  - `/leads` → Leads
  - `/analytics` → Analytics
  - `/billing` → Billing

---

## Phase 11: Billing (COMPLETE)

### M11.0 ✅ Free Trial on Registration
**Status**: Complete

**Implementation**:
- New users automatically receive a 14-day free trial on registration
- `trial_ends_at` is set to `now()->addDays(14)` in `RegisterController`
- Trial status checked via `Tenant::isOnTrial()` method
- Manual payment/transaction system remains in place (no Stripe)

**Files Modified**:
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Middleware/CheckUsageLimits.php`

**How It Works**:
1. User registers → tenant created with `trial_ends_at = now + 14 days`
2. During trial, user can use the platform
3. After trial expires, user must subscribe to a plan
4. Current subscription flow: submit payment → admin approval → plan activated

---

### M11.1 ✅ Plan Structure & Management
**Status**: Complete

**Plan Tiers**:
| Plan | Price | Tokens | Conversations | Knowledge Items | Leads |
|------|-------|--------|---------------|-----------------|-------|
| Free Trial | Nu. 0 | 50K | 100 | 10 | 50 |
| Starter | Nu. 5,000/mo | 500K | 1,000 | 100 | 500 |
| Business | Nu. 7,000/mo | 2M | Unlimited | Unlimited | Unlimited |
| Enterprise | Contact Us | Custom | Custom | Custom | Custom |

**Admin Plan Management**:
- Full CRUD for subscription plans
- Toggle plan active/inactive status
- Set `is_contact_sales` flag for Enterprise-type plans

**Files Created**:
- `app/Http/Controllers/Admin/PlanController.php`
- `app/Http/Requests/Admin/StorePlanRequest.php`
- `app/Http/Requests/Admin/UpdatePlanRequest.php`
- `resources/js/Pages/Admin/Plans/Index.vue`
- `resources/js/Pages/Admin/Plans/Create.vue`
- `resources/js/Pages/Admin/Plans/Edit.vue`

**Database Changes**:
- Added `is_contact_sales` boolean to `plans` table

---

### M11.2 ✅ PDF Receipt Generation
**Status**: Complete

**Implementation**:
- Generate downloadable PDF receipts for approved transactions
- Uses `barryvdh/laravel-dompdf` package
- Receipt includes: transaction details, plan info, payment method, dates

**Files Created**:
- `app/Services/Billing/ReceiptService.php`
- `resources/views/pdf/receipt.blade.php`

**Files Modified**:
- `app/Http/Controllers/Client/BillingController.php` (added `downloadReceipt()`)
- `resources/js/Pages/Client/Billing/Transactions.vue` (added download button)

**Route**: `GET /billing/transactions/{transaction}/receipt`

---

### M11.3 ✅ Enterprise Contact Form
**Status**: Complete

**Implementation**:
- Modal contact form for Enterprise plan inquiries
- Stores inquiries in database for admin review
- Email notification to admin on new inquiry

**Files Created**:
- `app/Models/EnterpriseInquiry.php`
- `app/Http/Controllers/Client/EnterpriseInquiryController.php`
- `app/Http/Controllers/Admin/EnterpriseInquiryController.php`
- `app/Notifications/EnterpriseInquiryNotification.php`
- `resources/js/Pages/Admin/Inquiries/Index.vue`
- `database/migrations/xxxx_create_enterprise_inquiries_table.php`

**Files Modified**:
- `resources/js/Pages/Client/Billing/Plans.vue` (added modal)
- `resources/js/Layouts/AdminLayout.vue` (added nav item)

**Admin Features**:
- View all enterprise inquiries
- Filter by status (Pending, Contacted, Closed)
- Add admin notes and update status

---

### M11.4 ✅ Direct Trial Activation
**Status**: Complete

**Implementation**:
- Free Trial plan activates directly without going to subscribe page
- Shows success notification after activation
- 14-day trial period

**Files Modified**:
- `app/Http/Controllers/Client/BillingController.php` (added `activateTrial()`)
- `resources/js/Pages/Client/Billing/Plans.vue` (direct activation button)

**Route**: `POST /billing/activate-trial/{plan}`

---

### M11.5 ✅ Token Usage Tracking Fix
**Status**: Complete

**Issue**: Ollama returns `totalTokens: 0` but provides `promptTokens` and `completionTokens` separately.

**Fix**: Calculate total from prompt + completion when totalTokens is 0.

**Files Modified**:
- `app/Services/LLM/ChatService.php` (`trackUsage()` method)

**Token Tracking**:
- Per-tenant usage tracking via `UsageRecord` model
- Monthly aggregation for billing
- Admin dashboard shows platform-wide totals (Groq cost)
- Client dashboard shows usage vs plan limits

---

### M11.6 ✅ Bhutanese Bank Payment Methods
**Status**: Complete

**Implementation**:
- Replaced generic payment methods with Bhutanese bank selection
- Banks: Bank of Bhutan, Bhutan National Bank, Druk PNB Ltd, Bhutan Development Bank Ltd., T Bank Ltd, Dk.
- Updated payment form, transaction display, and PDF receipt

**Files Modified**:
- `resources/js/Pages/Client/Billing/Subscribe.vue`
- `resources/js/Pages/Client/Billing/Transactions.vue`
- `resources/js/Pages/Admin/Transactions/Index.vue`
- `resources/views/pdf/receipt.blade.php`
- `app/Http/Controllers/Client/BillingController.php`

---

### M11.7 ✅ Reference Number Field
**Status**: Complete

**Implementation**:
- Added 6-character alphanumeric reference number field to payment form
- Stored in uppercase for consistency
- Displayed on PDF receipt

**Validation**: `required|string|size:6|alpha_num`

**Files Created**:
- `database/migrations/2025_12_04_113324_add_reference_number_to_transactions_table.php`

**Files Modified**:
- `app/Models/Transaction.php` (added to fillable)
- `resources/js/Pages/Client/Billing/Subscribe.vue`
- `app/Http/Controllers/Client/BillingController.php`
- `resources/views/pdf/receipt.blade.php`

---

### M11.8 Stripe Integration (Future)
**Status**: Pending (Optional - for international clients)

**Notes**:
- Current system uses manual payment verification
- Stripe will be added when international clients need automated payments
- Manual system works well for local BTN payments

---

## Phase 12: WordPress Plugin (COMPLETE)

### M12.1 ✅ Plugin Core Structure
**Status**: Complete

**Location**: `/Users/sam/Dev/wordpress/abitchat/`

**Files Created**:
- `abitchat.php` - Main plugin file with WordPress headers
- `includes/class-settings.php` - Settings management with WordPress Options API
- `includes/class-widget-loader.php` - Frontend widget injection
- `admin/class-admin.php` - Admin settings page
- `admin/js/admin.js` - Admin JavaScript (media uploader, color picker)
- `admin/css/admin.css` - Admin styles
- `languages/abitchat.pot` - Translation template
- `readme.txt` - WordPress.org readme
- `uninstall.php` - Clean uninstall handler

### M12.2 ✅ Admin Settings Page
**Status**: Complete

**Features**:
- Connection settings (API key, base URL)
- Test connection button with AJAX
- Widget appearance (position, color, bot name, welcome message)
- Bot avatar upload via WordPress Media Library
- Custom launcher icon upload
- Page targeting (all pages, include specific, exclude specific)
- GDPR compliance settings
- Visual previews for avatar and launcher icon

### M12.3 ✅ Widget Integration
**Status**: Complete

**Features**:
- Automatic widget injection via `wp_footer` action
- Data attributes passed to chatbot.js script
- GDPR-aware loading (deferred load after consent)
- Integration with Cookiebot and Complianz plugins
- Page targeting support

### M12.4 ✅ Security & Best Practices
**Status**: Complete

**Implementation**:
- Nonce verification on all AJAX requests
- Capability checks (`manage_options`)
- Input sanitization and output escaping
- Secure API key storage
- CSRF protection

---

## Phase 12.5: Widget Enhancements (COMPLETE)

### M12.5.1 ✅ Custom Launcher Icon
**Status**: Complete

**Features**:
- Upload custom icon via WordPress Media Library
- Replaces default chat button icon
- Maintains round shape (60x60px)
- Proper hover and open states
- Transparent background for custom icons

**Files Modified**:
- `public/widget/chatbot.js` - Added custom icon CSS and rendering
- `admin/class-admin.php` - Added launcher icon field
- `admin/js/admin.js` - Added launcher icon uploader
- `admin/css/admin.css` - Added launcher icon preview styles
- `includes/class-widget-loader.php` - Pass launcher icon to widget

### M12.5.2 ✅ Auto-Focus on Bot Reply
**Status**: Complete

**Implementation**: Input field auto-focuses after bot finishes responding

### M12.5.3 ✅ Bot Avatar in Messages
**Status**: Complete

**Features**:
- Bot avatar displayed next to each bot message
- Falls back to 🤖 emoji if no avatar set
- Consistent sizing with header avatar
- Works with typing indicator

### M12.5.4 ✅ Branding Update
**Status**: Complete

**Changes**:
- "Powered by" footer changed to "aBit Soft"
- Links to https://abit.bt
- Recommended avatar size note: 80x80px

### M12.5.5 ✅ Visual Fixes
**Status**: Complete

**Issues Fixed**:
- Header avatar overflow (added `overflow: hidden`)
- Launcher icon aspect ratio (explicit 60x60px dimensions)
- Launcher background showing around custom icon (transparent background)
- Close button invisible when chat open with custom icon (restore background on open)

---

## Phase 12.6: WordPress.org Submission (IN PROGRESS)

### Submission Preparation
**Status**: ~85% Ready

**Completed**:
- ✅ Main plugin file with proper headers
- ✅ readme.txt (WordPress.org format)
- ✅ uninstall.php (cleanup on uninstall)
- ✅ GPL v2 license declaration in headers
- ✅ Translation support (.pot file)
- ✅ Security (nonces, escaping, permissions)
- ✅ GDPR compliance

**Pending**:
- ⏳ LICENSE.txt - Full GPL v2 license text
- ⏳ .gitignore - Version control exclusions
- ⏳ .distignore - Distribution exclusions
- ⏳ /assets/ directory - Banner images, icons, screenshots

**Submission Process**:
1. Create remaining files (LICENSE.txt, .gitignore, .distignore)
2. Create assets directory with required images
3. Create ZIP package
4. Submit to https://wordpress.org/plugins/developers/add/
5. Wait for review (1-5 business days)
6. Post-approval: Upload assets via SVN

---

## Phase 13: Testing & Polish

### M13.1 Unit Tests
**Status**: Partial

**Tests Implemented**:
- ✅ `tests/Unit/Services/LLM/ChatServiceTest.php`
- ✅ `tests/Unit/Services/Knowledge/RetrievalServiceTest.php` (replaces planned RAGServiceTest)
- ✅ `tests/Unit/Services/Knowledge/TextChunkerTest.php`
- ✅ `tests/Unit/Services/Leads/LeadScoringServiceTest.php`

### M13.2 Feature Tests
**Status**: Partial

**Tests Implemented**:
- ✅ `tests/Feature/Auth/RegistrationTest.php`
- ✅ `tests/Feature/Auth/LoginTest.php`
- ✅ `tests/Feature/WidgetApiTest.php`
- ✅ `tests/Feature/KnowledgeBaseTest.php`
- ✅ `tests/Feature/LeadManagementTest.php`
- ✅ `tests/Feature/BillingTest.php`

**Still Missing**:
- `tests/Feature/Client/DashboardTest.php`
- Conversation flow tests

### M13.3 Browser Tests
**Status**: Pending

**Commands**:
```bash
composer require laravel/dusk --dev
php artisan dusk:install
```

---

## Phase 14: Data Encryption & Security

### M14.1 Widget-to-Server Encryption
**Status**: Pending

**Objective**: Encrypt all data transmitted between the chatbot widget and the backend API for enhanced security.

**Subtasks**:
1. Implement end-to-end encryption for chat messages
2. Encrypt visitor metadata and session data
3. Secure API key transmission
4. Encrypt lead capture data (email, phone, name)

**Implementation Options**:
- TLS/HTTPS enforcement (baseline)
- Payload encryption using AES-256
- Public/private key exchange for widget initialization
- Encrypted WebSocket connections for streaming

**Files to Create/Modify**:
- `app/Services/Encryption/WidgetEncryptionService.php`
- `public/widget/chatbot.js` (client-side encryption)
- `app/Http/Middleware/DecryptWidgetPayload.php`
- Widget API controllers

**Security Considerations**:
- Key rotation strategy
- Secure key storage on client
- Protection against replay attacks
- CORS policy hardening

### M14.2 Database Encryption
**Status**: Pending

**Subtasks**:
1. Encrypt sensitive fields at rest (lead emails, phone numbers)
2. Implement Laravel's encrypted casts for PII
3. Secure API keys storage
4. Audit logging for data access

### M14.3 Security Audit
**Status**: Pending

**Subtasks**:
1. OWASP Top 10 vulnerability scan
2. Penetration testing
3. Rate limiting review
4. Input sanitization audit

---

## Appendix: Test Commands

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Unit/Services/LLM/ChatServiceTest.php

# Run with coverage
php artisan test --coverage

# Run feature tests only
php artisan test --testsuite=Feature

# Clear caches before testing
php artisan config:clear && php artisan cache:clear && php artisan test
```

## Appendix: Debug Commands

```bash
# View logs in real-time
tail -f storage/logs/laravel.log

# Check queue status
php artisan queue:monitor

# Process single job
php artisan queue:work --once

# View failed jobs
php artisan queue:failed
```

# AbitChat

AI-Powered WordPress Chatbot SaaS with RAG (Retrieval-Augmented Generation) and Lead Capture.

## Features

- **Multi-tenant Architecture** - Powered by Spatie Laravel Multitenancy
- **LLM Integration** - Prism with Ollama (dev) / Groq (prod)
- **Knowledge Base** - Document upload, chunking, and embeddings
- **RAG Pipeline** - Semantic search for context-aware responses
- **Lead Capture** - Automatic lead scoring and capture during conversations
- **Widget API** - Embeddable chatbot for WordPress sites
- **Client Dashboard** - Conversation history, leads, analytics
- **Admin Dashboard** - Platform management, client overview

## Tech Stack

- **Backend**: Laravel 12+, PHP 8.2+
- **Frontend**: Vue 3 (Composition API), Inertia.js, Tailwind CSS v4
- **Database**: MySQL 8.0+, Redis
- **LLM**: Ollama (gemma3:4b) for dev, Groq (llama-3.1) for prod
- **Payments**: Laravel Cashier (Stripe)

## Quick Start

```bash
# Clone the repo
git clone https://github.com/YOUR_USERNAME/abitchat.git
cd abitchat

# Install dependencies
composer install
pnpm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Run migrations and seeders
php artisan migrate --seed

# Start development servers
composer dev
```

## Test Credentials

**Client User:**
- Email: `test@example.com`
- Password: `password`

**Admin User:**
- Email: `admin@example.com`
- Password: `password`

## Development

```bash
# Run all dev servers (Laravel, Queue, Logs, Vite)
composer dev

# Run tests
composer test

# Or individually
php artisan serve
pnpm run dev
```

### Local email (Mailpit)

This project sends transactional email via Resend in production and Mailpit (a local SMTP catcher) in development.

**Install via Homebrew** (no Docker needed):
```bash
brew install mailpit
brew services start mailpit
```

**Or via Docker** (if you prefer):
```bash
docker run -d --name chatbot-mailpit --restart unless-stopped \
  -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Mail UI: http://localhost:8025

Verify with:
```bash
php artisan tinker --execute='\Mail::raw("test", fn ($m) => $m->to("test@example.com")->subject("Test"));'
```

## License

MIT

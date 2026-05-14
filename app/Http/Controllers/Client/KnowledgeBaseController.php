<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\KnowledgeItemStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Rules\SafeExternalUrl;
use App\Services\Knowledge\KnowledgeCache;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function __construct(
        private KnowledgeCache $cache,
        private KnowledgeItemWorkflow $workflow,
    ) {}

    public function index(): Response
    {
        $tenant = $this->getTenant();

        $items = KnowledgeItem::forTenant($tenant)
            ->withCount('chunks')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (KnowledgeItem $item): array {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'type' => $item->type,
                    'status' => $item->status,
                    'chunks_count' => $item->chunks_count,
                    'created_at' => $item->created_at->format('M d, Y'),
                    'error_message' => $item->error_message,
                    'failed_at' => $item->failed_at?->format('M d, Y H:i'),
                ];
            });

        $statsByType = KnowledgeItem::forTenant($tenant)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        $stats = [
            'documents' => $statsByType->get('document', 0),
            'faqs' => $statsByType->get('faq', 0),
            'webpages' => $statsByType->get('webpage', 0),
            'text' => $statsByType->get('text', 0),
        ];

        return Inertia::render('Client/KnowledgeBase/Index', [
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Client/KnowledgeBase/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'type' => 'required|in:document,faq,webpage,text',
            'title' => 'required|string|max:255',
            'content' => 'required_if:type,faq,text|nullable|string',
            'source_url' => ['required_if:type,webpage', 'nullable', 'url', new SafeExternalUrl],
            'file' => 'required_if:type,document|nullable|file|mimes:pdf,doc,docx,txt,md|max:10240',
        ]);

        Log::debug('[Knowledge] (NO $) Creating item', [
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
        ]);

        $item = new KnowledgeItem;
        $item->tenant_id = $tenant->id;
        $item->title = $validated['title'];
        $item->type = $validated['type'];
        $item->status = KnowledgeItemStatus::Pending;

        if ($validated['type'] === 'document' && $request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("knowledge/{$tenant->id}", 'local');
            $item->file_path = $path ?: null;
            $item->metadata = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        } elseif ($validated['type'] === 'webpage') {
            $item->source_url = $validated['source_url'];
        } else {
            $item->content = $validated['content'];
        }

        $item->save();

        $this->dispatchProcessing($item);
        $this->cache->invalidate($tenant);

        Log::debug('[Knowledge] (NO $) Item created, processing queued', [
            'item_id' => $item->id,
        ]);

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item added and is being processed.');
    }

    public function show(KnowledgeItem $item): Response
    {
        $this->authorize('view', $item);

        $item->loadCount('chunks');

        return Inertia::render('Client/KnowledgeBase/Show', [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'content' => $item->content,
                'source_url' => $item->source_url,
                'status' => $item->status,
                'metadata' => $item->metadata,
                'chunks_count' => $item->chunks_count,
                'created_at' => $item->created_at->format('M d, Y H:i'),
                'updated_at' => $item->updated_at->format('M d, Y H:i'),
                'error_message' => $item->error_message,
                'failed_at' => $item->failed_at?->format('M d, Y H:i'),
            ],
        ]);
    }

    public function edit(KnowledgeItem $item): Response
    {
        $this->authorize('view', $item);

        return Inertia::render('Client/KnowledgeBase/Edit', [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'content' => $item->content,
                'source_url' => $item->source_url,
            ],
        ]);
    }

    public function update(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'source_url' => ['nullable', 'url', new SafeExternalUrl],
        ]);

        $item->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? $item->content,
            'source_url' => $validated['source_url'] ?? $item->source_url,
            'status' => KnowledgeItemStatus::Pending,
        ]);

        if ($item->wasChanged('content') || $item->wasChanged('source_url')) {
            $item->chunks()->delete();
            $this->dispatchProcessing($item);
        }

        $this->cache->invalidate($this->getTenant());

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item updated.');
    }

    public function destroy(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        Log::debug('[Knowledge] (NO $) Deleting item', [
            'item_id' => $item->id,
        ]);

        if ($item->file_path && Storage::disk('local')->exists($item->file_path)) {
            Storage::disk('local')->delete($item->file_path);
        }

        $item->chunks()->delete();
        $item->delete();

        $this->cache->invalidate($this->getTenant());

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item deleted.');
    }

    public function reprocess(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $item->chunks()->delete();
        $item->update([
            'status' => KnowledgeItemStatus::Pending,
            'error_message' => null,
            'failed_at' => null,
        ]);

        $this->dispatchProcessing($item);
        $this->cache->invalidate($this->getTenant());

        return redirect()->back()
            ->with('success', 'Knowledge item queued for reprocessing.');
    }

    public function retry(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $this->workflow->retry($item);

        return redirect()->back()
            ->with('success', 'Knowledge item queued for retry.');
    }

    /**
     * Dispatch ProcessKnowledgeItem in a way that doesn't block the HTTP response.
     *
     * Under a real queue driver (redis, database) plain dispatch() is already
     * non-blocking. Under QUEUE_CONNECTION=sync (dev or misconfigured prod),
     * plain dispatch() blocks the request for the full processing duration —
     * including DocumentProcessor::extractFromUrl's 30s HTTP fetch. Use
     * dispatchAfterResponse only in that case so the response goes out first.
     *
     * Note: dispatchAfterResponse internally calls dispatchSync which forces
     * the 'sync' connection regardless of config. Routing through it only
     * makes sense when the configured driver is already sync.
     */
    private function dispatchProcessing(KnowledgeItem $item): void
    {
        if (config('queue.default') === 'sync') {
            ProcessKnowledgeItem::dispatchAfterResponse($item);

            return;
        }

        ProcessKnowledgeItem::dispatch($item);
    }
}

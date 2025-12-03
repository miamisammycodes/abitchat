<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function index(): Response
    {
        $tenant = $this->getTenant();

        $items = KnowledgeItem::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'type' => $item->type,
                    'status' => $item->status,
                    'chunks_count' => $item->chunks()->count(),
                    'created_at' => $item->created_at->format('M d, Y'),
                ];
            });

        $stats = [
            'documents' => KnowledgeItem::where('tenant_id', $tenant->id)
                ->where('type', 'document')->count(),
            'faqs' => KnowledgeItem::where('tenant_id', $tenant->id)
                ->where('type', 'faq')->count(),
            'webpages' => KnowledgeItem::where('tenant_id', $tenant->id)
                ->where('type', 'webpage')->count(),
            'text' => KnowledgeItem::where('tenant_id', $tenant->id)
                ->where('type', 'text')->count(),
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
            'source_url' => 'required_if:type,webpage|nullable|url',
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
        $item->status = 'pending';

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

        // Dispatch job to process the knowledge item
        ProcessKnowledgeItem::dispatch($item);

        Log::debug('[Knowledge] (NO $) Item created, processing queued', [
            'item_id' => $item->id,
        ]);

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item added and is being processed.');
    }

    public function show(KnowledgeItem $item): Response
    {
        $this->authorizeItem($item);

        return Inertia::render('Client/KnowledgeBase/Show', [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'content' => $item->content,
                'source_url' => $item->source_url,
                'status' => $item->status,
                'metadata' => $item->metadata,
                'chunks_count' => $item->chunks()->count(),
                'created_at' => $item->created_at->format('M d, Y H:i'),
                'updated_at' => $item->updated_at->format('M d, Y H:i'),
            ],
        ]);
    }

    public function edit(KnowledgeItem $item): Response
    {
        $this->authorizeItem($item);

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
        $this->authorizeItem($item);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'source_url' => 'nullable|url',
        ]);

        $item->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? $item->content,
            'source_url' => $validated['source_url'] ?? $item->source_url,
            'status' => 'pending',
        ]);

        // Re-process if content changed
        if ($item->wasChanged('content') || $item->wasChanged('source_url')) {
            $item->chunks()->delete();
            ProcessKnowledgeItem::dispatch($item);
        }

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item updated.');
    }

    public function destroy(KnowledgeItem $item): RedirectResponse
    {
        $this->authorizeItem($item);

        Log::debug('[Knowledge] (NO $) Deleting item', [
            'item_id' => $item->id,
        ]);

        // Delete file if exists
        if ($item->file_path && Storage::disk('local')->exists($item->file_path)) {
            Storage::disk('local')->delete($item->file_path);
        }

        // Delete chunks first
        $item->chunks()->delete();
        $item->delete();

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item deleted.');
    }

    public function reprocess(KnowledgeItem $item): RedirectResponse
    {
        $this->authorizeItem($item);

        $item->chunks()->delete();
        $item->update(['status' => 'pending']);

        ProcessKnowledgeItem::dispatch($item);

        return redirect()->back()
            ->with('success', 'Knowledge item queued for reprocessing.');
    }

    private function authorizeItem(KnowledgeItem $item): void
    {
        $tenant = $this->getTenant();

        if ($item->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }
    }
}

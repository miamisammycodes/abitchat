<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = $user->tenant;

        $conversations = Conversation::forTenant($tenant)
            ->withStatus($request->string('status')->toString() ?: null)
            ->createdBetween(
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
            )
            ->when($request->boolean('has_lead'), fn ($q) => $q->whereNotNull('lead_id'))
            ->withCount('messages')
            ->with([
                // Qualified columns: latestMessage relation does a self-join which
                // makes unqualified conversation_id ambiguous.
                'latestMessage' => fn ($q) => $q->select([
                    'messages.id',
                    'messages.conversation_id',
                    'messages.content',
                    'messages.created_at',
                ]),
                'lead:id,name,email',
            ])
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Client/Conversations/Index', [
            'conversations' => $conversations,
            'filters' => [
                'status' => $request->string('status')->toString() ?: null,
                'from' => $request->string('from')->toString() ?: null,
                'to' => $request->string('to')->toString() ?: null,
                'has_lead' => $request->boolean('has_lead'),
            ],
        ]);
    }

    public function show(Request $request, Conversation $conversation): InertiaResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_if($conversation->tenant_id !== $user->tenant_id, 404);

        $conversation->load([
            'messages' => fn ($q) => $q->orderBy('created_at'),
            'lead:id,name,email,score',
        ]);

        return Inertia::render('Client/Conversations/Show', [
            'conversation' => $conversation,
        ]);
    }

    public function archive(Request $request, Conversation $conversation): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_if($conversation->tenant_id !== $user->tenant_id, 404);

        $conversation->update(['status' => 'archived']);

        return redirect()->route('client.conversations.index')
            ->with('success', 'Conversation archived.');
    }

    public function unarchive(Request $request, Conversation $conversation): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_if($conversation->tenant_id !== $user->tenant_id, 404);

        $conversation->update(['status' => 'active']);

        return redirect()->route('client.conversations.index')
            ->with('success', 'Conversation restored.');
    }

    public function export(Request $request, Conversation $conversation): StreamedResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_if($conversation->tenant_id !== $user->tenant_id, 404);

        $filename = "conversation-{$conversation->id}.txt";

        return response()->streamDownload(function () use ($conversation): void {
            echo "Conversation #{$conversation->id}\n";
            echo 'Started: '.$conversation->created_at->format('Y-m-d H:i:s')."\n";
            echo "Status: {$conversation->status}\n\n";

            foreach ($conversation->messages()->orderBy('created_at')->get() as $message) {
                $role = $message->role === 'assistant' ? 'Assistant' : 'Visitor';
                $time = $message->created_at->format('H:i:s');
                echo "[{$time}] {$role}: {$message->content}\n";
            }
        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

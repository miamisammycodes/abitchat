<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        abort(501);
    }

    public function show(Request $request, Conversation $conversation): InertiaResponse
    {
        abort(501);
    }

    public function archive(Request $request, Conversation $conversation): Response
    {
        abort(501);
    }

    public function unarchive(Request $request, Conversation $conversation): Response
    {
        abort(501);
    }

    public function export(Request $request, Conversation $conversation): StreamedResponse
    {
        abort(501);
    }
}

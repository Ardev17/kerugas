<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mensagens de contacto recebidas pelo site (Setembro 2026). Sem
 * âmbito por província de propósito — ao contrário de postos/pedidos,
 * uma mensagem de contacto não pertence a nenhuma província (o
 * formulário público não pede localização), por isso é visível a
 * qualquer Admin/Super Admin, tal como o Histórico de SMS era antes de
 * ser escopado (aqui não há razão de auditoria/custo para restringir).
 */
class ContactMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContactMessage::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $messages = $query->latest()->latest('id')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
                'unread_count' => ContactMessage::where('status', 'NEW')->count(),
            ],
        ]);
    }

    public function markRead(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->update(['status' => 'READ']);

        return response()->json(['success' => true, 'data' => $contactMessage->fresh()]);
    }
}

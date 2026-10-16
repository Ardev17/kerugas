<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;

/** Configuração pública do app nativo (Outubro 2026): versões e link da loja. */
class AppConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['android' => AppVersion::android(), 'ios' => AppVersion::ios()]]);
    }
}

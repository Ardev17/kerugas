<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Campaigns\CampaignService;
use App\Services\PhoneNumberService;
use App\Services\Auth\LoginThrottle;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Autenticação via Laravel Sanctum (secção 18).
 *
 * REGRAS DE SEGURANÇA IMPLEMENTADAS:
 * - Password nunca em texto puro (Hash::make usa bcrypt/argon2id conforme
 *   config/hashing.php do Laravel).
 * - Rate limiting aplicado na rota (ver routes/api.php: throttle:auth).
 * - Account lockout: 5 tentativas falhadas -> bloqueio de 15 minutos.
 * - Números de telefone sempre normalizados antes de guardar/comparar,
 *   para nunca criar contas duplicadas por variação do mesmo número.
 */
class AuthController extends Controller
{

    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly LoginThrottle $throttle,
    )
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $normalizedPhone = $this->phoneNumberService->normalize($data['phone']);

        if ($normalizedPhone === null) {
            return response()->json([
                'success' => false,
                'message' => 'Número de telefone inválido.',
                'errors' => ['phone' => ['Formato de telefone angolano não reconhecido.']],
            ], 422);
        }

        if (User::where('phone', $normalizedPhone)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Já existe uma conta com este número de telefone.',
                'errors' => ['phone' => ['Número já registado.']],
            ], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'phone' => $normalizedPhone,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'province_id' => $data['province_id'],
            'municipality_id' => $data['municipality_id'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'role' => UserRole::USER,
            'status' => 'ACTIVE',
        ]);

        $token = $user->createToken('kerugas-web')->plainTextToken;

        // Campanha de boas-vindas (Setembro 2026) — melhor esforço: uma
        // falha aqui nunca pode impedir a criação da conta.
        try {
            app(CampaignService::class)->deliver('welcome', $user, [], "welcome:{$user->id}", ['ignore_quiet_hours' => true]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('[AuthController] Boas-vindas não enviadas (não crítico)', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user->load(['province', 'municipality', 'neighborhood'])),
                'token' => $token,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $normalizedPhone = $this->phoneNumberService->normalize($data['identifier']);

        $user = User::query()
            ->when($normalizedPhone, fn ($q) => $q->orWhere('phone', $normalizedPhone))
            ->orWhere('email', $data['identifier'])
            ->first();

        // Proteção contra adivinhação (Outubro 2026) — ver LoginThrottle: os
        // erros contam por "conta + rede", nunca bloqueiam a conta inteira
        // nem toda a rede da operadora. A conta é identificada pelo id (o
        // mesmo telefone escrito de outra forma conta como a mesma conta).
        $accountKey = $user ? 'u' . $user->id : ($normalizedPhone ?? mb_strtolower(trim($data['identifier'])));
        $ip = (string) $request->ip();

        if ($wait = $this->throttle->retryAfter($accountKey, $ip)) {
            $minutes = (int) ceil($wait / 60);

            return response()->json([
                'success' => false,
                'message' => "Muitas tentativas falhadas. Por segurança, tente de novo daqui a {$minutes} " . ($minutes === 1 ? 'minuto' : 'minutos') . ' — ou recupere a palavra-passe.',
                'code' => 'TOO_MANY_ATTEMPTS',
                'retry_after' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        if (!$user || !Hash::check($data['password'], $user->password)) {
            $this->throttle->failed($accountKey, $ip, $user);

            return $this->invalidCredentialsResponse();
        }

        $this->throttle->succeeded($accountKey, $ip);

        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'A sua conta está suspensa. Contacte o suporte do KERUGAS.',
                'code' => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('kerugas-web')->plainTextToken;

        // Eliminação de conta (Outubro 2026): entrar durante o prazo de
        // arrependimento cancela o pedido.
        $deletionCancelled = app(\App\Services\Account\AccountDeletionService::class)->cancel($user, $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user->load(['province', 'municipality', 'neighborhood'])),
                'token' => $token,
                'deletion_cancelled' => $deletionCancelled,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        // Só um token Bearer real (PersonalAccessToken) se apaga — um
        // TransientToken (sessão por cookie) não tem delete() e rebentaria.
        if ($accessToken instanceof PersonalAccessToken) {
            // Push (Setembro 2026): o telemóvel com que a pessoa fez login
            // deixa de receber notificações desta conta ao sair —
            // importante em telemóveis partilhados. A FK em cascata já faz
            // isto quando a tabela do Sanctum a aceita; isto garante-o sempre.
            DeviceToken::where('personal_access_token_id', $accessToken->id)->delete();
            WebPushSubscription::where('personal_access_token_id', $accessToken->id)->delete();
            $accessToken->delete();
        }

        return response()->json(['success' => true, 'message' => 'Sessão terminada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()->load(['province', 'municipality', 'neighborhood'])),
        ]);
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Credenciais inválidas.',
            'code' => 'INVALID_CREDENTIALS',
        ], 401);
    }
}

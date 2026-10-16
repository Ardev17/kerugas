<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(private readonly PhoneNumberService $phoneNumberService)
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

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user->load(['province', 'municipality'])),
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

        if (!$user) {
            return $this->invalidCredentialsResponse();
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'Conta temporariamente bloqueada devido a várias tentativas falhadas. Tente novamente mais tarde.',
                'code' => 'ACCOUNT_LOCKED',
            ], 423);
        }

        if (!Hash::check($data['password'], $user->password)) {
            $this->registerFailedAttempt($user);

            return $this->invalidCredentialsResponse();
        }

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

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user->load(['province', 'municipality'])),
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Sessão terminada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()->load(['province', 'municipality'])),
        ]);
    }

    private function registerFailedAttempt(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        $user->update([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= self::MAX_FAILED_ATTEMPTS
                ? now()->addMinutes(self::LOCKOUT_MINUTES)
                : null,
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

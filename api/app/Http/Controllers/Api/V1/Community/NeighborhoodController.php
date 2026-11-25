<?php

namespace App\Http\Controllers\Api\V1\Community;

use App\Http\Controllers\Controller;
use App\Models\Neighborhood;
use App\Services\Community\CommunityAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bairros de cada município (Outubro 2026). A lista é pública (alimenta o
 * perfil e o registo); criar, renomear e apagar é do Admin para cima, só nos
 * municípios da sua área.
 */
class NeighborhoodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['municipality_id' => ['required', 'integer', 'exists:municipalities,id']]);

        return response()->json(['success' => true, 'data' => Neighborhood::query()
            ->where('municipality_id', $request->integer('municipality_id'))->orderBy('name')->get(['id', 'name', 'municipality_id'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[^<>{}]+$/u'],
        ]);
        $this->authorizeMunicipality($request, (int) $data['municipality_id']);
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $this->assertUnique((int) $data['municipality_id'], $name);

        $row = Neighborhood::create(['municipality_id' => $data['municipality_id'], 'name' => $name]);

        return response()->json(['success' => true, 'data' => $row->only(['id', 'name', 'municipality_id'])], 201);
    }

    public function update(Request $request, Neighborhood $neighborhood): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[^<>{}]+$/u']]);
        $this->authorizeMunicipality($request, (int) $neighborhood->municipality_id);
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $this->assertUnique((int) $neighborhood->municipality_id, $name, $neighborhood->id);

        $neighborhood->update(['name' => $name]);

        return response()->json(['success' => true, 'data' => $neighborhood->only(['id', 'name', 'municipality_id'])]);
    }

    public function destroy(Request $request, Neighborhood $neighborhood): JsonResponse
    {
        $this->authorizeMunicipality($request, (int) $neighborhood->municipality_id);
        $neighborhood->delete(); // utilizadores e mensagens ficam sem bairro (nullOnDelete)

        return response()->json(['success' => true]);
    }

    private function authorizeMunicipality(Request $request, int $municipalityId): void
    {
        abort_unless(CommunityAccess::canAdminister($request->user()) && CommunityAccess::canView($request->user(), $municipalityId), 403, 'Esse município está fora da sua área.');
    }

    private function assertUnique(int $municipalityId, string $name, ?int $ignore = null): void
    {
        $exists = Neighborhood::query()->where('municipality_id', $municipalityId)->where('name', $name)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Já existe um bairro com esse nome neste município.']);
        }
    }
}

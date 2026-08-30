<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tag::class);

        $perPage = (int) $request->integer('per_page', 100);
        $perPage = max(1, min($perPage, 100));

        $query = Tag::query();

        if ($request->filled('q')) {
            $term = $request->string('q');
            $query->where('name', 'like', "%{$term}%");
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => TagResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Tag::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
        ], [
            'name.required' => 'Etiket adı zorunludur.',
            'name.max' => 'Etiket adı en fazla :max karakter olabilir.',
            'color.max' => 'Renk en fazla :max karakter olabilir.',
        ]);

        // Aynı isim tekrar gönderilirse hata göstermek yerine mevcut
        // etiketi döneriz (firstOrCreate) — kullanıcı deneyimi için.
        $tag = Tag::firstOrCreate(
            ['slug' => Str::slug($validated['name'])],
            ['name' => $validated['name'], 'color' => $validated['color'] ?? null]
        );

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}

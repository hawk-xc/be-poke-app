<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Ability;
use App\Models\Pokemon;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Illuminate\Support\Facades\Log;
use App\Repositories\AuthRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Middleware\PermissionMiddleware;
    
class PokemonController extends Controller
{
    use ResponseTrait;
    protected AuthRepository $authRepository;

    /**
     * AuthController constructor.
     */
    public function __construct(AuthRepository $ar)
    {
        // Gunakan class middleware langsung
        $this->middleware([PermissionMiddleware::class . ':pokemon:list'])->only(['index', 'show', 'fetchData']);
        $this->middleware([PermissionMiddleware::class . ':pokemon:create'])->only('store');
        $this->middleware([PermissionMiddleware::class . ':pokemon:edit'])->only('update');
        $this->middleware([PermissionMiddleware::class . ':pokemon:delete'])->only(['destroy']);

        $this->authRepository = $ar;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $data = Pokemon::query();

        try {
            if ($request->filled('columns')) {
                $data->select([$request->get('columns')]);
            }

            if ($request->filled('search')) {
                if ($request->get('search_of') == 'left') {
                    $data->where('name', 'like', '%' . $request->get('search'));
                } else {
                    $data->where('name', 'like', $request->get('search') . '%');
                }
            }

            if ($request->filled('filter')) {
                switch ($request->get('filter')) {
                    case 'name':
                        $data->where('name', $request->get('filter_value'));
                        break;
                    case 'weight':
                        if ($request->filled('set_of')) {
                            if ($request->get('set_of') == 'greather_than') {
                                $data->where('weight', '>', $request->get('filter_value'));
                            } else if ($request->get('set_of') == 'less_than') {
                                $data->where('weight', '<', $request->get('filter_value'));
                            } else {
                                $data->where('weight', $request->get('filter_value'));
                            }
                        } else {
                            $data->where('weight', $request->get('filter_value'));
                        }
                        break;
                    case 'best_experience':
                        $data->where('best_experience', $request->get('filter_value'));
                        break;
                }
            }

            if ($request->filled('sort')) {
                $data->orderBy($request->get('sort'), $request->get('order'));
            }

            if ($request->filled('limit')) {
                $data->limit($request->get('limit'));
            }

            if ($request->filled('with_relation') == 'true') {
                $data->with('abilities');
            }

            $perPage = $request->query('per_page', 15);
            $data = $data->paginate($perPage);

            return $this->responseSuccess($data, "Success", 200);
        } catch (Exception $err) {
            Log::error("API Error : " . $err->getMessage());
            return $this->responseError(null, "API Error", 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'best_experience' => 'required|integer',
            'weight' => 'required|integer',
            'image_path' => 'required|image|extensions:jpg,jpeg,png,webp',
        ]);

        try {
            $pokemon = new Pokemon();
            $pokemon->name = $request->name;
            $pokemon->best_experience = $request->best_experience;
            $pokemon->weight = $request->weight;

            if ($request->hasFile('image_path')) {
                $path = Storage::disk('minio')->putFile('pokemons', $request->file('image_path'), 'public');
                $pokemon->image_path = Storage::disk('minio')->url($path);
            }

            $pokemon->save();

            return $this->responseSuccess($pokemon, "Success", 201);
        } catch (Exception $err) {
            Log::error("API Error : " . $err->getMessage());
            return $this->responseError(null, "API Error", 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $data = Pokemon::with('abilities')->findOrFail($id);

        try {
            return $this->responseSuccess($data, "Success", 200);
        } catch (Exception $err) {
            Log::error('API Error' . $err->getMessage());
            return $this->responseError(null, "API Error", 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $pokemon = Pokemon::findOrFail($id);

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'best_experience' => 'sometimes|integer',
                'weight' => 'sometimes|integer',
                'image_path' => 'sometimes|image|extensions:jpg,jpeg,png,webp',
            ]);

            $pokemon->name = $request->name ?? $pokemon->name;
            $pokemon->best_experience = $request->best_experience ?? $pokemon->best_experience;
            $pokemon->weight = $request->weight ?? $pokemon->weight;

            if ($request->hasFile('image_path')) {
                if ($pokemon->image_path) {
                    $oldPath = parse_url($pokemon->image_path, PHP_URL_PATH);
                    $oldPath = ltrim($oldPath, '/');
                    if (Storage::disk('minio')->exists($oldPath)) {
                        Storage::disk('minio')->delete($oldPath);
                    }
                }

                $path = Storage::disk('minio')->putFile('pokemons', $request->file('image_path'), 'public');
                $pokemon->image_path = Storage::disk('minio')->url($path);
            }

            $pokemon->save();

            return $this->responseSuccess($pokemon, "Success", 200);
        } catch (Exception $err) {
            Log::error('API Error: ' . $err->getMessage());
            return $this->responseError(null, "API Error", 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $data = Pokemon::findOrFail($id);

        try {
            $data->delete();

            return $this->responseSuccess(null, "Success", 200);
        } catch (Exception $err) {
            Log::error('API Error' . $err->getMessage());
            return $this->responseError(null, "API Error", 500);
        }
    }

    /**
     * Call the command to fetch data from Pokemon API
     */
    public function fetchData(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);

            $exitCode = Artisan::call('app:get-pokemon-data', [
                '--limit' => $limit
            ]);

            $output = Artisan::output();

            $newPokemonCount = Pokemon::whereDate('created_at', today())->count();
            $newAbilityCount = Ability::whereDate('created_at', today())->count();

            return $this->responseSuccess([
                'status' => 'success',
                'message' => 'Pokemon data fetching process started',
                'command_exit_code' => $exitCode,
                'new_pokemons_today' => $newPokemonCount,
                'new_abilities_today' => $newAbilityCount,
                'output_preview' => substr($output, 0, 500) . (strlen($output) > 500 ? '...' : ''),
                'limit_requested' => $limit
            ], "Fetch process completed", 200);
        } catch (Exception $err) {
            Log::error('Fetch data error: ' . $err->getMessage());
            return $this->responseError(null, "Failed to fetch Pokemon data", 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\Studio;
use App\Http\Resources\StudioResource;
use Illuminate\Http\Request;

class StudioController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Studio::query()
            ->when($q, function($builder) use ($q) {
                $builder->where('nama_studio', 'like', "%$q%");
                $builder->orWhere('tipe_studio', 'like', "%$q%");
            })
            ->paginate($per);
        return StudioResource::collection($data);
    }

    public function show($id)
    {
        $row = Studio::findOrFail($id);
        return new StudioResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = Studio::create($payload);
        return response()->json(new StudioResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Studio::findOrFail($id);
        $row->fill($request->all())->save();
        return new StudioResource($row);
    }

    public function destroy($id)
    {
        $row = Studio::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

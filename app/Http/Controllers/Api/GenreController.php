<?php

namespace App\Http\Controllers\Api;

use App\Models\Genre;
use App\Http\Resources\GenreResource;
use Illuminate\Http\Request;

class GenreController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q   = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Genre::query()
            ->when($q, fn($builder) => $builder->where('nama_genre', 'like', "%$q%"))
            ->paginate($per);
        return GenreResource::collection($data);
    }

    public function show($id)
    {
        $row = Genre::findOrFail($id);
        return new GenreResource($row);
    }

    public function store(Request $request)
    {
        $row = Genre::create($request->all());
        return response()->json(new GenreResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Genre::findOrFail($id);
        $row->fill($request->all())->save();
        return new GenreResource($row);
    }

    public function destroy($id)
    {
        $row = Genre::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

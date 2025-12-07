<?php

namespace App\Http\Controllers\Api;

use App\Models\Kasir;
use App\Http\Resources\KasirResource;
use Illuminate\Http\Request;

class KasirController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Kasir::query()
            ->when($q, function($builder) use ($q) {
                $builder->where('nama', 'like', "%$q%");
            })
            ->paginate($per);
        return KasirResource::collection($data);
    }

    public function show($id)
    {
        $row = Kasir::findOrFail($id);
        return new KasirResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = Kasir::create($payload);
        return response()->json(new KasirResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Kasir::findOrFail($id);
        $row->fill($request->all())->save();
        return new KasirResource($row);
    }

    public function destroy($id)
    {
        $row = Kasir::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

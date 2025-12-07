<?php

namespace App\Http\Controllers\Api;

use App\Models\Tiket;
use App\Http\Resources\TiketResource;
use Illuminate\Http\Request;

class TiketController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Tiket::query()
            ->paginate($per);
        return TiketResource::collection($data);
    }

    public function show($id)
    {
        $row = Tiket::findOrFail($id);
        return new TiketResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = Tiket::create($payload);
        return response()->json(new TiketResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Tiket::findOrFail($id);
        $row->fill($request->all())->save();
        return new TiketResource($row);
    }

    public function destroy($id)
    {
        $row = Tiket::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

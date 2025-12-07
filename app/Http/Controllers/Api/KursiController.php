<?php

namespace App\Http\Controllers\Api;

use App\Models\Kursi;
use App\Http\Resources\KursiResource;
use Illuminate\Http\Request;

class KursiController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Kursi::query()
            ->paginate($per);
        return KursiResource::collection($data);
    }

    public function show($id)
    {
        $row = Kursi::findOrFail($id);
        return new KursiResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = Kursi::create($payload);
        return response()->json(new KursiResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Kursi::findOrFail($id);
        $row->fill($request->all())->save();
        return new KursiResource($row);
    }

    public function destroy($id)
    {
        $row = Kursi::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

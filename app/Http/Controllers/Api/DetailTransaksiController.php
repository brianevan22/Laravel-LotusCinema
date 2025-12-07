<?php

namespace App\Http\Controllers\Api;

use App\Models\DetailTransaksi;
use App\Http\Resources\DetailTransaksiResource;
use Illuminate\Http\Request;

class DetailTransaksiController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = DetailTransaksi::query()
            ->paginate($per);
        return DetailTransaksiResource::collection($data);
    }

    public function show($id)
    {
        $row = DetailTransaksi::findOrFail($id);
        return new DetailTransaksiResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = DetailTransaksi::create($payload);
        return response()->json(new DetailTransaksiResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = DetailTransaksi::findOrFail($id);
        $row->fill($request->all())->save();
        return new DetailTransaksiResource($row);
    }

    public function destroy($id)
    {
        $row = DetailTransaksi::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

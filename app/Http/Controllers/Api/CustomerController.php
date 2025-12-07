<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // <-- tambahkan
use Illuminate\Support\Facades\Schema; // <-- tambahkan

class CustomerController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $flat = $request->boolean('flat');

        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $builder = Customer::query()
            ->when($q, function($builder) use ($q) {
                $builder->where(function ($query) use ($q) {
                    $query->where('nama', 'like', "%$q%")
                          ->orWhere('email', 'like', "%$q%");
                });
            });

        if ($flat) {
            $rows = $builder->orderByDesc('customer_id')->get();
            return response()->json([
                'ok'    => true,
                'count' => $rows->count(),
                'data'  => CustomerResource::collection($rows)->resolve(),
            ]);
        }

        $data = $builder->paginate($per);
        return CustomerResource::collection($data);
    }

    public function show($id)
    {
        $row = Customer::findOrFail($id);
        return new CustomerResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'nama'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:100'],
            'no_hp'    => ['nullable', 'string', 'max:20'],
            'id_users' => ['nullable', 'integer', 'exists:users,id_users'],
        ]);

        $insert = [
            'nama'  => $payload['nama'],
            'email' => $payload['email'],
            'no_hp' => $payload['no_hp'] ?? null,
        ];
        if (array_key_exists('id_users', $payload)) {
            $insert['id_users'] = $payload['id_users'];
        }

        $id = DB::table('customer')->insertGetId($insert);
        $row = Customer::findOrFail($id);

        return response()->json(new CustomerResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Customer::findOrFail($id);

        $payload = $request->validate([
            'nama'     => ['nullable', 'string', 'max:100'],
            'email'    => ['nullable', 'email', 'max:100'],
            'no_hp'    => ['nullable', 'string', 'max:20'],
            'id_users' => ['nullable', 'integer', 'exists:users,id_users'],
        ]);

        if (!empty($payload)) {
            DB::table('customer')
                ->where('customer_id', $row->customer_id)
                ->update(array_filter([
                    'nama'     => $payload['nama'] ?? null,
                    'email'    => $payload['email'] ?? null,
                    'no_hp'    => $payload['no_hp'] ?? null,
                    'id_users' => $payload['id_users'] ?? null,
                ], fn ($value) => $value !== null));
        }

        return new CustomerResource($row->fresh());
    }

    public function destroy($id)
    {
        $row = Customer::findOrFail($id);

        try {
            if ($row->id_users !== null && Schema::hasTable('users')) {
                DB::table('users')->where('id_users', $row->id_users)->delete();
            }
        } catch (\Throwable $_) {
            // jangan batalkan; hanya mencoba sinkron
        }

        $row->delete();
        return response()->json(['deleted' => true]);
    }
}

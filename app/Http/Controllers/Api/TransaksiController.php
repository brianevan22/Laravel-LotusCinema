<?php

namespace App\Http\Controllers\Api;

use App\Models\Transaksi;
use App\Http\Resources\TransaksiResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransaksiController extends \App\Http\Controllers\Controller
{
    protected array $withRelations = [
        'customer',
        'detail.tiket.kursi',
        'detail.tiket.jadwal.film',
        'detail.tiket.jadwal.studio',
    ];

    protected function normalizeStatus(?string $status): ?string
    {
        if (!$status) {
            return null;
        }
        $value = strtolower(trim($status));
        if (in_array($value, ['success', 'completed', 'selesai'])) {
            return 'sukses';
        }
        if (in_array($value, ['cancel', 'canceled', 'batal', 'failed'])) {
            return 'batal';
        }
        return $value;
    }

    protected function baseQuery(Request $request)
    {
        $query = Transaksi::with($this->withRelations)
            ->orderByDesc('tanggal_transaksi')
            ->orderByDesc('transaksi_id');

        if ($status = $this->normalizeStatus($request->query('status'))) {
            $query->where('status', $status);
        }

        if ($request->boolean('only_pending')) {
            $query->where('status', 'pending');
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', (int)$customerId);
        }

        return $query;
    }

    protected function validatePayload(Request $request, bool $requireCustomer = false): array
    {
        $customerRule = $requireCustomer ? ['required', 'integer'] : ['sometimes', 'integer'];

        return $request->validate([
            'customer_id' => $customerRule,
            'kasir_id' => ['nullable', 'integer'],
            'tanggal_transaksi' => ['nullable', 'date'],
            'total_harga' => ['nullable', 'numeric'],
            'status' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_destination' => ['nullable', 'string', 'max:120'],
            'payment_account_name' => ['nullable', 'string', 'max:150'],
            'paid_at' => ['nullable', 'date'],
        ]);
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        $flat = filter_var($request->query('flat'), FILTER_VALIDATE_BOOLEAN);
        $per = max(5, min(100, (int)$request->query('per_page', 15)));

        if ($flat) {
            return TransaksiResource::collection($query->get());
        }

        return TransaksiResource::collection($query->paginate($per));
    }

    public function show($id)
    {
        $row = Transaksi::with($this->withRelations)->findOrFail($id);
        return new TransaksiResource($row);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, true);
        $data['status'] = $this->normalizeStatus($data['status'] ?? 'pending') ?? 'pending';
        $row = Transaksi::create($data);
        return response()->json(new TransaksiResource($row->load($this->withRelations)), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Transaksi::findOrFail($id);
        $data = $this->validatePayload($request);
        if (isset($data['status'])) {
            $data['status'] = $this->normalizeStatus($data['status']) ?? 'pending';
        }
        $row->fill($data)->save();
        return new TransaksiResource($row->load($this->withRelations));
    }

    public function destroy($id)
    {
        $row = Transaksi::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }

    public function setStatus(Request $request, $id)
    {
        $request->validate([
            'status' => ['required', Rule::in(['pending', 'sukses', 'success', 'selesai', 'batal', 'cancel', 'canceled'])],
        ]);

        $row = Transaksi::findOrFail($id);
        $status = $this->normalizeStatus($request->input('status')) ?? 'pending';
        $row->status = $status;
        if ($status === 'sukses') {
            $row->paid_at = now();
            if (!$row->tanggal_transaksi) {
                $row->tanggal_transaksi = $row->paid_at;
            }
        }
        if ($status === 'pending') {
            $row->paid_at = null;
        }
        $row->save();

        return new TransaksiResource($row->load($this->withRelations));
    }
}

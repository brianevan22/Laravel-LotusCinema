<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\DetailTransaksiResource;

class TransaksiResource extends JsonResource
{
    public function toArray($request): array
    {
        $details = $this->whenLoaded('detail', function () {
            return DetailTransaksiResource::collection($this->detail);
        });

        $kursi = [];
        $film = null;
        $jadwal = null;
        $studio = null;

        if ($this->relationLoaded('detail')) {
            $kursi = $this->detail->map(function ($detail) {
                $seat = $detail->tiket && $detail->tiket->relationLoaded('kursi')
                    ? $detail->tiket->kursi
                    : null;
                return [
                    'tiket_id' => $detail->tiket_id,
                    'kursi_id' => $seat->kursi_id ?? null,
                    'nomor_kursi' => $seat->nomor_kursi ?? null,
                    'harga' => (int)($detail->harga ?? 0),
                ];
            })->values()->all();

            $firstDetail = $this->detail->first();
            if ($firstDetail && $firstDetail->tiket && $firstDetail->tiket->relationLoaded('jadwal')) {
                $jadwalModel = $firstDetail->tiket->jadwal;
                if ($jadwalModel) {
                    $filmModel = $jadwalModel->relationLoaded('film') ? $jadwalModel->film : null;
                    $studioModel = $jadwalModel->relationLoaded('studio') ? $jadwalModel->studio : null;
                    $film = $filmModel ? [
                        'film_id' => $filmModel->film_id,
                        'judul' => $filmModel->judul,
                    ] : null;
                    $studio = $studioModel ? [
                        'studio_id' => $studioModel->studio_id,
                        'nama_studio' => $studioModel->nama_studio,
                    ] : null;
                    $jadwal = [
                        'jadwal_id' => $jadwalModel->jadwal_id,
                        'tanggal' => $jadwalModel->tanggal,
                        'jam_mulai' => $jadwalModel->jam_mulai,
                        'jam_selesai' => $jadwalModel->jam_selesai,
                        'studio' => $studio,
                    ];
                }
            }
        }

        $status = strtolower((string)($this->status ?? 'pending'));
        if ($status === 'success') {
            $status = 'sukses';
        }

        return [
            'transaksi_id' => $this->transaksi_id,
            'customer_id' => $this->customer_id,
            'kasir_id' => $this->kasir_id,
            'status' => $status,
            'payment_method' => $this->payment_method,
            'payment_destination' => $this->payment_destination,
            'payment_account_name' => $this->payment_account_name,
            'paid_at' => optional($this->paid_at)->toDateTimeString(),
            'tanggal_transaksi' => optional($this->tanggal_transaksi)->toDateTimeString(),
            'total_harga' => (int)($this->total_harga ?? 0),
            'customer' => $this->whenLoaded('customer', function () {
                return [
                    'customer_id' => $this->customer->customer_id,
                    'nama' => $this->customer->nama ?? $this->customer->name ?? null,
                    'email' => $this->customer->email ?? null,
                    'no_hp' => $this->customer->no_hp ?? null,
                ];
            }),
            'detail' => $details,
            'kursi' => $kursi,
            'film' => $film,
            'jadwal' => $jadwal,
            'studio' => $studio,
        ];
    }
}

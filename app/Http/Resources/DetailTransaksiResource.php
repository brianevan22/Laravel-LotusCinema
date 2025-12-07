<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DetailTransaksiResource extends JsonResource
{
    public function toArray($request): array
    {
        $tiket = $this->whenLoaded('tiket');
        $kursi = $tiket && $tiket->relationLoaded('kursi') ? $tiket->kursi : null;
        $jadwal = $tiket && $tiket->relationLoaded('jadwal') ? $tiket->jadwal : null;
        $film = $jadwal && $jadwal->relationLoaded('film') ? $jadwal->film : null;
        $studio = $jadwal && $jadwal->relationLoaded('studio') ? $jadwal->studio : null;

        return [
            'detail_id' => $this->detail_id,
            'transaksi_id' => $this->transaksi_id,
            'tiket_id' => $this->tiket_id,
            'harga' => (int)($this->harga ?? 0),
            'kursi' => $kursi ? [
                'kursi_id' => $kursi->kursi_id,
                'studio_id' => $kursi->studio_id,
                'nomor_kursi' => $kursi->nomor_kursi,
            ] : null,
            'jadwal' => $jadwal ? [
                'jadwal_id' => $jadwal->jadwal_id,
                'tanggal' => $jadwal->tanggal,
                'jam_mulai' => $jadwal->jam_mulai,
                'jam_selesai' => $jadwal->jam_selesai,
                'film' => $film ? [
                    'film_id' => $film->film_id,
                    'judul' => $film->judul,
                ] : null,
                'studio' => $studio ? [
                    'studio_id' => $studio->studio_id,
                    'nama_studio' => $studio->nama_studio,
                ] : null,
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class CustomerController extends Controller
{
    public function dashboard()
    {
        $id_pelanggan = Auth::guard('pelanggan')->user()->id_pelanggan;

        // Ambil mobil tersedia (Pastikan view/tabel vw_katalog_mobil ada)
        // Catatan: Walaupun status 'Tersedia', nanti pas booking kita cek tanggal lagi biar aman.
        $mobil = DB::table('vw_katalog_mobil')
                    ->where('status_mobil', 'Tersedia')
                    ->get();

        // Ambil riwayat
        $riwayat = DB::table('transaksi')
                    ->join('mobil', 'transaksi.no_plat', '=', 'mobil.no_plat')
                    ->select('transaksi.*', 'mobil.merek', 'mobil.foto')
                    ->where('transaksi.id_pelanggan', $id_pelanggan)
                    ->orderBy('transaksi.tgl_transaksi', 'desc')
                    ->get();

        return view('customer.dashboard', compact('mobil', 'riwayat'));
    }

    public function prosesBooking(Request $request)
    {
        // 1. Validasi Input Dasar
        $request->validate([
            'tgl_pinjam' => 'required|date|after_or_equal:today',
            'tgl_kembali' => 'required|date|after:tgl_pinjam',
            'no_plat' => 'required',
            'harga_per_hari' => 'required|numeric'
        ]);

        // 2. CEK BENTROK TANGGAL (VALIDASI LANJUTAN) -- INI YANG BARU
        $tgl_awal = $request->tgl_pinjam;
        $tgl_akhir = $request->tgl_kembali;
        $no_plat  = $request->no_plat;

        // Logika: Cari apakah ada transaksi AKTIF di rentang tanggal tersebut untuk mobil ini
        $cekBentrok = DB::table('transaksi')
            ->where('no_plat', $no_plat)
            ->where(function($query) use ($tgl_awal, $tgl_akhir) {
                $query->whereBetween('tgl_pinjam', [$tgl_awal, $tgl_akhir])
                      ->orWhereBetween('tgl_rencana_kembali', [$tgl_awal, $tgl_akhir])
                      ->orWhere(function($q) use ($tgl_awal, $tgl_akhir) {
                          $q->where('tgl_pinjam', '<=', $tgl_awal)
                            ->where('tgl_rencana_kembali', '>=', $tgl_akhir);
                      });
            })
            // Hanya cek transaksi yang statusnya AKTIF (Booking, Verifikasi, atau Sedang Dipakai)
            // Kalau status 'Lunas' (sudah kembali) atau 'Dibatalkan', berarti tanggalnya aman.
            ->whereIn('status_pembayaran_sewa', ['Menunggu Pembayaran', 'Menunggu Verifikasi', 'Sedang Dipakai'])
            ->exists();

        // Jika bentrok, tendang balik customer dengan pesan error
        if ($cekBentrok) {
            return redirect()->back()->with('error', 'Maaf, Mobil ini SUDAH DIBOOKING orang lain pada tanggal tersebut. Silakan pilih tanggal atau mobil lain.');
        }

        // 3. Proses Insert Data (Kalau lolos validasi)
        $id_pelanggan = Auth::guard('pelanggan')->user()->id_pelanggan;
        $id_transaksi = 'TRX-' . time(); 

        $tgl_start = Carbon::parse($request->tgl_pinjam);
        $tgl_end = Carbon::parse($request->tgl_kembali);
        
        // Hitung selisih hari (minimal 1 hari)
        $jumlah_hari = $tgl_start->diffInDays($tgl_end) ?: 1;
        $total_biaya = $jumlah_hari * $request->harga_per_hari;

        // Insert Transaksi Baru
        DB::table('transaksi')->insert([
            'id_transaksi' => $id_transaksi,
            'no_plat' => $request->no_plat,
            'id_pelanggan' => $id_pelanggan,
            'tgl_transaksi' => now(),
            'tgl_pinjam' => $request->tgl_pinjam,
            'tgl_rencana_kembali' => $request->tgl_kembali,
            'total_biaya_sewa' => $total_biaya,
            'status_pembayaran_sewa' => 'Menunggu Pembayaran',
            'denda_keterlambatan' => 0,
            'denda_kerusakan' => 0,
            'total_bayar_denda' => 0
        ]);

        return redirect('/customer/dashboard')->with('sukses', 'Booking berhasil! Silakan upload bukti DP 50% di tab Riwayat.');
    }

    public function uploadBukti(Request $request)
    {
        $request->validate([
            'bukti_bayar' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'id_transaksi' => 'required'
        ]);

        if ($request->hasFile('bukti_bayar')) {
            // Simpan ke storage/app/public/bukti_transfer
            $path = $request->file('bukti_bayar')->store('bukti_transfer', 'public');

            // Update Database
            DB::table('transaksi')
                ->where('id_transaksi', $request->id_transaksi)
                ->update([
                    'bukti_bayar' => $path, 
                    'status_pembayaran_sewa' => 'Menunggu Verifikasi'
                ]);

            return redirect('/customer/dashboard')->with('sukses', 'Bukti berhasil diupload! Tunggu verifikasi admin.');
        }

        return redirect()->back()->with('error', 'Gagal upload file.');
    }
}
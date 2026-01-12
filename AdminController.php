<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // Untuk Query Builder
use Illuminate\Support\Facades\File; // Untuk Hapus Foto Mobil
use App\Models\Transaksi;
use App\Models\Mobil;

class AdminController extends Controller
{
    // --- DASHBOARD UTAMA ---
    public function index()
    {
        // Tampilkan transaksi urut dari yang terbaru
        $transaksis = Transaksi::with(['pelanggan', 'mobil'])
                        ->orderBy('tgl_transaksi', 'desc') 
                        ->get();

        // Tampilkan daftar semua mobil (untuk fitur Maintenance)
        $mobils = Mobil::all();

        return view('admin.dashboard', compact('transaksis', 'mobils'));
    }

    // --- 1. LOGIKA APPROVE (TERIMA DP) ---
    public function approve($id)
    {
        $transaksi = Transaksi::where('id_transaksi', $id)->firstOrFail();
        
        $transaksi->status_pembayaran_sewa = 'Sedang Dipakai'; 
        $transaksi->save();

        // Update Status Mobil
        $mobil = Mobil::where('no_plat', $transaksi->no_plat)->first();
        if($mobil) {
            $mobil->status_mobil = 'Disewa';
            $mobil->save();
        }

        return redirect()->back()->with('success', 'DP Diterima! Mobil resmi keluar.');
    }

    // --- 2. LOGIKA REJECT (MINTA UPLOAD ULANG) ---
    public function reject($id)
    {
        $transaksi = Transaksi::where('id_transaksi', $id)->firstOrFail();
        
        $transaksi->status_pembayaran_sewa = 'Menunggu Pembayaran'; 
        $transaksi->save();

        return redirect()->back()->with('error', 'Bukti Ditolak. Status dikembalikan ke Menunggu Pembayaran.');
    }

    // --- 3. LOGIKA BATAL (CANCEL ORDER) ---
    public function batal($id)
    {
        $transaksi = Transaksi::where('id_transaksi', $id)->firstOrFail();
        
        $transaksi->status_pembayaran_sewa = 'Dibatalkan';
        $transaksi->save();

        $mobil = Mobil::where('no_plat', $transaksi->no_plat)->first();
        if($mobil) {
            $mobil->status_mobil = 'Tersedia';
            $mobil->save();
        }

        return redirect()->back()->with('error', 'Transaksi dibatalkan. Mobil kembali tersedia.');
    }

    // --- 4. LOGIKA PELUNASAN (KEMBALI MOBIL) ---
    public function kembali(Request $request, $id)
    {
        $transaksi = Transaksi::where('id_transaksi', $id)->firstOrFail();

        $transaksi->tgl_kembali_real = now();
        $transaksi->kondisi_mobil_kembali = $request->kondisi_mobil_kembali;
        $transaksi->total_bayar_denda = $request->denda ?? 0;
        $transaksi->status_pembayaran_sewa = 'Lunas'; 
        $transaksi->save();

        $mobil = Mobil::where('no_plat', $transaksi->no_plat)->first();
        if($mobil) {
            $mobil->status_mobil = 'Tersedia';
            $mobil->save();
        }

        return redirect()->back()->with('success', 'Mobil dikembalikan & Pembayaran LUNAS!');
    }

    // --- 5. FITUR MAINTENANCE (BENGKEL) ---

    // A. Masuk Bengkel
    public function servisMasuk($no_plat)
    {
        $mobil = Mobil::where('no_plat', $no_plat)->firstOrFail();

        if ($mobil->status_mobil == 'Disewa') {
            return redirect()->back()->with('error', 'Gagal! Mobil sedang dibawa customer.');
        }

        $mobil->status_mobil = 'Maintenance';
        $mobil->save();

        return redirect()->back()->with('success', 'Mobil masuk bengkel.');
    }

    // B. Selesai Servis
    public function servisSelesai(Request $request, $no_plat)
    {
        $mobil = Mobil::where('no_plat', $no_plat)->firstOrFail();

        // Generate ID Maintenance Manual (MNT-Waktu)
        $id_mnt = 'MNT-' . time(); 

        // Simpan ke Tabel Maintenance
        DB::table('maintenance')->insert([
            'id_maintenance' => $id_mnt,
            'no_plat' => $no_plat,
            'tgl_masuk' => now(),
            'tgl_keluar_real' => now(),
            'jenis_perbaikan' => $request->jenis_perbaikan,
            'biaya_perbaikan' => $request->biaya,
            'status_perbaikan' => 'Selesai'
        ]);

        $mobil->status_mobil = 'Tersedia';
        $mobil->save();

        return redirect()->back()->with('success', 'Servis selesai! Biaya tercatat.');
    }

    // --- 6. CETAK LAPORAN KEUANGAN ---
    public function cetakLaporan()
    {
        $pemasukan = Transaksi::with(['pelanggan', 'mobil'])
                    ->where('status_pembayaran_sewa', 'Lunas')
                    ->orderBy('tgl_transaksi', 'desc')
                    ->get();

        $pengeluaran = DB::table('maintenance')
                    ->join('mobil', 'maintenance.no_plat', '=', 'mobil.no_plat')
                    ->select('maintenance.*', 'mobil.merek')
                    ->orderBy('tgl_masuk', 'desc')
                    ->get();

        return view('admin.cetak_laporan', compact('pemasukan', 'pengeluaran'));
    }

    // --- 7. MANAJEMEN KATALOG MOBIL (BARU) ---

    // A. Tampilkan Halaman Katalog
    public function tampilMobil()
    {
        // Ambil Data Mobil + Join ke Jenis Mobil (biar dapat harga)
        $mobils = DB::table('mobil')
                    ->join('jenis_mobil', 'mobil.id_jenis', '=', 'jenis_mobil.id_jenis')
                    ->select('mobil.*', 'jenis_mobil.nama_jenis', 'jenis_mobil.harga_per_hari')
                    ->get();
        
        // Ambil Data Jenis untuk Dropdown Tambah/Edit
        $jenis_mobil = DB::table('jenis_mobil')->get(); 
        
        return view('admin.manajemen_mobil', compact('mobils', 'jenis_mobil'));
    }

    // B. Tambah Mobil Baru
    public function tambahMobil(Request $request)
    {
        $request->validate([
            'no_plat' => 'required|unique:mobil,no_plat',
            'merek' => 'required',
            'id_jenis' => 'required',
            'warna' => 'required',
            'tahun_buat' => 'required|numeric',
            'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        // Upload Foto
        $nama_foto = time() . '.' . $request->foto->extension();
        $request->foto->move(public_path('img'), $nama_foto);

        // Insert ke Database
        DB::table('mobil')->insert([
            'no_plat' => $request->no_plat,
            'merek' => $request->merek,
            'id_jenis' => $request->id_jenis,
            'warna' => $request->warna,
            'tahun_buat' => $request->tahun_buat,
            'status_mobil' => 'Tersedia',
            'foto' => $nama_foto
        ]);

        return redirect()->back()->with('success', 'Mobil baru berhasil ditambahkan!');
    }

    // C. Update / Edit Mobil
    public function updateMobil(Request $request, $no_plat)
    {
        // Ambil data lama
        $mobil = DB::table('mobil')->where('no_plat', $no_plat)->first();

        // Data yang mau diupdate
        $dataUpdate = [
            'merek' => $request->merek,
            'warna' => $request->warna,
            'tahun_buat' => $request->tahun_buat,
            'id_jenis' => $request->id_jenis
        ];

        // Cek Foto Baru
        if ($request->hasFile('foto')) {
            // Hapus foto lama
            $pathLama = public_path('img/' . $mobil->foto);
            if (file_exists($pathLama)) { @unlink($pathLama); }

            // Upload foto baru
            $nama_foto = time() . '.' . $request->foto->extension();
            $request->foto->move(public_path('img'), $nama_foto);
            
            $dataUpdate['foto'] = $nama_foto;
        }

        // Update DB
        DB::table('mobil')->where('no_plat', $no_plat)->update($dataUpdate);

        return redirect()->back()->with('success', 'Data mobil berhasil diperbarui!');
    }

    // D. Hapus Mobil
    public function hapusMobil($no_plat)
    {
        $mobil = DB::table('mobil')->where('no_plat', $no_plat)->first();

        // Hapus File Foto
        $pathFoto = public_path('img/' . $mobil->foto);
        if (file_exists($pathFoto)) { @unlink($pathFoto); }

        // Hapus Record DB
        DB::table('mobil')->where('no_plat', $no_plat)->delete();

        return redirect()->back()->with('success', 'Mobil berhasil dihapus.');
    }
}
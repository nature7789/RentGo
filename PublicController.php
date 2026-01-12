<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pelanggan; // Pastikan Model Pelanggan sudah ada
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class PublicController extends Controller
{
    // Menampilkan Halaman Katalog (Landing Page)
    public function index()
    {
        // Ambil data dari VIEW database 'vw_katalog_mobil' biar lengkap ada harganya
        $mobil = DB::table('vw_katalog_mobil')->get();
        
        return view('welcome', compact('mobil'));
    }

    // Menampilkan Form Daftar (Register)
    public function formRegister()
    {
        return view('auth.register');
    }

    // Proses Simpan Akun Baru
    public function prosesRegister(Request $request)
    {
        // 1. Validasi input
        $request->validate([
            'nama_lengkap' => 'required',
            'email' => 'required|email|unique:pelanggan,email',
            'password' => 'required|min:6',
            'no_ktp' => 'required|numeric',
            'no_telp' => 'required',
            'alamat' => 'required'
        ]);

        // 2. Buat ID Pelanggan Otomatis (Contoh: P + Angka Acak)
        // Karena di database kamu ID-nya varchar (P001), kita buat random simpel aja
        $id_baru = 'P' . rand(100, 999); 

        // 3. Simpan ke Database
        Pelanggan::create([
            'id_pelanggan' => $id_baru,
            'nama_lengkap' => $request->nama_lengkap,
            'email' => $request->email,
            'password' => Hash::make($request->password), // Wajib Hash!
            'no_ktp' => $request->no_ktp,
            'no_telp' => $request->no_telp,
            'alamat' => $request->alamat
        ]);

        // 4. Langsung Login otomatis setelah daftar (Opsional)
        // Atau lempar ke halaman login
        return redirect('/login')->with('sukses', 'Akun berhasil dibuat! Silakan Login.');
    }
}
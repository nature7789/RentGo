<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Pelanggan;

class AuthController extends Controller
{
    // --- 1. TAMPILKAN HALAMAN LOGIN ---
    public function showLogin()
    {
        // PERBAIKAN DI SINI: Sesuaikan dengan nama file kamu "login_gabungan.blade.php"
        // Lokasi: resources/views/auth/login_gabungan.blade.php
        return view('auth.login_gabungan'); 
    }

    // --- 2. PROSES LOGIN (ADMIN & CUSTOMER) ---
    public function prosesLogin(Request $request)
    {
        // Validasi input
        $request->validate([
            'id_pengguna' => 'required', // Bisa Username (Admin) atau Email (Customer)
            'password' => 'required'
        ]);

        $input = $request->id_pengguna;
        $password = $request->password;

        // A. COBA LOGIN SEBAGAI ADMIN (Cek tabel karyawan)
        if (Auth::guard('admin')->attempt(['username' => $input, 'password' => $password])) {
            $request->session()->regenerate();
            return redirect()->intended('/admin/dashboard');
        }

        // B. COBA LOGIN SEBAGAI CUSTOMER (Cek tabel pelanggan)
        if (Auth::guard('pelanggan')->attempt(['email' => $input, 'password' => $password])) {
            $request->session()->regenerate();
            return redirect()->intended('/customer/dashboard');
        }

        // C. KALAU GAGAL SEMUA
        return back()->withErrors([
            'login_gagal' => 'Username/Email atau Password salah!',
        ])->onlyInput('id_pengguna');
    }

    // --- 3. TAMPILKAN HALAMAN REGISTER ---
    public function showRegister()
    {
        // Sesuai gambar: resources/views/auth/register.blade.php
        return view('auth.register');
    }

    // --- 4. PROSES REGISTER CUSTOMER BARU ---
    public function prosesRegister(Request $request)
    {
        $request->validate([
            'nama_lengkap' => 'required|max:100',
            'email' => 'required|email|unique:pelanggan,email',
            'no_ktp' => 'required|numeric|unique:pelanggan,no_ktp',
            'no_telp' => 'required|numeric',
            'password' => 'required|min:6' // Hapus confirmed kalau di form register gak ada 'Konfirmasi Password'
        ]);

        // Bikin ID Pelanggan Otomatis
        $id_pelanggan = 'P-' . time();

        // Simpan ke Database
        Pelanggan::create([
            'id_pelanggan' => $id_pelanggan,
            'nama_lengkap' => $request->nama_lengkap,
            'email' => $request->email,
            'no_ktp' => $request->no_ktp,
            'no_telp' => $request->no_telp,
            'alamat' => $request->alamat ?? '-', 
            'password' => Hash::make($request->password),
        ]);

        return redirect('/login')->with('success', 'Registrasi Berhasil! Silakan Login.');
    }

    // --- 5. LOGOUT ---
    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        Auth::guard('pelanggan')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
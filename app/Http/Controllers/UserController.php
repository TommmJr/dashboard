<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * Controller manajemen user.
 *
 * Saat ini baru halaman daftar user sebagai pembuktian gate permission
 * 'user.view' (dilindungi middleware 'can:user.view' di routes/web.php).
 * CRUD user penuh (create/update/delete) menyusul di tahap berikutnya.
 */
class UserController extends Controller
{
    /**
     * Daftar semua user beserta role-nya.
     * Eager loading role untuk mencegah N+1 query.
     */
    public function index(): View
    {
        $users = User::query()
            ->with('role')
            ->orderBy('name')
            ->paginate(50);

        return view('users.index', ['users' => $users]);
    }
}

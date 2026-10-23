<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Penerima yang membalas STOP / BERHENTI ke workspace ini.
 *
 * Halaman ini ada supaya penolakan "penerima sudah meminta berhenti" selalu
 * punya jalan keluar yang bisa dilihat: pelanggan yang membalas STOP lalu
 * menelepon minta dikirimi lagi, atau STOP yang terketik tidak sengaja.
 * Tanpa halaman ini, satu-satunya cara membukanya lagi adalah menunggu
 * penerimanya membalas MULAI — yang tidak pernah diketahui siapa pun.
 */
class OptOutController extends Controller
{
    public function index(Request $request): View
    {
        $cari = preg_replace('/\D+/', '', (string) $request->query('cari', ''));

        return view('dashboard.opt-outs.index', [
            'optOuts' => EnsureWorkspaceSelected::from($request)->optOuts()
                ->when($cari !== '', fn ($q) => $q->where('phone', 'like', "%{$cari}%"))
                ->latest('updated_at')
                ->paginate(50)
                ->withQueryString(),
            'cari' => $cari,
        ]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $optOut = EnsureWorkspaceSelected::from($request)->optOuts()->findOrFail($id);

        $optOut->delete();

        AuditLog::record('opt_out.removed', $optOut, ['phone' => $optOut->phone]);

        return back()->with('status', "{$optOut->phone} bisa dikirimi pesan lagi.");
    }
}

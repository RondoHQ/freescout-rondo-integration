<?php

namespace Modules\RondoIntegration\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\RondoIntegration\Services\BindingService;

class BindingAdminController extends Controller
{
    public function index()
    {
        $bindings = DB::table('rondo_oidc_bindings')
            ->leftJoin('users', 'users.id', '=', 'rondo_oidc_bindings.last_user_id')
            ->select('rondo_oidc_bindings.*', 'users.first_name', 'users.last_name')
            ->orderBy('rondo_oidc_bindings.created_at', 'desc')
            ->paginate(50);
        return view('rondointegration::settings.bindings', ['bindings' => $bindings]);
    }

    public function disable(User $user, Request $request, BindingService $bindings)
    {
        $this->validateAdminAction($request);
        $bindings->disable($user, auth()->user(), $request->reason);
        \Session::flash('flash_success_floating', __('Rondo sign-in disabled and sessions invalidated.'));
        return redirect()->route('rondointegration.bindings');
    }

    public function replace(User $user, Request $request, BindingService $bindings)
    {
        $this->validateAdminAction($request);
        $url = $bindings->createRecovery($user, auth()->user(), $request->reason);
        return view('rondointegration::settings.recovery', ['recovery_url' => $url, 'user' => $user]);
    }

    private function validateAdminAction(Request $request)
    {
        $request->validate([
            'password_current' => 'required|string',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        if (!Hash::check($request->password_current, auth()->user()->password)) {
            abort(403, 'Local password confirmation failed.');
        }
    }
}


<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class UserController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.users.index', ['users' => User::query()->latest()->paginate(25)]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::query()->create([
            ...$request->userAttributes(),
            'password' => Hash::make(str()->password(32)),
        ]);

        $status = Password::sendResetLink(['email' => $user->email]);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', $status === Password::RESET_LINK_SENT
                ? 'User created and invitation email sent.'
                : 'User created, but the invitation email could not be sent. Use password reset to resend it.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $wasActive = $user->is_active;

        $user->update($request->userAttributes());

        if ($wasActive && ! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'User updated.');
    }

    public function sendPasswordResetLink(User $user): RedirectResponse
    {
        $this->authorize('sendPasswordResetLink', $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', $status === Password::RESET_LINK_SENT
                ? 'Password setup email sent.'
                : 'Password setup email could not be sent. Check mail configuration and try again.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();
        });

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }
}

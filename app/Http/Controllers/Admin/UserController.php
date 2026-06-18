<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
        $user->update($request->userAttributes());

        return redirect()->route('admin.users.edit', $user)->with('status', 'User updated.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(Request $request): array
    {
        $request->validate([
            "email" => "required|email",
            "password" => "required",
        ]);

        $user = User::query()
            ->where("email", $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                "email" => "Invalid credentials",
            ]);
        }

        $token = $user->createToken("Access Token");

        return ["user" => $user, "token" => $token->plainTextToken];
    }

    public function destroy(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();
        $user->currentAccessToken()->delete();
        return response("Token deleted", 200);
    }

    /**
     * @throws AuthenticationException
     */
    public function myAccount(Request $request): array
    {
        if (!($user = $request->user())) {
            throw new AuthenticationException();
        }

        return [
            "user" => $user,
        ];
    }
}

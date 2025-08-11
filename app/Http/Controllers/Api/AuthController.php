<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    // ✅ Enregistrer un nouvel utilisateur
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role_id'  => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id'  => $validated['role_id'],
        ]);

        $user->load('role'); // Charger le nom du rôle

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message'      => 'User registered successfully.',
            'user'         => $user,
            'role'         => $user->role->name ?? null, // 👈 Rôle (nom)
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ], 201);
    }

    // ✅ Se connecter
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role')->where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.'
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message'      => 'Login successful.',
            'user'         => $user,
            'role_id' => $user->role_id, // 👈 important
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ], 200);
    }

    // ✅ Voir l’utilisateur connecté
    public function me(Request $request)
    {
        $user = $request->user()->load('role'); // Charge la relation role

        return response()->json([
            'message' => 'User profile retrieved successfully.',
            'user'    => $user,
            'role'    => $user->role->name ?? null,
        ]);
    }

    // ✅ Se déconnecter
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }
}

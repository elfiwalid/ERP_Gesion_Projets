<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Projet;
use Illuminate\Http\Request;

class ProjetController extends Controller
{
    public function index()
    {
        return Projet::with(['createdBy', 'validatedBy', 'documents'])->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'client_name' => 'required|string',
        ]);

        $projet = Projet::create([
            'name' => $validated['name'],
            'client_name' => $validated['client_name'],
            'status' => 'en_attente',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($projet, 201);
    }

    public function show(Projet $projet)
    {
        return $projet->load(['createdBy', 'validatedBy', 'documents']);
    }

    public function update(Request $request, Projet $projet)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'client_name' => 'sometimes|string',
            'status' => 'sometimes|string',
        ]);

        $projet->update($validated);

        return response()->json($projet);
    }

    public function destroy(Projet $projet)
    {
        $projet->delete();

        return response()->json(null, 204);
    }
}

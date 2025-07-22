<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    // Lister tous les documents
    public function index(Request $request)
    {
        $user = $request->user();

        if (in_array($user->role->name, ['Admin Général', 'Responsable Administratif'])) {
            // Admins : voir seulement ceux validés et partagés avec eux
            return Document::whereIn('shared_with', ['admin_general', 'responsable_administratif'])
                ->where('status', 'valide')
                ->with(['projet', 'uploadedBy', 'validatedBy'])
                ->get();
        }

        // Les autres : voir tout ce qu’ils ont créé ou qui leur est partagé
        return Document::with(['projet', 'uploadedBy', 'validatedBy'])->get();
    }

    // Créer un document (Chef de Terrain)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'projet_id'   => 'required|exists:projets,id',
            'name'        => 'required|string',
            'type'        => 'required|string',
            'path'        => 'required|string',
            'status'      => 'sometimes|in:brouillon,partage,valide',
            'shared_with' => 'sometimes|in:chef_terrain,chef_terrain_superieur',
        ]);

        $document = Document::create([
            'projet_id'   => $validated['projet_id'],
            'name'        => $validated['name'],
            'type'        => $validated['type'],
            'path'        => $validated['path'],
            'status'      => $validated['status'] ?? 'brouillon',
            'shared_with' => $validated['shared_with'] ?? 'chef_terrain',
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document->load(['projet', 'uploadedBy', 'validatedBy']), 201);
    }

    // Voir un document
    public function show(Document $document)
    {
        return $document->load(['projet', 'uploadedBy', 'validatedBy']);
    }

    // Mettre à jour un document
    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string',
            'type'        => 'sometimes|string',
            'path'        => 'sometimes|string',
            'status'      => 'sometimes|in:brouillon,partage,valide',
            'shared_with' => 'sometimes|in:chef_terrain,chef_terrain_superieur,admin_general,responsable_administratif',
        ]);

        $document->update($validated);

        return response()->json($document->load(['projet', 'uploadedBy', 'validatedBy']));
    }

    // Supprimer un document
    public function destroy(Document $document)
    {
        $document->delete();
        return response()->json(['message' => 'Document supprimé avec succès.'], 204);
    }

    // Valider et partager par Chef Supérieur → Admins
    public function validateAndShare(Document $document, Request $request)
    {
        $user = $request->user();

        if ($user->role->name !== 'Chef de Terrain Supérieur') {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($document->shared_with !== 'chef_terrain' || $document->status !== 'brouillon') {
            return response()->json(['message' => 'Document non éligible à la validation.'], 400);
        }

        // Partage aux deux rôles admin
        $document->update([
            'status'        => 'valide',
            'shared_with'   => 'admin_general', // ou 'responsable_administratif' selon besoin
            'validated_by'  => $user->id,
        ]);

        return response()->json([
            'message'  => 'Document validé et partagé aux admins.',
            'document' => $document->load(['projet', 'uploadedBy', 'validatedBy']),
        ]);
    }
}

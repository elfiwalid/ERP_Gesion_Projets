<?php

// app/Http/Controllers/Api/DocumentController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    // =========================
    // Rôles (constantes SonarQube-safe)
    // =========================
    public const ROLE_ADMIN_GENERAL       = 'Admin Général';
    public const ROLE_RESP_ADMIN          = 'Responsable Administratif';
    public const ROLE_CTS                 = 'Chef de Terrain Supérieur';
    public const ROLE_CT                  = 'Chef de Terrain';

    // Clés techniques associées (pour shared_with JSON)
    public const KEY_ADMIN_GENERAL        = 'admin_general';
    public const KEY_RESP_ADMIN           = 'responsable_administratif';
    public const KEY_CTS                  = 'chef_terrain_superieur';
    public const KEY_CT                   = 'chef_terrain';

    // =========================
    // LISTE (visibilité)
    // =========================
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->role->name ?? '';

        if ($role === self::ROLE_ADMIN_GENERAL) {
            // AdminG : à valider (gate=admin, en_attente) + tout ce qui lui est partagé
            return Document::with(['projet','uploadedBy','reviewedBy'])
                ->where(function ($q) {
                    $q->where('validation_gate', 'admin')
                      ->where('status', 'en_attente');
                })
                ->orWhereJsonContains('shared_with', self::KEY_ADMIN_GENERAL)
                ->latest()->get();
        }

        if ($role === self::ROLE_RESP_ADMIN) {
            // RA : ses propres docs + ce qui est partagé avec RA
            return Document::with(['projet','uploadedBy','reviewedBy'])
                ->where('uploaded_by', $user->id)
                ->orWhereJsonContains('shared_with', self::KEY_RESP_ADMIN)
                ->latest()->get();
        }

        if ($role === self::ROLE_CTS) {
            // CTS : à valider (gate=cts, en_attente) + ce qui est partagé avec CTS
            return Document::with(['projet','uploadedBy','reviewedBy'])
                ->where(function ($q) {
                    $q->where('validation_gate', 'cts')
                      ->where('status', 'en_attente');
                })
                ->orWhereJsonContains('shared_with', self::KEY_CTS)
                ->latest()->get();
        }

        if ($role === self::ROLE_CT) {
            // CT : ses propres docs + ce qui est partagé avec CT
            return Document::with(['projet','uploadedBy','reviewedBy'])
                ->where('uploaded_by', $user->id)
                ->orWhereJsonContains('shared_with', self::KEY_CT)
                ->latest()->get();
        }

        // Fallback (si autre rôle)
        return Document::with(['projet','uploadedBy','reviewedBy'])->latest()->get();
    }

    // =========================
    // CRÉATION
    // =========================
    public function store(Request $request)
    {
        $user = $request->user();
        $role = $user->role->name ?? '';

        $validated = $request->validate([
            'projet_id'    => ['required','exists:projets,id'],
            'name'         => ['required','string','max:255'],
            'type'         => ['required','string','max:50'],
            'path'         => ['required','string'], // Option A: tu fournis le chemin (pas d’upload fichier)
            'shared_with'  => ['required','array','min:1'],
            'shared_with.*'=> [Rule::in([
                self::KEY_ADMIN_GENERAL,
                self::KEY_RESP_ADMIN,
                self::KEY_CTS,
                self::KEY_CT,
            ])],
        ]);

        // Déduction workflow
        $validationGate = 'none';
        $status = 'brouillon';
        $aud = $validated['shared_with'];

        if ($role === self::ROLE_RESP_ADMIN) {
            // RA → docs à valider par AdminG, visibles AdminG + CTS
            $validationGate = 'admin';
            $status = 'en_attente';
            $aud = array_values(array_unique(array_merge($aud, [
                self::KEY_CTS,
                self::KEY_ADMIN_GENERAL,
            ])));
        } elseif ($role === self::ROLE_CT) {
            // CT → docs à valider par CTS (au minimum partagés avec CTS)
            $validationGate = 'cts';
            $status = 'en_attente';
            $aud = array_values(array_unique(array_merge($aud, [
                self::KEY_CTS,
            ])));
        }

        $doc = Document::create([
            'projet_id'       => $validated['projet_id'],
            'name'            => $validated['name'],
            'type'            => $validated['type'],
            'path'            => $validated['path'],
            'status'          => $status,
            'validation_gate' => $validationGate,
            'shared_with'     => $aud,          // ← JSON array (cast dans le modèle)
            'uploaded_by'     => $user->id,
        ]);

        return response()->json($doc->load(['projet','uploadedBy','reviewedBy']), 201);
    }

    // =========================
    // SHOW / UPDATE / DELETE
    // =========================
    public function show(Document $document)
    {
        return $document->load(['projet','uploadedBy','reviewedBy']);
    }

    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'name'         => ['sometimes','string','max:255'],
            'type'         => ['sometimes','string','max:50'],
            'path'         => ['sometimes','string'],
            'status'       => ['sometimes', Rule::in(['brouillon','en_attente','valide','rejete'])],
            'shared_with'  => ['sometimes','array','min:1'],
            'shared_with.*'=> [Rule::in([
                self::KEY_ADMIN_GENERAL,
                self::KEY_RESP_ADMIN,
                self::KEY_CTS,
                self::KEY_CT,
            ])],
        ]);

        $document->update($validated);
        return response()->json($document->load(['projet','uploadedBy','reviewedBy']));
    }

    public function destroy(Document $document)
    {
        $document->delete();
        return response()->json(null, 204);
    }

    // =========================
    // DÉCISION ADMIN (valider / rejeter + commentaire)
    // =========================
    public function adminReview(Document $document, Request $request)
    {
        $user = $request->user();
        if (($user->role->name ?? '') !== self::ROLE_ADMIN_GENERAL) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($document->validation_gate !== 'admin' || $document->status !== 'en_attente') {
            return response()->json(['message' => 'Document non éligible à la validation Admin.'], 400);
        }

        $data = $request->validate([
            'action'  => ['required', Rule::in(['valide','rejete'])],
            'comment' => ['nullable','string'],
        ]);

        $document->update([
            'status'         => $data['action'],
            'review_comment' => $data['comment'] ?? null,
            'reviewed_by'    => $user->id,
            'reviewed_at'    => now(),
        ]);

        return response()->json($document->load(['projet','uploadedBy','reviewedBy']));
    }

    // =========================
    // DÉCISION CTS (valider / rejeter) – si validé → partage auto RA + AdminG
    // =========================
    public function ctsReview(Document $document, Request $request)
    {
        $user = $request->user();
        if (($user->role->name ?? '') !== self::ROLE_CTS) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($document->validation_gate !== 'cts' || $document->status !== 'en_attente') {
            return response()->json(['message' => 'Document non éligible à la validation CTS.'], 400);
        }

        $data = $request->validate([
            'action'  => ['required', Rule::in(['valide','rejete'])],
            'comment' => ['nullable','string'],
        ]);

        $newShared = is_array($document->shared_with) ? $document->shared_with : [];
        if ($data['action'] === 'valide') {
            // Partage auto RA + AdminG
            $newShared = array_values(array_unique(array_merge($newShared, [
                self::KEY_RESP_ADMIN,
                self::KEY_ADMIN_GENERAL,
            ])));
        }

        $document->update([
            'status'         => $data['action'],
            'shared_with'    => $newShared,
            'review_comment' => $data['comment'] ?? null,
            'reviewed_by'    => $user->id,
            'reviewed_at'    => now(),
        ]);

        return response()->json($document->load(['projet','uploadedBy','reviewedBy']));
    }


    
}

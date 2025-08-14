<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Piece;
use App\Models\Projet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PieceController extends Controller
{
    private const ROLE_ADMIN_G   = 1;
    private const ROLE_RESP_ADMIN= 2;

    /* ------------------------- Helpers rôles ------------------------- */
    private function isAdminG(Request $r): bool
    {
        return (int) ($r->user()->role_id ?? 0) === self::ROLE_ADMIN_G;
    }
    private function isRespAdmin(Request $r): bool
    {
        return (int) ($r->user()->role_id ?? 0) === self::ROLE_RESP_ADMIN;
    }
    private function isAssignedUser(Piece $piece, Request $r): bool
    {
        return (int) ($piece->assigned_user_id ?? 0) === (int) $r->user()->id;
    }

    /* ---------------- Liste des pièces d’un projet ------------------- */
    // GET /api/projets/{projet}/pieces
    public function indexByProject(Request $r, Projet $projet)
    {
        $pieces = $projet->pieces()
            ->with(['assignee:id,name', 'projet:id,nom'])
            ->orderBy('obligatoire','desc')
            ->orderBy('id','asc')
            ->get();

        return response()->json($pieces);
    }

    /* ----------------------- Créer pièce (projet) -------------------- */
    // POST /api/projets/{projet}/pieces
    // Autorisé: AdminG (1) ou Responsable Administratif (2)
    public function storeForProject(Request $r, Projet $projet)
    {
        if (!$this->isAdminG($r) && !$this->isRespAdmin($r)) {
            return response()->json(['message' => "Action non autorisée."], 403);
        }

        // Cas bulk: pieces[]
        if ($r->has('pieces') && is_array($r->input('pieces'))) {
            $data = $r->validate([
                'pieces'               => ['required','array','min:1'],
                'pieces.*.nom'         => ['required','string','max:255'],
                'pieces.*.description' => ['nullable','string'],
                'pieces.*.obligatoire' => ['nullable','boolean'],
                'pieces.*.due_date'    => ['nullable','date','date_format:Y-m-d'],
            ]);

            $createdIds = [];
            foreach ($data['pieces'] as $row) {
                $createdIds[] = Piece::create([
                    'projet_id'        => $projet->id,
                    'nom'              => $row['nom'],
                    'description'      => $row['description'] ?? null,
                    'obligatoire'      => array_key_exists('obligatoire',$row) ? (bool)$row['obligatoire'] : true,
                    'due_date'         => $row['due_date'] ?? null,
                    'statut'           => 'A_IMPORTER',
                    'fichier_path'     => null,
                    'assigned_user_id' => null,
                    'assigned_by'      => null,
                    'motif_refus'      => null,
                ])->id;
            }

            $pieces = Piece::with('assignee:id,name')->whereIn('id',$createdIds)->get();
            return response()->json($pieces, 201);
        }

        // Cas unitaire
        $data = $r->validate([
            'nom'         => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'obligatoire' => ['nullable','boolean'],
            'due_date'    => ['nullable','date','date_format:Y-m-d'],
        ]);

        $piece = Piece::create([
            'projet_id'        => $projet->id,
            'nom'              => $data['nom'],
            'description'      => $data['description'] ?? null,
            'obligatoire'      => array_key_exists('obligatoire',$data) ? (bool)$data['obligatoire'] : true,
            'due_date'         => $data['due_date'] ?? null,
            'statut'           => 'A_IMPORTER',
            'fichier_path'     => null,
            'assigned_user_id' => null,
            'assigned_by'      => null,
            'motif_refus'      => null,
        ]);

        return response()->json($piece->load('assignee:id,name'), 201);
    }

    /* --------------------------- Show pièce -------------------------- */
    // GET /api/pieces/{piece}
    public function show(Piece $piece)
    {
        return response()->json($piece->load(['assignee:id,name', 'projet:id,nom']));
    }

    /* ---------------------------- Assign ----------------------------- */
    // PATCH /api/pieces/{piece}/assign
    // AdminG uniquement
    public function assign(Request $r, Piece $piece)
    {
        if (!$this->isAdminG($r)) {
            return response()->json(['message' => "Seul l’Admin Général peut assigner une pièce."], 403);
        }

        $data = $r->validate([
            'assigned_user_id' => ['nullable','exists:users,id'],
            'due_date'         => ['nullable','date'],
        ]);

        $piece->assigned_user_id = $data['assigned_user_id'] ?? null;
        $piece->due_date         = $data['due_date'] ?? null;
        $piece->assigned_by      = $r->user()->id;
        $piece->save();

        return response()->json($piece->load(['assignee:id,name']));
    }

    /* ---------------------------- Upload ----------------------------- */
    // POST /api/pieces/{piece}/upload
    // Seul l’utilisateur assigné peut uploader (AdminG NON requis ici)
    public function upload(Request $r, Piece $piece)
    {
        if (!$this->isAssignedUser($piece, $r)) {
            return response()->json(['message' => "Seul l’utilisateur assigné peut uploader ce fichier."], 403);
        }

        $r->validate([
            'fichier' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg',
        ], [
            'fichier.required' => 'Sélectionne un fichier.',
            'fichier.max'      => 'Taille max 20 Mo.',
            'fichier.mimes'    => 'Types autorisés: pdf, doc/docx, xls/xlsx, png, jpg/jpeg.',
        ]);

        $path = $r->file('fichier')->store("projets/{$piece->projet_id}/pieces", 'public');

        $piece->fichier_path = $path;
        $piece->uploaded_by  = $r->user()->id;
        $piece->statut       = 'EN_COURS';     // en attente de modération
        $piece->save();

        return response()->json($piece->load('assignee:id,name'));
    }

    /* ---------------------------- Valider ---------------------------- */
    // POST /api/pieces/{piece}/valider
    // AdminG uniquement
    public function valider(Request $r, Piece $piece)
    {
        if (!$this->isAdminG($r)) {
            return response()->json(['message' => "Seul l’Admin Général peut valider."], 403);
        }

        if (!$piece->fichier_path || !Storage::disk('public')->exists($piece->fichier_path)) {
            return response()->json(['message' => 'Aucun fichier à valider.'], 422);
        }

        $piece->statut = 'VALIDE';
        $piece->motif_refus = null;
        $piece->save();

        return response()->json($piece->load('assignee:id,name'));
    }

    /* ---------------------------- Refuser ---------------------------- */
    // POST /api/pieces/{piece}/refuser
    // AdminG uniquement + motif requis
    public function refuser(Request $r, Piece $piece)
    {
        if (!$this->isAdminG($r)) {
            return response()->json(['message' => "Seul l’Admin Général peut refuser."], 403);
        }

        $data = $r->validate([
            'motif' => ['required','string','min:3'],
        ]);

        $piece->statut = 'REFUSE';
        $piece->motif_refus = $data['motif'];
        $piece->save();

        return response()->json($piece->load('assignee:id,name'));
    }

    /* --------------------------- Download ---------------------------- */
    // GET /api/pieces/{piece}/download
    // Autorisé: AdminG ou l’utilisateur assigné
    public function download(Request $r, Piece $piece)
    {
        if (!$this->isAdminG($r) && !$this->isAssignedUser($piece, $r)) {
            return response()->json(['message' => "Non autorisé."], 403);
        }

        if (!$piece->fichier_path || !Storage::disk('public')->exists($piece->fichier_path)) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }

        $ext = pathinfo($piece->fichier_path, PATHINFO_EXTENSION);
        $base = $piece->nom ? Str::slug($piece->nom, '_') : 'piece';
        $filename = "{$base}__{$piece->id}." . ($ext ?: 'bin');

        return Storage::disk('public')->download($piece->fichier_path, $filename);
    }

    /* ---------------------------- Delete ----------------------------- */
    // DELETE /api/pieces/{piece} (optionnel) — AdminG uniquement
    public function destroy(Request $r, Piece $piece)
    {
        if (!$this->isAdminG($r)) {
            return response()->json(['message' => "Action non autorisée."], 403);
        }
        if ($piece->fichier_path && Storage::disk('public')->exists($piece->fichier_path)) {
            Storage::disk('public')->delete($piece->fichier_path);
        }
        $piece->delete();
        return response()->json(['message' => 'Pièce supprimée.']);
    }
}

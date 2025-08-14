<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller; // <-- ajout important
use App\Models\Demande;
use App\Models\DemandeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DemandeDocumentController extends Controller
{
    // ⚠️ ajuste ces IDs à TA table `roles`
    private const ROLE_ADMIN_GENERAL             = 1; // Admin Général
    private const ROLE_RESPONSABLE_ADMINISTRATIF = 2; // Responsable Administratif (RA)
    private const ROLE_CTS                       = 3; // Chef de Terrain Supérieur
    private const ROLE_CT                        = 4; // Chef de Terrain
    private const ROLE_CES                       = 5; // Chargé d'Études Supérieur

    /**
     * Ajouter un document à une demande existante.
     * Règle: si la demande est BRIEF, le doc ajouté doit être un brief.
     */
    public function store(Request $r, int $demandeId)
    {
        $data = $r->validate([
            'nom'      => ['required','string','max:255'],
            'is_brief' => ['nullable','boolean'],
        ]);

        $demande = Demande::findOrFail($demandeId);

        // Comparaison en string (pas d'enum)
        if ($demande->type === 'BRIEF' && !($data['is_brief'] ?? false)) {
            return response()->json(['message' => 'Pour une demande BRIEF, le document doit être un brief.'], 422);
        }

        $doc = DemandeDocument::create([
            'demande_id' => $demande->id,
            'nom'        => $data['nom'],
            'is_brief'   => (bool)($data['is_brief'] ?? false),
        ]);

        return response()->json($doc, 201);
    }

    /**
     * Upload du fichier (disk "public")
     * - brief => CES ou Admin Général
     * - non-brief => RA ou Admin Général
     */
    public function upload(Request $r, int $documentId)
{
    $r->validate(['fichier' => ['required','file','max:20480']]); // 20MB
    $doc = DemandeDocument::with('demande')->findOrFail($documentId);

    $roleId = auth()->user()->role_id;

    if ($doc->is_brief) {
        // ✅ NOUVELLE RÈGLE: RA peut téléverser un BRIEF (mais ne peut pas le valider/refuser)
        if (!in_array($roleId, [
            self::ROLE_CES,
            self::ROLE_ADMIN_GENERAL,
            self::ROLE_RESPONSABLE_ADMINISTRATIF, // <-- ajouté
        ], true)) {
            abort(403, 'Seuls CES, RA ou Admin Général peuvent téléverser un brief.');
        }
    } else {
        // Non-brief : inchangé (RA ou AdminG)
        if (!in_array($roleId, [self::ROLE_RESPONSABLE_ADMINISTRATIF, self::ROLE_ADMIN_GENERAL], true)) {
            abort(403, 'Seul le Responsable Administratif ou Admin Général peut téléverser ce document.');
        }
    }

    $path = $r->file('fichier')->store("demandes/{$doc->demande_id}/documents", 'public');

    $doc->update([
    'fichier_path' => $path,
    'statut'       => 'EN_COURS',
    'uploaded_by'  => auth()->id(),
]);

$this->recalcDemandeStatut($doc->demande_id); // 👈 ajouter ceci

return response()->json($doc->fresh());

}

 


    /**
     * Mes tâches selon mon rôle
     */
    public function mesTaches()
    {
        $roleId = auth()->user()->role_id;

        $q = DemandeDocument::query()->with('demande.client');

        if ($roleId === self::ROLE_CES) {
            $q->where('is_brief', true)->where('statut','!=','VALIDE');
        } elseif ($roleId === self::ROLE_RESPONSABLE_ADMINISTRATIF) {
            $q->where(function($qq){ $qq->whereNull('is_brief')->orWhere('is_brief', false); })
              ->where('statut','!=','VALIDE');
        } elseif ($roleId === self::ROLE_ADMIN_GENERAL) {
            // admin voit tout
        } else {
            abort(403, 'Aucune tâche pour ce rôle.');
        }

        return response()->json($q->orderByDesc('id')->get());
    }

    // Valider le document (brief => CES/AdminG ; non-brief => RA/AdminG)
public function valider(int $documentId)
{
    $doc = DemandeDocument::with('demande')->findOrFail($documentId);
    $roleId = auth()->user()->role_id;

    if ($doc->is_brief) {
        if (!in_array($roleId, [self::ROLE_CES, self::ROLE_ADMIN_GENERAL], true)) abort(403);
    } else {
        if (!in_array($roleId, [self::ROLE_RESPONSABLE_ADMINISTRATIF, self::ROLE_ADMIN_GENERAL], true)) abort(403);
    }

    $doc->update([
    'statut' => 'VALIDE',
    'motif_refus' => null,
]);

$this->recalcDemandeStatut($doc->demande_id); // 👈 ajouter

return response()->json($doc->fresh());
}

// Refuser le document (motif requis)
public function refuser(Request $r, int $documentId)
{
    $data = $r->validate(['motif' => ['required','string','max:2000']]);

    $doc = DemandeDocument::with('demande')->findOrFail($documentId);
    $roleId = auth()->user()->role_id;

    if ($doc->is_brief) {
        if (!in_array($roleId, [self::ROLE_CES, self::ROLE_ADMIN_GENERAL], true)) abort(403);
    } else {
        if (!in_array($roleId, [self::ROLE_RESPONSABLE_ADMINISTRATIF, self::ROLE_ADMIN_GENERAL], true)) abort(403);
    }

    $doc->update([
    'statut' => 'REFUSE',
    'motif_refus' => $data['motif'],
]);

$this->recalcDemandeStatut($doc->demande_id); // 👈 ajouter

return response()->json($doc->fresh());
}

private function recalcDemandeStatut(int $demandeId): void
{
    $d = Demande::withCount([
        'documents as total_docs',
        'documents as valides_count' => function ($q) { $q->where('statut', 'VALIDE'); },
        'documents as refuses_count' => function ($q) { $q->where('statut', 'REFUSE'); },
    ])->find($demandeId);

    if (!$d || $d->total_docs === 0) return;

    $new = 'EN_COURS';
    if ($d->valides_count === $d->total_docs) {
        $new = 'TERMINEE';
    } elseif ($d->refuses_count === $d->total_docs) {
        $new = 'BROUILLON';
    }

    if ($d->statut !== $new) {
        $d->statut = $new;
        $d->save();
    }
}


}

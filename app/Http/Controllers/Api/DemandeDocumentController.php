<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Demande;
use App\Models\DemandeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str; // ✅ Import ajouté

class DemandeDocumentController extends Controller
{
    /**
     * Ajouter un document à une demande existante.
     * - Plus AUCUNE contrainte de type (BRIEF / APPEL_OFFRE).
     * - Si un fichier est fourni dans la même requête, le document est créé directement VALIDÉ.
     *
     * FormData possible :
     *  - nom (string)
     *  - is_brief (bool, optionnel)
     *  - fichier (file, optionnel)
     */
    public function store(Request $r, int $demandeId)
    {
        $data = $r->validate([
            'nom'      => ['required','string','max:255'],
            'is_brief' => ['nullable','boolean'],
            'fichier'  => ['sometimes','file','max:20480'], // 20MB
        ]);

        $demande = Demande::findOrFail($demandeId);

        $path = null;
        $uploadedBy = null;
        $statut = 'EN_COURS';

        if ($r->hasFile('fichier')) {
            $path = $r->file('fichier')->store("demandes/{$demande->id}/documents", 'public');
            $uploadedBy = auth()->id();
            $statut = 'VALIDE'; // ✅ auto-validation si fichier fourni
        }

        $doc = DemandeDocument::create([
            'demande_id'   => $demande->id,
            'nom'          => $data['nom'],
            'is_brief'     => (bool)($data['is_brief'] ?? false),
            'fichier_path' => $path,
            'uploaded_by'  => $uploadedBy,
            'statut'       => $statut,
            'motif_refus'  => null,
        ]);

        // recalcul de statut demande
        $this->recalcDemandeStatut($demande->id);

        return response()->json($doc->fresh(), 201);
    }

    /**
     * Upload (ou ré-upload) d'un fichier pour un document.
     * - Aucune restriction de rôle.
     * - Le document passe automatiquement à VALIDE.
     */
    public function upload(Request $r, int $documentId)
    {
        $r->validate(['fichier' => ['required','file','max:20480']]); // 20MB

        $doc = DemandeDocument::with('demande')->findOrFail($documentId);

        $path = $r->file('fichier')->store("demandes/{$doc->demande_id}/documents", 'public');

        $doc->update([
            'fichier_path' => $path,
            'statut'       => 'VALIDE',     // ✅ auto-validation
            'uploaded_by'  => auth()->id(),
            'motif_refus'  => null,
        ]);

        $this->recalcDemandeStatut($doc->demande_id);

        return response()->json($doc->fresh());
    }

    /**
     * Mes tâches : plus de workflow d'approbation => liste vide.
     */
    public function mesTaches()
    {
        return response()->json([]);
    }

    /**
     * (DÉSACTIVÉ) Valider un document — workflow supprimé.
     */
    public function valider(int $documentId)
    {
        return response()->json(['message' => 'Workflow de validation désactivé.'], 405);
    }

    /**
     * (DÉSACTIVÉ) Refuser un document — workflow supprimé.
     */
    public function refuser(Request $r, int $documentId)
    {
        return response()->json(['message' => 'Workflow de validation désactivé.'], 405);
    }

    /**
     * Recalcule le statut de la demande :
     * - TERMINEE si tous les docs sont VALIDE
     * - BROUILLON si tous REFUSE (cas théorique si tu réactives refuser)
     * - EN_COURS sinon
     */
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

    /** Télécharger le fichier d'un document de demande (stream sécurisé) */
    public function download(int $documentId)
    {
        $doc = DemandeDocument::findOrFail($documentId);

        if (empty($doc->fichier_path)) {
            return response()->json(['message' => 'Aucun fichier pour ce document.'], 404);
        }

        $disk = $this->pickDiskFor($doc->fichier_path);
        if (!Storage::disk($disk)->exists($doc->fichier_path)) {
            return response()->json(['message' => 'Fichier introuvable sur le disque.'], 404);
        }

        // Nom de fichier lisible
        $ext      = pathinfo($doc->fichier_path, PATHINFO_EXTENSION);
        $basename = trim((string)$doc->nom) !== '' ? $doc->nom : ('document_'.$doc->id);
        $safe     = Str::slug($basename, '_'); // ✅ Str maintenant disponible
        $filename = $safe.($ext ? '.'.$ext : '');

        // Stream de téléchargement avec Content-Disposition
        return Storage::disk($disk)->download($doc->fichier_path, $filename);
    }

    /** URL publique si le fichier est sur le disk "public" (option pratique pour lien direct) */
    public function publicUrl(int $documentId)
    {
        $doc = DemandeDocument::findOrFail($documentId);
        if (empty($doc->fichier_path)) {
            return response()->json(['message' => 'Aucun fichier pour ce document.'], 404);
        }

        $disk = $this->pickDiskFor($doc->fichier_path);
        if ($disk !== 'public') {
            // Pas d'URL publique pour ce disk ; on invite à utiliser /download
            return response()->json(['message' => 'Pas d\'URL publique disponible pour ce fichier.'], 422);
        }

        return response()->json(['url' => Storage::disk('public')->url($doc->fichier_path)]);
    }

    /** Utilitaire pour choisir le bon disk en fonction du chemin stocké */
    private function pickDiskFor(string $relativePath): string
    {
        if (Storage::disk('public')->exists($relativePath)) return 'public';
        if (Storage::disk('local')->exists($relativePath))  return 'local';
        return config('filesystems.default', 'local');
    }
}
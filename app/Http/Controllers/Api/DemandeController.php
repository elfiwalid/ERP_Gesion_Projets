<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Demande;
use App\Models\DemandeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DemandeController extends Controller
{
    // ⚠️ ajuste ces IDs à TA table `roles` si tu les utilises plus tard ici
    private const ROLE_ADMIN_GENERAL             = 1; // Admin Général
    private const ROLE_RESPONSABLE_ADMINISTRATIF = 2; // Responsable Administratif (RA)
    private const ROLE_CTS                       = 3; // Chef de Terrain Supérieur
    private const ROLE_CT                        = 4; // Chef de Terrain
    private const ROLE_CES                       = 5; // Chargé d'Études Supérieur

    /**
     * Créer une demande (BRIEF ou APPEL_OFFRE) + (optionnel) ses documents.
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'client_id'            => ['required','exists:clients,id'],
            'type'                 => ['required','in:BRIEF,APPEL_OFFRE'],
            'intitule'             => ['nullable','string','max:255'],
            'description'          => ['nullable','string'],
            'documents'            => ['sometimes','array','min:1'],
            'documents.*.nom'      => ['required_with:documents','string','max:255'],
            'documents.*.is_brief' => ['nullable','boolean'],
        ]);

        // Contrôles payload
        $docs = collect($data['documents'] ?? []);
        $briefCount    = $docs->where('is_brief', true)->count();
        $nonBriefCount = $docs->where('is_brief', '!=', true)->count();

        if ($data['type'] === 'BRIEF') {
            if ($docs->isNotEmpty() && ($briefCount < 1 || $nonBriefCount > 0)) {
                return response()->json(['message' => 'Type BRIEF: tous les documents doivent être des briefs (au moins 1).'], 422);
            }
        } else { // APPEL_OFFRE
            if ($docs->isNotEmpty() && $briefCount < 1) {
                return response()->json(['message' => 'APPEL_OFFRE: inclure au moins un document brief.'], 422);
            }
        }

        $demande = DB::transaction(function () use ($data, $docs) {
            $d = Demande::create([
                'client_id'   => $data['client_id'],
                'type'        => $data['type'], // string
                'intitule'    => $data['intitule'] ?? null,
                'description' => $data['description'] ?? null,
                'statut'      => 'EN_COURS',
                'cree_par'    => auth()->id(),
            ]);

            foreach ($docs as $doc) {
                DemandeDocument::create([
                    'demande_id' => $d->id,
                    'nom'        => $doc['nom'],
                    'is_brief'   => (bool)($doc['is_brief'] ?? false),
                ]);
            }

            return $d->load(['client','documents'])->loadCount('documents');
        });

        return response()->json($demande, 201);
    }

    /**
     * Lister les demandes (filtres optionnels)
     */
    public function index(Request $r)
{
    $q = Demande::query()
        ->with('client')
        ->withCount('documents'); // <-- ajoute le count

    if ($r->filled('client_id')) $q->where('client_id', (int) $r->get('client_id'));
    if ($r->filled('type'))      $q->where('type', $r->get('type'));
    if ($r->filled('statut'))    $q->where('statut', $r->get('statut'));

    return $q->orderByDesc('id')->paginate(20);
}


// Liste des demandes TERMINEE (éligibles). Filtrable par ?client_id=
public function eligibles(Request $r)
{
    $q = \App\Models\Demande::query()
        ->with('client')
        ->where('statut', 'TERMINEE');

    if ($r->filled('client_id')) {
        $q->where('client_id', (int)$r->get('client_id'));
    }

    return $q->orderByDesc('id')->paginate(20);
}

// Alias par chemin /clients/{id}/demandes/eligibles
public function eligiblesByClient(int $clientId, Request $r)
{
    $r->merge(['client_id' => $clientId]);
    return $this->eligibles($r);
}

    /**
     * Détail d’une demande
     */
    public function show(int $id)
{
    // détail avec documents + count
    return Demande::with(['client','documents'])
        ->withCount('documents')
        ->findOrFail($id);
}
}

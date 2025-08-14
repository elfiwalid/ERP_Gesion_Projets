<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Demande;
use App\Models\Piece;
use App\Models\Projet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjetController extends Controller
{
    private const ROLE_ADMIN_GENERAL             = 1;
    private const ROLE_RESPONSABLE_ADMINISTRATIF = 2;

    public function index(Request $r)
    {
        $q = Projet::query()->with(['client','demande'])
            ->when($r->filled('client_id'), fn($qq) => $qq->where('client_id', (int)$r->get('client_id')))
            ->when($r->filled('statut'),    fn($qq) => $qq->where('statut', $r->get('statut')))
            ->orderByDesc('id');

        return $q->paginate(20);
    }

    public function show(int $id)
    {
        return Projet::with(['client','demande','pieces'])->findOrFail($id);
    }

    /** Création: demande_id ET client_id requis */
    public function store(Request $r)
    {
        $user = $r->user();
        if (!in_array($user->role_id, [self::ROLE_ADMIN_GENERAL, self::ROLE_RESPONSABLE_ADMINISTRATIF], true)) {
            abort(403, "Seuls l’Admin Général ou le Responsable Administratif peuvent créer un projet.");
        }

        $data = $r->validate([
            'client_id'        => ['required','integer','exists:clients,id'],
            'demande_id'       => ['required','integer','exists:demandes,id'],
            'nom'              => ['required','string','max:255'],
            'date_debut'       => ['nullable','date','date_format:Y-m-d'],
            'date_fin_prevue'  => ['required','date','date_format:Y-m-d','after_or_equal:date_debut'],
            'pieces'                   => ['sometimes','array','min:1'],
            'pieces.*.nom'             => ['required_with:pieces','string','max:255'],
            'pieces.*.description'     => ['nullable','string'],
            'pieces.*.obligatoire'     => ['nullable','boolean'],
            'pieces.*.due_date'        => ['nullable','date','date_format:Y-m-d'],
        ]);

        $projet = DB::transaction(function () use ($data, $user) {

            $client   = Client::findOrFail((int)$data['client_id']);
            $demande  = Demande::with('client')->findOrFail((int)$data['demande_id']);

            // 1) Demande éligible
            if ($demande->statut !== 'TERMINEE') {
                abort(422, "La demande #{$demande->id} n’est pas éligible (statut: {$demande->statut}).");
            }

            // 2) Coherence client/demande
            if ((int)$demande->client_id !== (int)$client->id) {
                abort(422, "La demande sélectionnée n’appartient pas au client choisi.");
            }

            // 3) Création projet
            $p = Projet::create([
                'client_id'       => $client->id,
                'demande_id'      => $demande->id,
                'nom'             => $data['nom'],
                'date_debut'      => $data['date_debut'] ?? null,
                'date_fin_prevue' => $data['date_fin_prevue'],
                'statut'          => 'EN_VALIDATION', // adapter si besoin
                'archived_at'     => null,
                'cree_par'        => $user->id,
            ]);

            // 4) Pièces à fournir
            foreach (($data['pieces'] ?? []) as $row) {
                Piece::create([
                    'projet_id'        => $p->id,
                    'nom'              => $row['nom'],
                    'description'      => $row['description'] ?? null,
                    'obligatoire'      => array_key_exists('obligatoire', $row) ? (bool)$row['obligatoire'] : true,
                    'statut'           => 'A_IMPORTER',
                    'fichier_path'     => null,
                    'uploaded_by'      => null,
                    'assigned_user_id' => null,
                    'assigned_by'      => null,
                    'due_date'         => $row['due_date'] ?? null,
                ]);
            }

            return $p->load(['client','demande','pieces']);
        });

        return response()->json($projet, 201);
    }
}

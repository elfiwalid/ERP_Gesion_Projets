<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Demande;
use App\Models\Piece;
use App\Models\Projet;
use App\Models\ProjectApproval;
use Illuminate\Http\Request;
use App\Models\EstimationState;
use App\Models\EstimationAudit;
use App\Models\FinancialOffer;
use App\Models\FinancialOfferItem;
use App\Models\EstimationBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ProjetController extends Controller
{
    // IDs de rôles (adapte si besoin)
    private const ADMIN_G    = 1; // Admin Général
    private const RESP_ADMIN = 2; // RA
    private const CHEF_SUP   = 3; // Chef Terrain Supérieur
    private const CHEF       = 4; // Chef Terrain
    private const CHARGE_SUP = 5; // Chargé d’études Supérieur

    private const TECH_OFFER_NAME = 'Offre technique';

    /** Rôles ayant accès aux projets EN_VALIDATION */
    private function enValidationVisibleRoles(): array
    {
        return [self::ADMIN_G, self::RESP_ADMIN, self::CHEF_SUP, self::CHARGE_SUP];
    }

    /** Rôles approbateurs */
    private function approverRoles(): array
    {
        return [self::ADMIN_G, self::CHEF_SUP, self::CHARGE_SUP];
    }

    /** Util dans show() */
    private function assertCanSeeProject(Request $r, Projet $p): void
    {
        if ($p->statut === 'EN_VALIDATION') {
            $u = $r->user();
            if (!in_array((int)$u->role_id, $this->enValidationVisibleRoles(), true)) {
                abort(403, "Projet en cours de validation — accès restreint.");
            }
        }
    }

    /** Liste — filtre visibilité + recherche + stats pièces */
    public function index(Request $r)
    {
        $u = $r->user();

        $q = Projet::query()
            ->with(['client','demande'])
            ->withCount([
                'pieces as pieces_count',
                'pieces as pieces_valides_count' => function ($qq) {
                    $qq->where('statut', 'VALIDE');
                },
            ])
            ->when($r->filled('client_id'), fn($qq) => $qq->where('client_id', (int)$r->get('client_id')))
            ->when($r->filled('statut'),    fn($qq) => $qq->where('statut', $r->get('statut')))
            ->when($r->filled('search'), function ($qq) use ($r) {
                $s = trim($r->get('search'));
                $qq->where(function ($w) use ($s) {
                    $w->where('nom', 'like', "%{$s}%")
                      ->orWhereHas('client', fn($wc) => $wc->where('raison_sociale','like',"%{$s}%"));
                });
            })
            ->orderByDesc('id');

        if (!in_array((int)$u->role_id, $this->enValidationVisibleRoles(), true)) {
            $q->where('statut', '!=', 'EN_VALIDATION');
        }

        return $q->paginate(20);
    }

    /** Détail projet */
    public function show(Request $r, int $id)
    {
        $p = Projet::with([
                'client','demande',
                'pieces.assignee:id,name',
                'approvals.decider:id,name'
            ])
            ->findOrFail($id);

        $this->assertCanSeeProject($r, $p);

        return $p;
    }

    /** Pièces d’un projet */
    public function pieces(Request $r, int $id)
    {
        $p = Projet::findOrFail($id);
        $this->assertCanSeeProject($r, $p);

        return Piece::with(['assignee:id,name','projet:id,nom'])
            ->where('projet_id', $p->id)
            ->orderBy('obligatoire','desc')
            ->orderBy('id')
            ->get();
    }

    /** Création projet */
    public function store(Request $r)
    {
        $user = $r->user();
        if (!in_array((int)$user->role_id, [self::ADMIN_G, self::RESP_ADMIN], true)) {
            abort(403, "Seuls l’Admin Général ou le Responsable Administratif peuvent créer un projet.");
        }

        $data = $r->validate([
            'client_id'        => ['required','integer','exists:clients,id'],
            'demande_id'       => ['required','integer','exists:demandes,id'],
            'nom'              => ['required','string','max:255'],
            'date_debut'       => ['nullable','date','date_format:Y-m-d'],
            'date_fin_prevue'  => ['required','date','date_format:Y-m-d','after_or_equal:date_debut'],

            'pieces'               => ['sometimes','array','min:1'],
            'pieces.*.nom'         => ['required_with:pieces','string','max:255'],
            'pieces.*.description' => ['nullable','string'],
            'pieces.*.obligatoire' => ['nullable','boolean'],
            'pieces.*.due_date'    => ['nullable','date','date_format:Y-m-d'],
        ]);

        $projet = DB::transaction(function () use ($data, $user) {
            $client  = Client::findOrFail((int)$data['client_id']);
            $demande = Demande::with('client')->findOrFail((int)$data['demande_id']);

            if ($demande->statut !== 'TERMINEE') {
                abort(422, "La demande #{$demande->id} n’est pas éligible (statut: {$demande->statut}).");
            }
            if ((int)$demande->client_id !== (int)$client->id) {
                abort(422, "La demande sélectionnée n’appartient pas au client choisi.");
            }

            $p = Projet::create([
                'client_id'       => $client->id,
                'demande_id'      => $demande->id,
                'nom'             => $data['nom'],
                'date_debut'      => $data['date_debut'] ?? null,
                'date_fin_prevue' => $data['date_fin_prevue'],
                'statut'          => 'EN_VALIDATION',
                'archived_at'     => null,
                'cree_par'        => $user->id,
            ]);

            foreach (($data['pieces'] ?? []) as $row) {
                Piece::create([
                    'projet_id'        => $p->id,
                    'nom'              => $row['nom'],
                    'description'      => $row['description'] ?? null,
                    'obligatoire'      => array_key_exists('obligatoire',$row) ? (bool)$row['obligatoire'] : true,
                    'statut'           => 'A_IMPORTER',
                    'fichier_path'     => null,
                    'uploaded_by'      => null,
                    'assigned_user_id' => null,
                    'assigned_by'      => null,
                    'due_date'         => $row['due_date'] ?? null,
                ]);
            }

            foreach ($this->approverRoles() as $rid) {
                ProjectApproval::create([
                    'projet_id' => $p->id,
                    'role_id'   => $rid,
                    'decision'  => 'PENDING',
                ]);
            }

            // NOTE: plus de pré-création finance ici

            return $p->load(['client','demande','pieces','approvals']);
        });

        return response()->json($projet, 201);
    }

    /** Lister approbations */
    public function approvals(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        return $projet->load(['approvals.decider'])->approvals;
    }

    /** Décision approbateur */
    public function decide(Request $r, Projet $projet)
    {
        $u = $r->user();
        if (!in_array((int)$u->role_id, $this->approverRoles(), true)) {
            return response()->json(['message' => 'Seuls les approbateurs peuvent décider.'], 403);
        }

        if ($projet->statut !== 'EN_VALIDATION') {
            return response()->json(['message' => 'Projet déjà décidé.'], 422);
        }

        $data = $r->validate([
            'decision' => ['required', Rule::in(['APPROUVE','REFUSE'])],
            'motif'    => ['nullable','string','min:3'],
        ]);

        DB::transaction(function () use ($projet, $u, $data) {
            // 🔒 Verrouille toutes les approbations du projet
            $approvals = ProjectApproval::where('projet_id', $projet->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('role_id');

            $mine = $approvals->get($u->role_id);
            if (!$mine) abort(404, "Ligne d'approbation introuvable.");

            // 👉 Détection 2ᵉ tour pour MOI : ma ligne est revenue à PENDING avec un motif "technique"
            $isSecondRoundForMe = ($mine->decision === 'PENDING') && !empty($mine->motif);

            // 👉 Règle "motif"
            $needsMotif = ($data['decision'] === 'REFUSE') || $isSecondRoundForMe;
            if ($needsMotif && (!isset($data['motif']) || mb_strlen(trim($data['motif'])) < 3)) {
                abort(422, "Un motif (≥ 3 caractères) est requis pour cette décision.");
            }

            // ✍️ Enregistrer MA décision
            $mine->update([
                'decision'   => $data['decision'],
                'motif'      => $data['motif'] ?? null,
                'decided_by' => $u->id,
                'decided_at' => Carbon::now(),
            ]);

            // ♻️ Relecture et détection de conflit (mix APPROUVE + REFUSE)
            $all      = ProjectApproval::where('projet_id', $projet->id)->lockForUpdate()->get();
            $approved = $all->where('decision', 'APPROUVE');
            $refused  = $all->where('decision', 'REFUSE');

            if ($approved->count() > 0 && $refused->count() > 0) {
                // Remettre les AUTRES (pas moi) à PENDING pour re-décider
                ProjectApproval::where('projet_id', $projet->id)
                    ->where('role_id', '!=', $u->role_id)
                    ->whereIn('decision', ['APPROUVE', 'REFUSE'])
                    ->update([
                        'decision'   => 'PENDING',
                        'motif'      => 'Interaction requise suite à des décisions conflictuelles',
                        'decided_by' => null,
                        'decided_at' => null,
                        'updated_at' => Carbon::now(),
                    ]);

                // Le projet reste en validation
                $projet->update(['statut' => 'EN_VALIDATION']);
                return;
            }

            // ✅ États finaux (tous APPROUVE / tous REFUSE) sinon EN_VALIDATION
            $final       = ProjectApproval::where('projet_id', $projet->id)->get();
            $allApproved = $final->every(fn($a) => $a->decision === 'APPROUVE');
            $allRefused  = $final->every(fn($a) => $a->decision === 'REFUSE');

            if ($allApproved) {
                $projet->update([
                    'statut'      => 'EN_COURS',
                    'archived_at' => null,
                ]);
            } elseif ($allRefused) {
                $projet->update([
                    'statut'      => 'ARCHIVE',
                    'archived_at' => Carbon::now(),
                ]);
            } else {
                $projet->update(['statut' => 'EN_VALIDATION']);
            }
        });

        return $projet->fresh()->load(['approvals.decider']);
    }

    /** Changer le statut — AdminG uniquement */
    public function updateStatut(Request $r, Projet $projet)
    {
        if ((int)$r->user()->role_id !== self::ADMIN_G) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        $data = $r->validate([
            'statut' => ['required', Rule::in(['EN_VALIDATION','EN_COURS','CLOTURE','ARCHIVE'])],
        ]);

        $attrs = ['statut' => $data['statut']];
        if ($data['statut'] === 'ARCHIVE') {
            $attrs['archived_at'] = Carbon::now();
        }
        if ($data['statut'] === 'EN_COURS') {
            $attrs['archived_at'] = null;
        }

        $projet->update($attrs);
        return $projet->fresh();
    }

    /** Choisit le disk selon l’emplacement réel d’un path relatif */
    private function pickDiskFor(string $relativePath): string
    {
        if (Storage::disk('public')->exists($relativePath)) {
            return 'public';
        }
        if (Storage::disk('local')->exists($relativePath)) {
            return 'local';
        }
        return config('filesystems.default', 'local');
    }

    /** Télécharger la pièce "Offre technique" validée */
    public function downloadTechOffer(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);

        $piece = Piece::where('projet_id', $projet->id)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower(self::TECH_OFFER_NAME)])
            ->where('statut', 'VALIDE')
            ->whereNotNull('fichier_path')
            ->first();

        if (!$piece) {
            return response()->json([
                'message' => "Aucune 'Offre technique' validée avec fichier pour ce projet."
            ], 404);
        }

        $disk = $this->pickDiskFor($piece->fichier_path);
        if (!Storage::disk($disk)->exists($piece->fichier_path)) {
            return response()->json(['message' => 'Fichier introuvable sur le stockage.'], 404);
        }

        $safeName = 'Offre_technique_projet_'.$projet->id.'_'.basename($piece->fichier_path);
        return Storage::disk($disk)->download($piece->fichier_path, $safeName);
    }



    // ==========================================================
// ===================  ESTIMATION  =========================
// ==========================================================

private function ensureEstimationState(Projet $p): EstimationState
{
    return EstimationState::firstOrCreate(['projet_id' => $p->id], ['statut' => 'NONE']);
}

private function computeTechOk(Projet $p): bool
{
    return \App\Models\Piece::where('projet_id', $p->id)
        ->whereRaw('LOWER(nom) = ?', [mb_strtolower(self::TECH_OFFER_NAME)])
        ->where('statut', 'VALIDE')
        ->whereNotNull('fichier_path')
        ->exists();
}

/* AdminG NE VOIT PAS tant que le Chef Sup n’a pas validé (statut doit être PENDING_ADMIN ou APPROVED) */
private function assertEstimationVisibility(Request $r, EstimationState $es): void
{
    $role = (int) $r->user()->role_id;

    // Règle: AdminG ne voit pas tant que Chef Sup n’a pas validé,
    // MAIS il doit pouvoir voir une estimation REFUSED.
    if ($role === self::ADMIN_G && !in_array($es->statut, ['PENDING_ADMIN','APPROVED','REFUSED'], true)) {
        abort(403, "Estimation non disponible (en attente de validation du Chef Terrain Sup).");
    }
}


private function snapshotItems(int $projetId): array
{
    return \App\Models\EstimationBudget::where('projet_id',$projetId)
        ->orderBy('position')->orderBy('id')
        ->get(['id','label','amount','position'])
        ->map(fn($r)=>$r->toArray())
        ->all();
}

private function writeAudit(int $projetId, int $actorId, int $roleId, string $action, ?array $old=null, ?array $new=null, ?string $motif=null): void
{
    \App\Models\EstimationAudit::create([
        'projet_id'     => $projetId,
        'actor_id'      => $actorId,
        'actor_role_id' => $roleId,
        'action'        => $action,
        'old_json'      => $old,
        'new_json'      => $new,
        'motif'         => $motif,
    ]);
}

/** GET: voir l’estimation */
public function estimationShow(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    if ($projet->statut !== 'EN_COURS') {
        return response()->json(['message'=>"Projet non EN_COURS."], 422);
    }

    $es = $this->ensureEstimationState($projet);
    $this->assertEstimationVisibility($r, $es);

    // ⚠️ on ne bloque plus l’affichage si tech pas OK ; on expose l’info.
    $techOk = $this->computeTechOk($projet);

    $items = \App\Models\EstimationBudget::where('projet_id',$projet->id)
        ->orderBy('position')->orderBy('id')
        ->get(['id','label','amount','position']);

    return response()->json([
        'tech_ok' => $techOk,
        'estimation' => [
            'statut'            => $es->statut ?? 'NONE',
            'motif_refus'       => $es->motif_refus,
            'chef_approved_by'  => $es->chef_approved_by,
            'chef_approved_at'  => optional($es->chef_approved_at)->toIso8601String(),
            'admin_approved_by' => $es->admin_approved_by,
            'admin_approved_at' => optional($es->admin_approved_at)->toIso8601String(),
        ],
        'items' => $items->map(function ($it) {
            return [
                'id'       => (int) $it->id,
                'label'    => (string) $it->label,
                'amount'   => (float) $it->amount,
                'position' => (int) $it->position,
            ];
        })->values(),
        'total' => (float) $items->sum('amount'),
    ]);
}


/** GET: audits */
public function estimationAudits(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $es = $this->ensureEstimationState($projet);
    // Même garde que show() => inclut REFUSED pour AdminG
    $this->assertEstimationVisibility($r, $es);

    $audits = \App\Models\EstimationAudit::where('projet_id',$projet->id)
        ->orderByDesc('id')->limit(100)->get();

    return response()->json($audits);
}

/** POST: enregistrer/remplacer toutes les lignes
 *  RÈGLE IMPORTANTE:
 *  - Chef (4)       => statut PENDING_CHEF
 *  - Chef Sup (3)   => statut PENDING_CHEF tant qu'IL N’A PAS VALIDÉ (AdminG ne doit pas voir)
 *  - AdminG (1)     => statut APPROVED
 */
public function estimationSave(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $u = $r->user(); $role = (int) $u->role_id;

    if (!in_array($role, [self::CHEF, self::CHEF_SUP, self::ADMIN_G], true)) {
        return response()->json(['message'=>'Non autorisé.'], 403);
    }
    if ($projet->statut !== 'EN_COURS') {
        return response()->json(['message'=>'Projet non EN_COURS.'], 422);
    }
    if (!$this->computeTechOk($projet)) {
        return response()->json(['message'=>"L'Offre technique n'est pas validée."], 422);
    }

    $data = $r->validate([
        'items' => ['required','array','min:1'],
        'items.*.label'  => ['required','string','max:255'],
        'items.*.amount' => ['required','numeric','min:0'],
    ]);

    $old = $this->snapshotItems($projet->id);

    DB::transaction(function () use ($data, $projet, $u, $role, $old) {
        \App\Models\EstimationBudget::where('projet_id',$projet->id)->delete();

        $pos = 1;
        foreach ($data['items'] as $row) {
            \App\Models\EstimationBudget::create([
                'projet_id'  => $projet->id,
                'label'      => trim($row['label']),
                'amount'     => (float)$row['amount'],
                'position'   => $pos++,
                'created_by' => $u->id,
                'updated_by' => $u->id,
            ]);
        }

        $es = $this->ensureEstimationState($projet);
        $next = $es->statut;

        if ($role === self::CHEF) {
            $next = 'PENDING_CHEF';
        } elseif ($role === self::CHEF_SUP) {
            // ⚠️ on RESTE en PENDING_CHEF tant que Chef Sup n'a pas cliqué "Valider"
            $next = 'PENDING_CHEF';
        } elseif ($role === self::ADMIN_G) {
            $next = 'APPROVED';
        }

        $es->update([
            'statut'                 => $next,
            'motif_refus'            => null,
            'uploaded_by'            => $u->id,
            'uploaded_by_role_id'    => $role,
            'uploaded_at'            => \Carbon\Carbon::now(),
            // ne pas marquer approuvé Chef/Admin ici (seulement via estimationApprove)
            'chef_approved_by'       => ($role===self::ADMIN_G ? $es->chef_approved_by : $es->chef_approved_by),
            'chef_approved_at'       => ($role===self::ADMIN_G ? $es->chef_approved_at : $es->chef_approved_at),
            'admin_approved_by'      => ($role===self::ADMIN_G ? $u->id : null),
            'admin_approved_at'      => ($role===self::ADMIN_G ? \Carbon\Carbon::now() : null),
        ]);

        $new = $this->snapshotItems($projet->id);
        $this->writeAudit($projet->id, $u->id, $role, 'save', $old, $new, null);
    });

    return $this->estimationShow($r, $projet);
}

/** POST: approbation
 *  - Chef Sup : passe à PENDING_ADMIN (et pose l’empreinte chef_approved_*)
 *  - AdminG   : passe à APPROVED (et pose l’empreinte admin_approved_*)
 *  AdminG ne peut approuver que si statut == PENDING_ADMIN
 */
public function estimationApprove(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $u = $r->user(); $role = (int) $u->role_id;

    if (!in_array($role, [self::CHEF_SUP, self::ADMIN_G], true)) {
        return response()->json(['message'=>'Non autorisé.'], 403);
    }
    if (!\App\Models\EstimationBudget::where('projet_id',$projet->id)->exists()) {
        return response()->json(['message'=>"Aucune ligne d'estimation."], 422);
    }

    $es = $this->ensureEstimationState($projet);

    if ($role === self::CHEF_SUP) {
        if (!in_array($es->statut, ['PENDING_CHEF','REFUSED','NONE'], true)) {
            return response()->json(['message'=>"Rien en attente Chef Sup."], 422);
        }
        $es->update([
            'statut'            => 'PENDING_ADMIN',
            'motif_refus'       => null,
            'chef_approved_by'  => $u->id,
            'chef_approved_at'  => \Carbon\Carbon::now(),
        ]);
    } else { // ADMIN_G
        if ($es->statut !== 'PENDING_ADMIN') {
            return response()->json(['message'=>"En attente de validation Chef Sup."], 422);
        }
        $es->update([
            'statut'            => 'APPROVED',
            'motif_refus'       => null,
            'admin_approved_by' => $u->id,
            'admin_approved_at' => \Carbon\Carbon::now(),
        ]);
    }

    $this->writeAudit($projet->id, $u->id, $role, 'approve', null, $this->snapshotItems($projet->id), null);
    return $this->estimationShow($r, $projet);
}

/** POST: refus (Chef Sup / AdminG) — motif requis */
public function estimationRefuse(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $u = $r->user(); $role = (int) $u->role_id;

    if (!in_array($role, [self::CHEF_SUP, self::ADMIN_G], true)) {
        return response()->json(['message'=>'Non autorisé.'], 403);
    }

    $data = $r->validate([
        'motif' => ['required','string','min:3'],
    ]);

    $es = $this->ensureEstimationState($projet);
    if (!in_array($es->statut, ['PENDING_CHEF','PENDING_ADMIN','APPROVED','NONE'], true)) {
        return response()->json(['message'=>"Rien à refuser."], 422);
    }

    $es->update([
        'statut'            => 'REFUSED',
        'motif_refus'       => $data['motif'],
        'admin_approved_by' => null,
        'admin_approved_at' => null,
    ]);

    $this->writeAudit($projet->id, $u->id, $role, 'refuse', $this->snapshotItems($projet->id), null, $data['motif']);
    return $this->estimationShow($r, $projet);
}

public function financeShow(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);

    $role = (int) $r->user()->role_id;
    if ($role !== self::ADMIN_G) {
        return response()->json(['message' => "Réservé à l’AdminG."], 403);
    }

    // L’offre financière n’est accessible que quand l’estimation est approuvée
    $es = EstimationState::firstOrCreate(['projet_id' => $projet->id], ['statut' => 'NONE']);
    if ($es->statut !== 'APPROVED') {
        return response()->json(['message' => "Estimation non approuvée par l’AdminG."], 422);
    }

    $estimationTotal = (float) EstimationBudget::where('projet_id', $projet->id)->sum('amount');

    // Crée le conteneur d’offre si besoin (vide au départ)
    $offer = FinancialOffer::firstOrCreate(
        ['projet_id' => $projet->id],
        ['created_by' => $r->user()->id, 'updated_by' => $r->user()->id]
    );

    $items = FinancialOfferItem::where('financial_offer_id', $offer->id)
        ->orderBy('position')->orderBy('id')
        ->get(['id','label','amount','position']);

    return response()->json([
        'estimation_total' => $estimationTotal,
        'offer' => [
            'id'         => $offer->id,
            'items'      => $items,
            'total'      => (float) $items->sum('amount'),
            'updated_at' => optional($offer->updated_at)->toIso8601String(),
        ],
    ]);
}

public function saveItems(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $u = $r->user();
    if ((int)$u->role_id !== self::ADMIN_G) {
        return response()->json(['message' => 'Non autorisé.'], 403);
    }

    $data = $r->validate([
        'items' => ['required','array','min:1'],
        'items.*.label' => ['required','string','max:255'],
        'items.*.amount' => ['required','numeric','min:0'],
    ]);

    try {
        DB::transaction(function () use ($projet, $u, $data) {
            $offer = FinancialOffer::firstOrCreate(
                ['projet_id' => $projet->id],
                ['created_by' => $u->id, 'updated_by' => $u->id, 'total_cached' => 0]
            );

            // On remplace les lignes existantes
            FinancialOfferItem::where('financial_offer_id', $offer->id)->delete();

            $now = now();
            $pos = 1;
            $bulk = [];
            foreach ($data['items'] as $row) {
                $bulk[] = [
                    'financial_offer_id' => $offer->id,
                    'label' => trim($row['label']),
                    'amount' => (float) $row['amount'],
                    'position' => $pos++,
                    'created_by' => $u->id,
                    'updated_by' => $u->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            FinancialOfferItem::insert($bulk);

            // >>> recalcul & écriture du total
            $total = (float) FinancialOfferItem::where('financial_offer_id', $offer->id)->sum('amount');
            $offer->total_cached = $total;
            $offer->updated_by = $u->id;
            $offer->updated_at = now(); // Ajout explicite de updated_at
            $offer->save();

            // Log pour debug
            \Log::info('FinancialOffer saved', [
                'offer_id' => $offer->id,
                'total_calculated' => $total,
                'total_cached_after_save' => $offer->fresh()->total_cached
            ]);
        });

    } catch (\Throwable $e) {
        \Log::error('FinancialOffer saveItems failed', [
            'projet_id' => $projet->id,
            'err' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json(['message' => 'Erreur serveur: '.$e->getMessage()], 500);
    }

    // CORRECTION: Appeler financeShow au lieu de show
    return $this->financeShow($r, $projet);
}

}

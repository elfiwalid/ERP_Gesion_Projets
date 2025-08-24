<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Demande;
use App\Models\Depot;
use App\Models\Piece;
use App\Models\Projet;
use App\Models\ProjectApproval;
use App\Models\ProjectFinance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use ZipArchive;

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

            // Précréer un container finance
            ProjectFinance::firstOrCreate(['projet_id' => $p->id]);

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
            'decided_at' => \Carbon\Carbon::now(),
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
                    'updated_at' => \Carbon\Carbon::now(),
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
                'archived_at' => \Carbon\Carbon::now(),
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

    // ============================
    // =======  FINANCE  ==========
    // ============================

    private function ensureFinance(Projet $p): ProjectFinance
    {
        return ProjectFinance::firstOrCreate(['projet_id' => $p->id]);
    }

    private function computeTechOk(Projet $p): bool
{
    return Piece::where('projet_id', $p->id)
        ->whereRaw('LOWER(nom) = ?', [mb_strtolower(self::TECH_OFFER_NAME)])
        ->where('statut', 'VALIDE')
        ->whereNotNull('fichier_path')   // ✅
        ->exists();
}

    public function financeShow(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        $f = $this->ensureFinance($projet);

        return response()->json([
            'tech_ok'   => $this->computeTechOk($projet),
            'estimation'=> [
                'file_path'                 => $f->estimation_file_path,
                'uploaded_by'               => $f->estimation_uploaded_by,
                'uploaded_by_role_id'       => $f->estimation_uploaded_by_role_id,
                'uploaded_at'               => optional($f->estimation_uploaded_at)->toIso8601String(),
                'statut'                    => $f->estimation_statut,
                'motif_refus'               => $f->estimation_motif_refus,
                'chef_approved_by'          => $f->chef_approved_by,
                'chef_approved_at'          => optional($f->chef_approved_at)->toIso8601String(),
                'admin_approved_by'         => $f->admin_approved_by,
                'admin_approved_at'         => optional($f->admin_approved_at)->toIso8601String(),
            ],
            'sent_by'   => $f->sent_by,
            'sent_at'   => optional($f->sent_at)->toIso8601String(),
        ]);
    }

    public function financeUploadEstimation(Request $r, Projet $projet)
{
    $this->assertCanSeeProject($r, $projet);
    $u = $r->user();

    if (!in_array((int)$u->role_id, [self::CHEF, self::CHEF_SUP, self::ADMIN_G], true)) {
        return response()->json(['message' => 'Non autorisé à déposer une estimation.'], 403);
    }

    if ($projet->statut !== 'EN_COURS') {
        return response()->json(['message' => 'Le projet doit être EN_COURS.'], 422);
    }

    $r->validate([
        'fichier' => ['required','file','mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg','max:10240'],
    ]);

    $f = $this->ensureFinance($projet);

    if (!in_array($f->estimation_statut, ['NONE','REFUSED'], true)) {
        return response()->json(['message' => 'Une estimation est déjà en cours/validée.'], 422);
    }

    $path = \Illuminate\Support\Facades\Storage::disk('local')
        ->putFile("estimations/{$projet->id}", $r->file('fichier'));

    // Remplace le match par un switch (compatible PHP 7.x)
    $role = (int) $u->role_id;
    $next = 'PENDING_ADMIN';
    switch ($role) {
        case self::CHEF:
            $next = 'PENDING_CHEF';
            break;
        case self::CHEF_SUP:
        case self::ADMIN_G:
        default:
            $next = 'PENDING_ADMIN';
            break;
    }

    $f->fill([
        'estimation_file_path'           => $path,
        'estimation_uploaded_by'         => $u->id,
        'estimation_uploaded_by_role_id' => $u->role_id,
        'estimation_uploaded_at'         => \Carbon\Carbon::now(),
        'estimation_statut'              => $next,
        'estimation_motif_refus'         => null,
        'chef_approved_by'               => null,
        'chef_approved_at'               => null,
        'admin_approved_by'              => null,
        'admin_approved_at'              => null,
    ])->save();

    return $this->financeShow($r, $projet);
}


    public function financeChefApprove(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        $u = $r->user();
        if ((int)$u->role_id !== self::CHEF_SUP) {
            return response()->json(['message' => 'Seul le Chef Terrain Sup peut valider cette étape.'], 403);
        }

        $f = $this->ensureFinance($projet);
        if ($f->estimation_statut !== 'PENDING_CHEF') {
            return response()->json(['message' => 'Aucune estimation en attente Chef Sup.'], 422);
        }

        $f->update([
            'chef_approved_by' => $u->id,
            'chef_approved_at' => Carbon::now(),
            'estimation_statut'=> 'PENDING_ADMIN',
        ]);

        return $this->financeShow($r, $projet);
    }

    public function financeAdminApprove(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        $u = $r->user();
        if ((int)$u->role_id !== self::ADMIN_G) {
            return response()->json(['message' => 'Seul l’AdminG peut valider définitivement.'], 403);
        }

        $f = $this->ensureFinance($projet);
        if (!in_array($f->estimation_statut, ['PENDING_ADMIN','PENDING_CHEF'], true)) {
            return response()->json(['message' => 'Aucune estimation en attente AdminG.'], 422);
        }

        $f->update([
            'admin_approved_by' => $u->id,
            'admin_approved_at' => Carbon::now(),
            'estimation_statut' => 'APPROVED',
            'estimation_motif_refus' => null,
        ]);

        return $this->financeShow($r, $projet);
    }

    public function financeRefuse(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        $u = $r->user();
        if (!in_array((int)$u->role_id, [self::CHEF_SUP, self::ADMIN_G], true)) {
            return response()->json(['message' => 'Non autorisé à refuser.'], 403);
        }

        $data = $r->validate([
            'motif' => ['required','string','min:3'],
        ]);

        $f = $this->ensureFinance($projet);
        if (!in_array($f->estimation_statut, ['PENDING_CHEF','PENDING_ADMIN'], true)) {
            return response()->json(['message' => 'Aucune estimation à refuser.'], 422);
        }

        $f->update([
            'estimation_statut'      => 'REFUSED',
            'estimation_motif_refus' => $data['motif'],
            // on garde l’historique uploaded_by etc.
        ]);

        return $this->financeShow($r, $projet);
    }

    public function financeSendRA(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);
        $u = $r->user();
        if ((int)$u->role_id !== self::RESP_ADMIN) {
            return response()->json(['message' => 'Seul le RA peut envoyer la proposition au client.'], 403);
        }

        $f = $this->ensureFinance($projet);
        $techOk = $this->computeTechOk($projet);

        if (!$techOk) {
            return response()->json(['message' => "L'offre technique n'est pas validée."], 422);
        }
        if ($f->estimation_statut !== 'APPROVED') {
            return response()->json(['message' => "L'estimation n'est pas validée."], 422);
        }

        $f->update([
            'sent_by' => $u->id,
            'sent_at' => Carbon::now(),
        ]);

        // Ici tu peux déclencher l'email, la génération PDF, etc.

        return $this->financeShow($r, $projet);
    }

    public function sendables(Request $r, Projet $projet)
{
    $u = $r->user();
    if (!in_array((int)$u->role_id, [self::ADMIN_G, self::RESP_ADMIN], true)) {
        return response()->json(['message'=>'Non autorisé.'], 403);
    }
    if ($projet->statut !== 'EN_COURS') {
        return response()->json(['message'=>"Le projet n'est pas en cours."], 422);
    }

    // Pièces validées avec fichier
    $pieces = $projet->pieces()
        ->where('statut','VALIDE')
        ->whereNotNull('fichier_path')
        ->get(['id','nom','fichier_path']);

    // Offre technique
    $tech = $pieces->first(function ($p) {
        return mb_strtolower(trim($p->nom)) === mb_strtolower('Offre technique');
    });

    // Finance
    $finance = ProjectFinance::firstOrCreate(['projet_id' => $projet->id], ['estimation_statut' => 'NONE']);
    $financeReady = ($finance->estimation_statut === 'APPROVED') && !empty($finance->estimation_file_path);

    // Autres pièces
    $others = $pieces->filter(fn($p) => !$tech || $p->id !== $tech->id)->values()->all();

    // ✅ Dépôt (justificatif logistique du DERNIER dépôt physique)
    $lastDepot  = Depot::where('projet_id', $projet->id)->latest('id')->first();
    $depotFile  = null;
    if ($lastDepot && $lastDepot->mode === 'PHYSIQUE' && !empty($lastDepot->physique_file_path)) {
        $depotFile = [
            'path'     => $lastDepot->physique_file_path,
            'filename' => $lastDepot->physique_file_name ?: basename($lastDepot->physique_file_path),
        ];
    }

    return response()->json([
        'tech'    => $tech ? ['id'=>$tech->id, 'nom'=>$tech->nom] : null,
        'finance' => $financeReady ? [
            'path'     => $finance->estimation_file_path,
            'filename' => basename($finance->estimation_file_path),
        ] : null,
        'pieces'  => array_map(fn($p)=>['id'=>$p->id,'nom'=>$p->nom], $others),
        'depot'   => $depotFile,                   // 👈 on renvoie la variable définie ci-dessus
        'ready'   => (bool)($tech && $financeReady),
    ]);
}


public function downloadPackage(Request $r, Projet $projet)
{
    $u = $r->user();
    if (!in_array((int)$u->role_id, [self::ADMIN_G, self::RESP_ADMIN], true)) {
        return response()->json(['message'=>'Non autorisé.'], 403);
    }
    if ($projet->statut !== 'EN_COURS') {
        return response()->json(['message'=>"Le projet n'est pas en cours."], 422);
    }

    $data = $r->validate([
        'include_tech'    => ['required','boolean'],
        'include_finance' => ['required','boolean'],
        'include_depot'   => ['sometimes','boolean'],   // 👈 ajouté
        'piece_ids'       => ['array'],
        'piece_ids.*'     => ['integer','exists:pieces,id'],
    ]);

    // Récup sources
    $pieces = $projet->pieces()
        ->where('statut','VALIDE')
        ->whereNotNull('fichier_path')
        ->get(['id','nom','fichier_path']);

    $tech = $pieces->first(fn($p) => mb_strtolower(trim($p->nom)) === mb_strtolower('Offre technique'));

    $finance = ProjectFinance::firstOrCreate(['projet_id' => $projet->id], ['estimation_statut' => 'NONE']);
    $financeReady = ($finance->estimation_statut === 'APPROVED') && !empty($finance->estimation_file_path);

    if ($data['include_tech'] && !$tech) {
        return response()->json(['message'=>"Offre technique absente ou non validée."], 422);
    }
    if ($data['include_finance'] && !$financeReady) {
        return response()->json(['message'=>"Estimation budget non approuvée."], 422);
    }

    $files = [];

    if ($data['include_tech'] && $tech) {
        $files[] = [
            'disk' => $this->pickDiskFor($tech->fichier_path),
            'path' => $tech->fichier_path,
            'name' => '01_Offre_technique_'.basename($tech->fichier_path),
        ];
    }

    if ($data['include_finance'] && $financeReady) {
        $files[] = [
            'disk' => $this->pickDiskFor($finance->estimation_file_path),
            'path' => $finance->estimation_file_path,
            'name' => '02_Estimation_budget_'.basename($finance->estimation_file_path),
        ];
    }

    // Autres pièces cochées
    $selected = collect($data['piece_ids'] ?? [])->map(fn($v)=>(int)$v)->all();
    foreach ($pieces as $p) {
        if ($tech && $p->id === $tech->id) continue;
        if (!in_array($p->id, $selected, true)) continue;
        $files[] = [
            'disk' => $this->pickDiskFor($p->fichier_path),
            'path' => $p->fichier_path,
            'name' => 'piece_'.$p->id.'_'.str_replace(' ','_',$p->nom).'_'.basename($p->fichier_path),
        ];
    }

    // ✅ Fichier logistique du dernier dépôt physique, si demandé
    if (!empty($data['include_depot'])) {
        $lastDepot = Depot::where('projet_id', $projet->id)->latest('id')->first();
        if ($lastDepot && $lastDepot->mode === 'PHYSIQUE' && !empty($lastDepot->physique_file_path)) {
            $files[] = [
                'disk' => $this->pickDiskFor($lastDepot->physique_file_path),
                'path' => $lastDepot->physique_file_path,
                'name' => '03_Budget_logistique_'.basename($lastDepot->physique_file_path),
            ];
        }
    }

    if (empty($files)) {
        return response()->json(['message'=>"Aucun fichier à inclure."], 422);
    }

    // Création ZIP (identique à ta version)
    $disk = 'public';
    $dir  = 'proposals/'.$projet->id;
    $name = 'projet_'.$projet->id.'_proposal_'.now()->format('Ymd_His').'.zip';
    $zipRelativePath = $dir.'/'.$name;

    \Storage::disk($disk)->makeDirectory($dir);
    $zipFullPath = \Storage::disk($disk)->path($zipRelativePath);

    $zip = new \ZipArchive();
    if (true !== $zip->open($zipFullPath, \ZipArchive::CREATE)) {
        return response()->json(['message'=>'Impossible de créer le ZIP.'], 500);
    }
    foreach ($files as $f) {
        $src = \Storage::disk($f['disk'])->path($f['path']);
        if (is_file($src)) $zip->addFile($src, $f['name']);
    }
    $zip->close();

    $url = \Storage::disk($disk)->url($zipRelativePath);
    $projet->update(['proposal_last_zip_path' => $zipRelativePath]);

    return response()->json(['status'=>'ready','url'=>$url,'zip'=>$zipRelativePath]);
}

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

}

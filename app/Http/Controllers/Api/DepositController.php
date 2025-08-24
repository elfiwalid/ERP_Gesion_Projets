<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use App\Models\Piece;
use App\Models\Projet;
use App\Models\ProjectFinance;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DepositController extends Controller
{
    private const RESP_ADMIN       = 2; // Responsable Administratif
    private const TECH_OFFER_NAME  = 'Offre technique';

    /** -------- Utils -------- */

    private function assertIsRA(Request $r): void
    {
        if ((int) $r->user()->role_id !== self::RESP_ADMIN) {
            abort(403, 'Seul le Responsable Administratif peut créer/marquer un dépôt.');
        }
    }

    private function computeTechOk(Projet $p): bool
    {
        return Piece::where('projet_id', $p->id)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower(self::TECH_OFFER_NAME)])
            ->where('statut', 'VALIDE')
            ->whereNotNull('fichier_path')
            ->exists();
    }

    private function financeApproved(Projet $p): bool
    {
        $f = ProjectFinance::firstWhere('projet_id', $p->id);
        return $f && $f->estimation_statut === 'APPROVED' && !empty($f->estimation_file_path);
    }

    /** -------- 1) Projets éligibles au dépôt --------
     *  EN_COURS + estimation APPROVED + offre technique validée
     */
    public function eligible(Request $r)
    {
        $rows = Projet::with('client')
            ->select('projets.*')
            ->join('project_finances as pf', 'pf.projet_id', '=', 'projets.id')
            ->where('pf.estimation_statut', 'APPROVED')
            ->whereNotNull('pf.estimation_file_path')
            ->where('projets.statut', 'EN_COURS')
            ->orderByDesc('projets.id')
            ->get();

        // Filtre final côté PHP pour l'offre technique validée
        $filtered = $rows->filter(fn (Projet $p) => $this->computeTechOk($p));

        $out = $filtered->map(function (Projet $p) {
            return [
                'id'     => $p->id,
                'nom'    => $p->nom,
                'client' => [
                    'id' => $p->client_id,
                    'raison_sociale' => optional($p->client)->raison_sociale,
                ],
                '_finance' => [
                    'tech_ok'    => true,
                    'estimation' => ['statut' => 'APPROVED'],
                ],
                '_ready' => true,
            ];
        });

        return $out->values();
    }

    /** -------- 2) Dernier dépôt d’un projet -------- */
    public function lastForProject(Projet $projet)
    {
        $dep = Depot::with('projet')->where('projet_id', $projet->id)->latest('id')->first();
        return $dep ?: response()->json(null, 200);
    }

    /** -------- 3) Détail d’un dépôt -------- */
    public function show(Depot $deposit)
    {
        return $deposit->load('projet');
    }

    /** -------- 4) Créer un dépôt --------
     *  Routes supportées :
     *   - POST /projets/{projet}/deposits
     *   - POST /deposits (avec projet_id dans le body)
     */
    public function store(Request $r, Projet $projet = null)
    {
        $this->assertIsRA($r);

        // Projet via route ou body
        if (!$projet) {
            $pid = $r->validate(['projet_id' => ['required', 'integer', 'exists:projets,id']])['projet_id'];
            $projet = Projet::findOrFail($pid);
        }

        // Éligibilité
        if ($projet->statut !== 'EN_COURS') {
            return response()->json(['message' => "Projet non éligible (statut)."], 422);
        }
        if (!$this->financeApproved($projet)) {
            return response()->json(['message' => "Estimation non approuvée."], 422);
        }
        // (Option) Si tu veux imposer l’offre technique validée :
        // if (!$this->computeTechOk($projet)) {
        //     return response()->json(['message' => "Offre technique non validée."], 422);
        // }

        // Validation champs (pas le fichier ici, on le validera conditionnellement)
        $data = $r->validate([
            'mode' => ['required', Rule::in(['EMAIL', 'PHYSIQUE', 'PLATEFORME'])],
            'meta.email.to'      => ['nullable', 'string'],
            'meta.email.subject' => ['nullable', 'string'],
            'meta.email.body'    => ['nullable', 'string'],
            'meta.physical.ref'     => ['nullable', 'string'],
            'meta.physical.contact' => ['nullable', 'string'],
            'meta.physical.motif'   => ['nullable', 'string', 'min:3'],
            'meta.platform.url'  => ['nullable', 'url'],
        ]);

        // Contraintes par mode
        if ($data['mode'] === 'EMAIL' && empty(data_get($data, 'meta.email.to'))) {
            return response()->json(['message' => "Destinataires requis (meta.email.to)."], 422);
        }
        if ($data['mode'] === 'PHYSIQUE') {
            if (empty(data_get($data, 'meta.physical.motif'))) {
                return response()->json(['message' => "Motif obligatoire pour le dépôt physique."], 422);
            }
            // fichier OBLIGATOIRE en mode PHYSIQUE
            $r->validate([
                'physique_file' => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
            ]);
        }
        if ($data['mode'] === 'PLATEFORME' && empty(data_get($data, 'meta.platform.url'))) {
            return response()->json(['message' => "URL de la plateforme requise."], 422);
        }

        $user = $r->user();

        // Préparation des colonnes fichier (null par défaut)
        $filePath = null;
        $fileName = null;

        // Upload si PHYSIQUE
        if ($data['mode'] === 'PHYSIQUE' && $r->hasFile('physique_file')) {
            $file     = $r->file('physique_file');
            $fileName = $file->getClientOriginalName();
            // stocke sur le disk 'public' => crée /storage/depots/<projetId>/...
            $stored   = $file->store("depots/{$projet->id}", 'public'); // ex: depots/22/abc123.pdf
            $filePath = $stored; // on garde le chemin relatif du disk (ex: depots/22/abc123.pdf)
        }

        // Création
        $dep = Depot::create([
            'projet_id'          => $projet->id,
            'mode'               => $data['mode'],
            'status'             => 'SENT', // tu peux mettre PENDING si tu veux un workflow différent
            'meta_email'         => $data['mode'] === 'EMAIL'      ? (data_get($data, 'meta.email')     ?? null) : null,
            'meta_physical'      => $data['mode'] === 'PHYSIQUE'   ? (data_get($data, 'meta.physical')  ?? null) : null,
            'meta_platform'      => $data['mode'] === 'PLATEFORME' ? (data_get($data, 'meta.platform')  ?? null) : null,
            'physique_file_path' => $filePath,
            'physique_file_name' => $fileName,
            'created_by'         => $user->id,
            'sent_by'            => $user->id,
            'sent_at'            => Carbon::now(),
        ]);

        return $dep->load('projet');
    }

    /** -------- 5) Dépôt physique : enregistrer juste un path texte --------
     *  PUT /depots/{deposit}/physique-path
     *  Body: { path: "...", name?: "nom.pdf" }
     */
    public function setPhysicalPath(Request $r, Depot $deposit)
    {
        $this->assertIsRA($r);

        $data = $r->validate([
            'path' => ['required', 'string', 'min:3'],
            'name' => ['nullable', 'string'],
        ]);

        $deposit->physique_file_path = $data['path'];
        $deposit->physique_file_name = $data['name'] ?? basename($data['path']);
        $deposit->save();

        return $deposit->fresh();
    }

    /** -------- 6) Dépôt physique : upload réel --------
     *  POST /depots/{deposit}/physique-upload
     *  FormData: file
     */
    public function uploadPhysical(Request $r, Depot $deposit)
    {
        $this->assertIsRA($r);

        $r->validate([
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:15360'],
        ]);

        $file = $r->file('file');
        $path = $file->store("depots/{$deposit->projet_id}/physique", 'public');

        $deposit->physique_file_path = $path;
        $deposit->physique_file_name = $file->getClientOriginalName();
        $deposit->save();

        return response()->json([
            'id'   => $deposit->id,
            'path' => $deposit->physique_file_path,
            'name' => $deposit->physique_file_name,
            'url'  => Storage::url($deposit->physique_file_path),
        ]);
    }
}

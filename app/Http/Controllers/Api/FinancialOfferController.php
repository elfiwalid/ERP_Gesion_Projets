<?php

namespace App\Http\Controllers;

use App\Models\Projet;
use App\Models\FinancialOffer;
use App\Models\FinancialOfferItem;
use App\Models\EstimationState;
use App\Models\EstimationBudget;
use App\Models\Piece;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialOfferController extends Controller
{
    /* ==== Rôles (adapte si tes IDs diffèrent) ==== */
    private const ADMIN_G  = 1;
    private const CHEF_SUP = 3;
    private const CHEF     = 4;

    /* ==== Nom de la pièce “offre technique” ==== */
    private const TECH_OFFER_NAME = 'offre technique';

    /* ==== Helpers locaux (remplace si tu as déjà) ==== */

    private function assertCanSeeProject(Request $r, Projet $p): void
    {
        // Minimal: utilisateur authentifié.
        // Remplace par ta logique d’autorisation projet.
        if (!$r->user()) {
            abort(401, 'Non authentifié.');
        }
    }

    private function ensureEstimationState(Projet $p): EstimationState
    {
        return EstimationState::firstOrCreate(
            ['projet_id' => $p->id],
            ['statut' => 'NONE']
        );
    }

    private function computeTechOk(Projet $p): bool
    {
        return Piece::where('projet_id', $p->id)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower(self::TECH_OFFER_NAME)])
            ->where('statut', 'VALIDE')
            ->whereNotNull('fichier_path')
            ->exists();
    }

    private function ensureFinancialOffer(Projet $p, ?int $userId = null): FinancialOffer
    {
        return FinancialOffer::firstOrCreate(
            ['projet_id' => $p->id],
            ['total_cached' => 0, 'created_by' => $userId]
        );
    }

    /* ===================== API ===================== */

    /**
     * GET /projets/{projet}/offre-financiere
     * Visible et éditable uniquement par AdminG
     * Prérequis: projet EN_COURS, offre technique OK, estimation APPROVED
     */
    public function show(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);

        $u = $r->user(); $role = (int) $u->role_id;
        if ($role !== self::ADMIN_G) {
            return response()->json(['message' => "Offre financière réservée à l’Admin Général."], 403);
        }

        if ($projet->statut !== 'EN_COURS') {
            return response()->json(['message' => 'Projet non EN_COURS.'], 422);
        }

        if (!$this->computeTechOk($projet)) {
            return response()->json(['message' => "L'Offre technique n'est pas validée."], 422);
        }

        $es = $this->ensureEstimationState($projet);
        if (($es->statut ?? 'NONE') !== 'APPROVED') {
            return response()->json(['message' => "Estimation non validée par l’AdminG."], 422);
        }

        $offer = $this->ensureFinancialOffer($projet, $u->id)->load('items');

        $estimationTotal = (float) EstimationBudget::where('projet_id', $projet->id)->sum('amount');

        return response()->json([
            'estimation_total' => $estimationTotal,
            'offer' => [
                'id'         => $offer->id,
                'total'      => (float) $offer->total_cached,
                'updated_at' => optional($offer->updated_at)->toIso8601String(),
                'items'      => $offer->items()->get(['id','label','amount','position'])->map(function ($r) {
                    return [
                        'id'       => $r->id,
                        'label'    => $r->label,
                        'amount'   => (float) $r->amount,
                        'position' => (int) $r->position,
                    ];
                }),
            ],
        ]);
    }

    /**
     * POST /projets/{projet}/offre-financiere/items
     * Remplace toutes les lignes — AdminG uniquement
     * Payload: { items:[{label, amount}] }
     */
    public function saveItems(Request $r, Projet $projet)
    {
        $this->assertCanSeeProject($r, $projet);

        $u = $r->user(); $role = (int) $u->role_id;
        if ($role !== self::ADMIN_G) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($projet->statut !== 'EN_COURS') {
            return response()->json(['message' => 'Projet non EN_COURS.'], 422);
        }
        if (!$this->computeTechOk($projet)) {
            return response()->json(['message' => "L'Offre technique n'est pas validée."], 422);
        }
        $es = $this->ensureEstimationState($projet);
        if (($es->statut ?? 'NONE') !== 'APPROVED') {
            return response()->json(['message' => "Estimation non validée par l’AdminG."], 422);
        }

        $data = $r->validate([
            'items' => ['required','array','min:1'],
            'items.*.label'  => ['required','string','max:255'],
            'items.*.amount' => ['required','numeric','min:0'],
        ]);

        $offer = $this->ensureFinancialOffer($projet, $u->id);

        DB::transaction(function () use ($data, $offer, $u) {
            FinancialOfferItem::where('financial_offer_id', $offer->id)->delete();

            $pos = 1; $total = 0;
            foreach ($data['items'] as $row) {
                $amt = (float) $row['amount'];

                FinancialOfferItem::create([
                    'financial_offer_id' => $offer->id,
                    'label'      => trim($row['label']),
                    'amount'     => $amt,
                    'position'   => $pos++,
                    'created_by' => $u->id,
                    'updated_by' => $u->id,
                ]);
                $total += $amt;
            }

            $offer->update([
                'total_cached' => $total,
                'updated_by'   => $u->id,
            ]);
        });

        return $this->show($r, $projet);
    }
}

<?php

// app/Http/Controllers/Api/SoutenanceController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use App\Models\Soutenance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class SoutenanceController extends Controller
{
    private const ADMIN_G    = 1;
    private const RESP_ADMIN = 2;

    private function assertRAorAdmin($u): void {
        if (!in_array((int)$u->role_id, [self::ADMIN_G, self::RESP_ADMIN], true)) {
            abort(403, 'Non autorisé.');
        }
    }

    /** Helpers JSON */
    private function normalizeInvites(?array $inv): array { return array_values($inv ?? []); }
    private function setInvite(array $invites, int $userId, array $patch): array {
        $found = false;
        foreach ($invites as &$i) {
            if ((int)$i['user_id'] === $userId) { $i = array_merge($i, $patch); $found = true; break; }
        }
        if (!$found) { $invites[] = array_merge(['user_id'=>$userId,'statut'=>'INVITÉ','notified_at'=>null], $patch); }
        return $this->normalizeInvites($invites);
    }
    private function upsertFeedback(array $list, int $uid, array $data): array {
        $found = false;
        foreach ($list as &$f) {
            if ((int)$f['user_id'] === $uid) { $f = array_merge($f, $data); $found = true; break; }
        }
        if (!$found) { $list[] = array_merge(['user_id'=>$uid], $data); }
        return array_values($list);
    }


    /** POST /depots/{deposit}/soutenance  (création / mise à jour) */
    public function upsertForDepot(Depot $deposit, Request $r)
    {
        $this->assertRAorAdmin($r->user());

        $data = $r->validate([
            'date_time' => ['required','date'],
            'lieu'      => ['nullable','string','max:255'],
            'meeting_url'=>['nullable','url'],
            'notes'     => ['nullable','string'],
            'invite_user_ids'   => ['sometimes','array'],
            'invite_user_ids.*' => ['integer','exists:users,id'],
        ]);

        $s = Soutenance::firstOrNew(['depot_id'=>$deposit->id]);
        $s->date_time   = Carbon::parse($data['date_time']);
        $s->lieu        = $data['lieu'] ?? null;
        $s->meeting_url = $data['meeting_url'] ?? null;
        $s->notes       = $data['notes'] ?? null;
        if (!$s->exists) { $s->statut = 'PLANIFIÉE'; $s->created_by = $r->user()->id; }
        $s->updated_by = $r->user()->id;

        // Maj des invités (liste simple -> JSON détaillé)
        if (isset($data['invite_user_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $data['invite_user_ids'])));
            $inv = [];
            foreach ($ids as $uid) {
                $inv[] = ['user_id'=>$uid, 'statut'=>'INVITÉ', 'notified_at'=>null];
            }
            $s->invites = $inv;
        }

        $s->save();

        return $this->showByDepot($deposit, $r);
    }

    public function events(Request $r)
{
    $u     = $r->user();
    $start = \Carbon\Carbon::parse($r->query('start', now()->startOfMonth()))->startOfDay();
    $end   = \Carbon\Carbon::parse($r->query('end',   now()->endOfMonth()))->endOfDay();

    $q = \App\Models\Soutenance::query()
        ->whereBetween('date_time', [$start, $end])
        ->with(['depot:id,projet_id', 'depot.projet:id,nom']);

    $rows = $q->get();

    // Visibilité : AdminG (1) / RA (2) -> tout ; sinon seulement si invité
    $isAdminOrRA = in_array((int) $u->role_id, [1, 2], true);
    if (!$isAdminOrRA) {
        $rows = $rows->filter(function ($s) use ($u) {
            $inv = collect($s->invites ?: []);
            return $inv->pluck('user_id')->contains((int) $u->id);
        });
    }

    $events = $rows->map(function ($s) {
        $projet   = optional(optional($s->depot)->projet);
        $statut   = mb_strtoupper($s->statut ?: 'BROUILLON');

        // PHP 7: remplacer match() par switch
        $calendar = 'Primary';
        switch ($statut) {
            case 'TENUE':
                $calendar = 'Success';
                break;
            case 'PLANIFIÉE':
            case 'EN COURS':
                $calendar = 'Warning';
                break;
            case 'ANNULÉE':
                $calendar = 'Danger';
                break;
        }

        // Pas de ?-> (PHP 8). Utilise optional()
        $startIso = optional($s->date_time)->toIso8601String();
        $endDt    = $s->date_time ? (clone $s->date_time)->addMinutes(90) : null;
        $endIso   = $endDt ? $endDt->toIso8601String() : null;

        return [
            'id'     => 'sout-' . $s->id,
            'title'  => 'Soutenance — ' . ($projet ? ($projet->nom ?: ('Projet #' . (optional($s->depot)->projet_id ?: '?'))) : 'Projet ?'),
            'start'  => $startIso,
            'end'    => $endIso,
            'allDay' => false,
            'extendedProps' => [
                'calendar'      => $calendar,         // Danger / Success / Primary / Warning
                'soutenance_id' => $s->id,
                'projet_id'     => optional($s->depot)->projet_id,
                'statut'        => $s->statut,
                'lieu'          => $s->lieu,
                'meeting_url'   => $s->meeting_url,
            ],
        ];
    })->values();

    return response()->json($events);
}



    /** POST /soutenances/{soutenance}/envoyer-invitations */
    public function sendInvites(Soutenance $soutenance, Request $r)
    {
        $this->assertRAorAdmin($r->user());
        $now = Carbon::now()->toIso8601String();
        $inv = [];
        foreach (($soutenance->invites ?? []) as $i) {
            $i['notified_at'] = $now;
            $inv[] = $i;
        }
        $soutenance->invites = $inv;
        $soutenance->save();

        // TODO: brancher un vrai envoi email si besoin
        return response()->json(['statut'=>'INVITATIONS_ENVoyÉES']);
    }

    /** POST /soutenances/{soutenance}/tenue */
    public function markDone(Soutenance $soutenance, Request $r)
    {
        $this->assertRAorAdmin($r->user());
        $soutenance->update(['statut'=>'TENUE']);
        return response()->json(['statut'=>'TENUE']);
    }

    /** POST /soutenances/{soutenance}/feedback */
    public function storeFeedback(Soutenance $soutenance, Request $r)
    {
        $u = $r->user();
        // Option: vérifier que l'utilisateur est invité
        $invited = collect($soutenance->invites ?? [])->pluck('user_id')->contains((int)$u->id);
        if (!$invited && !in_array((int)$u->role_id, [self::ADMIN_G,self::RESP_ADMIN], true)) {
            return response()->json(['message'=>'Non invité à cette soutenance.'], 403);
        }

        $data = $r->validate([
            'commentaire' => ['nullable','string'],
            'client_demande_complements' => ['required','boolean'],
            'complements_details' => ['nullable','string'],
        ]);

        $feedbacks = $soutenance->feedbacks ?? [];
        $feedbacks = $this->upsertFeedback($feedbacks, (int)$u->id, [
            'commentaire' => $data['commentaire'] ?? null,
            'client_demande_complements' => (bool)$data['client_demande_complements'],
            'complements_details' => $data['client_demande_complements'] ? ($data['complements_details'] ?? null) : null,
            'created_at' => Carbon::now()->toIso8601String(),
        ]);
        $soutenance->feedbacks = $feedbacks;
        $soutenance->save();

        return response()->json(['statut'=>'ENREGISTRÉ']);
    }

    /** GET /soutenances/{soutenance}/feedback */
    public function listFeedback(Soutenance $soutenance)
    {
        $list = collect($soutenance->feedbacks ?? []);
        if ($list->isEmpty()) return [];

        $users = User::whereIn('id', $list->pluck('user_id')->all())->get(['id','name'])->keyBy('id');

        return $list->map(function($f) use ($users){
            $u = $users->get((int)$f['user_id']);
            return [
                'user_id' => (int)$f['user_id'],
                'user_name' => $u->name ?? null,
                'commentaire' => $f['commentaire'] ?? null,
                'client_demande_complements' => (bool)($f['client_demande_complements'] ?? false),
                'complements_details' => $f['complements_details'] ?? null,
                'created_at' => $f['created_at'] ?? null,
            ];
        })->values();
    }


    public function showByDepot(Depot $deposit, Request $r)
{
    $s = Soutenance::where('depot_id', $deposit->id)->latest('id')->first();
    if (!$s) return response()->json(null, 200);

    $me          = $r->user();
    $isPrivileged= in_array((int)$me->role_id, [self::ADMIN_G, self::RESP_ADMIN], true);

    // ↯ Blocage si non invité et pas Admin/RA
    $invites = collect($s->invites ?? []);
    $invitedMe = $invites->pluck('user_id')->contains((int)$me->id);
    if (!$isPrivileged && !$invitedMe) {
        return response()->json(['message' => 'Accès restreint à la soutenance.'], 403);
    }

    $users = \App\Models\User::whereIn('id', $invites->pluck('user_id')->all())
        ->get(['id','name','email'])->keyBy('id');

    $invites = $invites->map(function($i) use ($users){
        $u = $users->get((int)$i['user_id']);
        return [
            'user_id' => (int)$i['user_id'],
            'name'    => $u->name ?? null,
            'email'   => $u->email ?? null,
            'statut'  => $i['statut'] ?? 'INVITÉ',
            'notified_at' => !empty($i['notified_at']) ? \Carbon\Carbon::parse($i['notified_at'])->toIso8601String() : null,
        ];
    });

    return [
        'id'         => $s->id,
        'depot_id'   => $s->depot_id,
        'date_time'  => optional($s->date_time)->toIso8601String(),
        'lieu'       => $s->lieu,
        'meeting_url'=> $s->meeting_url,
        'statut'     => $s->statut,
        'status'     => $s->statut,
        'notes'      => $s->notes,
        'invites'    => $invites,
        'invited_me' => $invitedMe,
        'can_manage' => $isPrivileged, // pratique pour l’UI
    ];
}

}

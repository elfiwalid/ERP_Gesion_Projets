<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * GET /api/clients
     * Params:
     *  - search?   : string (raison_sociale, contact_nom, contact_email)
     *  - page?     : int (pagination Laravel)
     *  - per_page? : int (par défaut 15)
     */
    public function index(Request $r)
    {
        $q = Client::query();

        if ($s = trim((string) $r->get('search', ''))) {
            $q->where(function ($qq) use ($s) {
                $qq->where('raison_sociale', 'like', "%$s%")
                   ->orWhere('contact_nom', 'like', "%$s%")
                   ->orWhere('contact_email', 'like', "%$s%");
            });
        }

        // tri récent d'abord
        $q->orderByDesc('id');

        $perPage = (int) ($r->get('per_page') ?? 15);
        $perPage = $perPage > 0 ? $perPage : 15;

        // Si tu veux aussi renvoyer le nombre de demandes/projets, dé-commente :
        // $q->withCount(['demandes', 'projets']);

        return $q->paginate($perPage);
    }

    /**
     * POST /api/clients
     * Body JSON:
     *  - raison_sociale* (string)
     *  - contact_nom? (string)
     *  - contact_email? (email)
     *  - contact_telephone? (string)
     *  - adresse? (string)
     *  - metadonnees? (object/array JSON)
     */
    public function store(Request $r)
    {
        $data = $r->validate([
            'raison_sociale'     => ['required','string','max:255'],
            'contact_nom'        => ['nullable','string','max:255'],
            'contact_email'      => ['nullable','email','max:255'],
            'contact_telephone'  => ['nullable','string','max:50'],
            'adresse'            => ['nullable','string','max:500'],
            'metadonnees'        => ['nullable','array'],
        ]);

        $client = Client::create($data);
        return response()->json($client, 201);
    }

    /** GET /api/clients/{id} */
    public function show(int $id)
    {
        $client = Client::findOrFail($id);
        // $client->loadCount(['demandes','projets']); // optionnel
        return response()->json($client);
    }

    /**
     * PUT/PATCH /api/clients/{id}
     * Body: mêmes champs que store (tous optionnels)
     */
    public function update(Request $r, int $id)
    {
        $client = Client::findOrFail($id);

        $data = $r->validate([
            'raison_sociale'     => ['sometimes','required','string','max:255'],
            'contact_nom'        => ['sometimes','nullable','string','max:255'],
            'contact_email'      => ['sometimes','nullable','email','max:255'],
            'contact_telephone'  => ['sometimes','nullable','string','max:50'],
            'adresse'            => ['sometimes','nullable','string','max:500'],
            'metadonnees'        => ['sometimes','nullable','array'],
        ]);

        $client->update($data);
        return response()->json($client->fresh());
    }

    /** DELETE /api/clients/{id} (cascade si FKs configurées) */
    public function destroy(int $id)
    {
        $client = Client::findOrFail($id);
        $client->delete();
        return response()->json(['deleted' => true]);
    }
}

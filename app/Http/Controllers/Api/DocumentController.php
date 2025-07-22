<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index()
    {
        return Document::with(['projet', 'uploadedBy', 'validatedBy'])->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'projet_id' => 'required|exists:projets,id',
            'name' => 'required|string',
            'type' => 'required|string',
            'path' => 'required|string',
            'status' => 'sometimes|string|in:brouillon,partage,valide',
        ]);

        $document = Document::create([
            'projet_id' => $validated['projet_id'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'path' => $validated['path'],
            'status' => $validated['status'] ?? 'brouillon',
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(Document $document)
    {
        return $document->load(['projet', 'uploadedBy', 'validatedBy']);
    }

    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'type' => 'sometimes|string',
            'path' => 'sometimes|string',
            'status' => 'sometimes|string|in:brouillon,partage,valide',
        ]);

        $document->update($validated);

        return response()->json($document);
    }

    public function destroy(Document $document)
    {
        $document->delete();

        return response()->json(null, 204);
    }
}

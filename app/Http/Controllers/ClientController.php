<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Client;
use App\Models\ActivityLog;

class ClientController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the clients.
     */
    public function index(Request $request)
    {
        $query = Client::query();

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('insurance_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('active', $request->status === 'active');
        }

        $clients = $query->latest()->paginate(15);
        
        return view('clients.index', compact('clients'));
    }

    /**
     * Show the form for creating a new client.
     */
    public function create()
    {
        return view('clients.create');
    }

    /**
     * Store a newly created client in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:clients,email',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date|before:today',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'allergies' => 'nullable|string',
            'medical_notes' => 'nullable|string',
            'insurance_number' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $client = new Client();
            $client->fill($request->all());
            $client->active = $request->has('active');
            $client->save();

            // Log client creation
            ActivityLog::logActivity(
                'create',
                "Client créé: {$client->full_name}",
                $client,
                null,
                $client->toArray()
            );

            return redirect()->route('clients.index')
                ->with('success', 'Client ajouté avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la création du client: " . $e->getMessage(),
                null,
                null,
                ['error_details' => $e->getMessage(), 'request_data' => $request->all()]
            );
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la création du client.'])
                ->withInput();
        }
    }

    /**
     * Display the specified client.
     */
    public function show($id)
    {
        $client = Client::with(['sales.saleItems.product'])->findOrFail($id);
        $recentSales = $client->sales()->with(['saleItems.product'])
                                   ->latest()
                                   ->take(10)
                                   ->get();
        
        return view('clients.show', compact('client', 'recentSales'));
    }

    /**
     * Show the form for editing the specified client.
     */
    public function edit($id)
    {
        $client = Client::findOrFail($id);
        return view('clients.edit', compact('client'));
    }

    /**
     * Update the specified client in storage.
     */
    public function update(Request $request, $id)
    {
        $client = Client::findOrFail($id);
        $oldValues = $client->toArray();

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:clients,email,'.$id,
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date|before:today',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'allergies' => 'nullable|string',
            'medical_notes' => 'nullable|string',
            'insurance_number' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $client->fill($request->all());
            $client->active = $request->has('active');
            $client->save();

            // Log client update
            ActivityLog::logActivity(
                'update',
                "Client modifié: {$client->full_name}",
                $client,
                $oldValues,
                $client->toArray()
            );

            return redirect()->route('clients.index')
                ->with('success', 'Client mis à jour avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la modification du client {$client->full_name}: " . $e->getMessage(),
                $client
            );
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la modification du client.'])
                ->withInput();
        }
    }

    /**
     * Remove the specified client from storage.
     */
    public function destroy($id)
    {
        try {
            $client = Client::findOrFail($id);
            $clientData = $client->toArray();
            $clientName = $client->full_name;
            
            // Check if client has sales
            if ($client->sales()->count() > 0) {
                ActivityLog::logActivity(
                    'unauthorized_access',
                    "Tentative de suppression d'un client avec historique de ventes: {$clientName}",
                    $client
                );
                
                return redirect()->route('clients.index')
                    ->withErrors(['error' => 'Impossible de supprimer un client qui a un historique de ventes.']);
            }
            
            // Log deletion before actually deleting
            ActivityLog::logActivity(
                'delete',
                "Client supprimé: {$clientName}",
                null,
                $clientData,
                null
            );
            
            $client->delete();

            return redirect()->route('clients.index')
                ->with('success', 'Client supprimé avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la suppression du client: " . $e->getMessage(),
                null,
                null,
                ['client_id' => $id, 'error_details' => $e->getMessage()]
            );
            
            return redirect()->route('clients.index')
                ->withErrors(['error' => 'Erreur lors de la suppression du client.']);
        }
    }
}
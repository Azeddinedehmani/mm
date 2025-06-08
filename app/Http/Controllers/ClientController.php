<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Prescription;
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

        // Search functionality - Fixed with filled() instead of has()
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('insurance_number', 'like', "%{$search}%");
            });
        }

        // Filter by status - Fixed with filled() instead of has()
        if ($request->filled('status')) {
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
        
        // Statistiques du client
        $clientStats = [
            'total_sales' => $client->sales()->count(),
            'total_spent' => $client->sales()->sum('total_amount'),
            'last_visit' => $client->sales()->latest()->first()?->sale_date,
            'prescriptions_count' => Prescription::where('client_id', $id)->count(),
        ];
        
        return view('clients.show', compact('client', 'recentSales', 'clientStats'));
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
     * Remove the specified client from storage (PRESERVE SALES).
     * Les ventes sont conservées avec client_id = NULL pour l'historique.
     */
    public function destroy($id)
    {
        try {
            $client = Client::findOrFail($id);
            $clientData = $client->toArray();
            $clientName = $client->full_name;
            
            // Compter les données associées
            $salesCount = $client->sales()->count();
            $prescriptionsCount = Prescription::where('client_id', $id)->count();
            
            // Protection spéciale : seuls les administrateurs peuvent supprimer
            if (!auth()->user()->isAdmin()) {
                ActivityLog::logActivity(
                    'unauthorized_access',
                    "Tentative de suppression de client par un non-administrateur: {$clientName}",
                    $client,
                    null,
                    ['attempted_by' => auth()->user()->name, 'user_role' => auth()->user()->role]
                );
                
                return redirect()->route('clients.index')
                    ->withErrors(['error' => 'Seuls les administrateurs peuvent supprimer des clients.']);
            }

            // SUPPRESSION AVEC PRÉSERVATION DES VENTES
            DB::beginTransaction();
            
            try {
                $deletionSummary = [
                    'client_name' => $clientName,
                    'client_data' => $clientData,
                    'sales_preserved' => 0,
                    'prescriptions_deleted' => 0,
                    'prescription_items_deleted' => 0,
                    'deleted_by' => auth()->user()->name,
                    'deletion_date' => now()->toDateTimeString()
                ];

                // 1. DÉTACHER LES VENTES (ne pas les supprimer)
                if ($salesCount > 0) {
                    // Mettre client_id à NULL pour préserver les ventes dans l'historique
                    Sale::where('client_id', $id)->update([
                        'client_id' => null,
                        'client_name_at_deletion' => $clientName, // Optionnel : garder le nom pour référence
                        'deleted_client_data' => json_encode([
                            'id' => $client->id,
                            'name' => $clientName,
                            'email' => $client->email,
                            'phone' => $client->phone,
                            'deleted_at' => now()
                        ])
                    ]);
                    
                    $deletionSummary['sales_preserved'] = $salesCount;
                }

                // 2. SUPPRIMER LES ORDONNANCES ET LEURS ITEMS
                if ($prescriptionsCount > 0) {
                    $prescriptions = Prescription::where('client_id', $id)->with('prescriptionItems')->get();
                    
                    foreach ($prescriptions as $prescription) {
                        // Compter les items d'ordonnance
                        $deletionSummary['prescription_items_deleted'] += $prescription->prescriptionItems()->count();
                        
                        // Supprimer les items d'ordonnance
                        $prescription->prescriptionItems()->delete();
                    }
                    
                    // Supprimer les ordonnances
                    $deletionSummary['prescriptions_deleted'] = $prescriptionsCount;
                    Prescription::where('client_id', $id)->delete();
                }

                // 3. SUPPRIMER LE CLIENT
                $client->delete();

                // Log de la suppression
                ActivityLog::logActivity(
                    'delete',
                    "Client supprimé: {$clientName} | Ventes préservées: {$deletionSummary['sales_preserved']} | Ordonnances supprimées: {$deletionSummary['prescriptions_deleted']} | Items ordonnance supprimés: {$deletionSummary['prescription_items_deleted']}",
                    null,
                    $clientData,
                    $deletionSummary
                );
                
                DB::commit();
                
                // Message de succès détaillé
                $message = "Client supprimé avec succès!";
                if ($deletionSummary['sales_preserved'] > 0) {
                    $message .= " {$deletionSummary['sales_preserved']} vente(s) préservée(s) dans l'historique.";
                }
                if ($deletionSummary['prescriptions_deleted'] > 0) {
                    $message .= " {$deletionSummary['prescriptions_deleted']} ordonnance(s) supprimée(s).";
                }
                
                return redirect()->route('clients.index')
                    ->with('success', $message);
                    
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la suppression du client: " . $e->getMessage(),
                isset($client) ? $client : null,
                null,
                [
                    'client_id' => $id, 
                    'error_details' => $e->getMessage(),
                    'attempted_by' => auth()->user()->name,
                    'error_trace' => $e->getTraceAsString()
                ]
            );
            
            return redirect()->route('clients.index')
                ->withErrors(['error' => 'Erreur lors de la suppression du client.']);
        }
    }

    /**
     * Désactiver un client au lieu de le supprimer (ALTERNATIVE RECOMMANDÉE).
     */
    public function deactivate($id)
    {
        try {
            $client = Client::findOrFail($id);
            $oldStatus = $client->active;
            
            if (!$client->active) {
                return redirect()->route('clients.index')
                    ->withErrors(['error' => 'Ce client est déjà désactivé.']);
            }
            
            $client->update(['active' => false]);
            
            // Log deactivation
            ActivityLog::logActivity(
                'update',
                "Client désactivé: {$client->full_name}",
                $client,
                ['active' => $oldStatus],
                ['active' => false, 'deactivated_by' => auth()->user()->name, 'deactivated_at' => now()]
            );
            
            return redirect()->route('clients.index')
                ->with('success', 'Client désactivé avec succès! Ses données restent préservées.');
                
        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la désactivation du client: " . $e->getMessage(),
                null,
                null,
                ['client_id' => $id, 'error_details' => $e->getMessage()]
            );
            
            return redirect()->route('clients.index')
                ->withErrors(['error' => 'Erreur lors de la désactivation du client.']);
        }
    }

    /**
     * Réactiver un client.
     */
    public function reactivate($id)
    {
        try {
            $client = Client::findOrFail($id);
            $oldStatus = $client->active;
            
            if ($client->active) {
                return redirect()->route('clients.index')
                    ->withErrors(['error' => 'Ce client est déjà actif.']);
            }
            
            $client->update(['active' => true]);
            
            // Log reactivation
            ActivityLog::logActivity(
                'update',
                "Client réactivé: {$client->full_name}",
                $client,
                ['active' => $oldStatus],
                ['active' => true, 'reactivated_by' => auth()->user()->name, 'reactivated_at' => now()]
            );
            
            return redirect()->route('clients.index')
                ->with('success', 'Client réactivé avec succès!');
                
        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la réactivation du client: " . $e->getMessage(),
                null,
                null,
                ['client_id' => $id, 'error_details' => $e->getMessage()]
            );
            
            return redirect()->route('clients.index')
                ->withErrors(['error' => 'Erreur lors de la réactivation du client.']);
        }
    }

    /**
     * Vérifier les dépendances d'un client avant suppression (AJAX).
     */
    public function checkDependencies($id)
    {
        try {
            $client = Client::findOrFail($id);
            
            $dependencies = [
                'sales' => $client->sales()->count(),
                'prescriptions' => Prescription::where('client_id', $id)->count(),
                'will_be_preserved' => [],
                'will_be_deleted' => [],
                'warnings' => []
            ];
            
            // Ce qui sera préservé
            if ($dependencies['sales'] > 0) {
                $dependencies['will_be_preserved'][] = "{$dependencies['sales']} vente(s) (préservées dans l'historique)";
            }
            
            // Ce qui sera supprimé
            if ($dependencies['prescriptions'] > 0) {
                $dependencies['will_be_deleted'][] = "{$dependencies['prescriptions']} ordonnance(s)";
            }
            
            // Avertissements
            if ($dependencies['sales'] > 0) {
                $dependencies['warnings'][] = "ℹ️ INFO: Les ventes seront préservées dans l'historique comptable";
            }
            if ($dependencies['prescriptions'] > 0) {
                $dependencies['warnings'][] = "⚠️ ATTENTION: Les ordonnances seront définitivement supprimées";
            }
            
            $dependencies['can_delete'] = true;
            $dependencies['action_type'] = 'preserve_sales';
            $dependencies['recommendation'] = $dependencies['sales'] > 0 ? 
                "Recommandation: Considérez plutôt désactiver le client pour préserver toutes les données." : 
                "Aucune vente associée, suppression sûre.";
            
            return response()->json($dependencies);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors de la vérification des dépendances',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export clients avec statut des ventes.
     */
    public function export(Request $request)
    {
        $query = Client::withCount(['sales', 'prescriptions' => function($query) {
            // Compter seulement les prescriptions
        }]);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('active', $request->status === 'active');
        }

        $clients = $query->get();

        // Log export activity
        ActivityLog::logActivity(
            'export',
            'Export de la liste des clients (' . $clients->count() . ' clients)',
            null,
            null,
            ['export_count' => $clients->count(), 'exported_by' => auth()->user()->name]
        );

        $filename = 'clients_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($clients) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for UTF-8
            fwrite($file, "\xEF\xBB\xBF");
            
            // CSV headers
            fputcsv($file, [
                'ID',
                'Prénom',
                'Nom',
                'Email',
                'Téléphone',
                'Statut',
                'Nb Ventes',
                'Nb Ordonnances',
                'Action recommandée',
                'Date création',
                'Dernière modification'
            ], ';');

            foreach ($clients as $client) {
                $action = 'Suppression directe';
                if ($client->sales_count > 0) {
                    $action = 'Désactivation recommandée';
                }
                
                fputcsv($file, [
                    $client->id,
                    $client->first_name,
                    $client->last_name,
                    $client->email ?: 'Non renseigné',
                    $client->phone ?: 'Non renseigné',
                    $client->active ? 'Actif' : 'Inactif',
                    $client->sales_count,
                    $client->prescriptions_count ?? 0,
                    $action,
                    $client->created_at->format('d/m/Y H:i'),
                    $client->updated_at->format('d/m/Y H:i')
                ], ';');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Voir les ventes orphelines (sans client).
     */
    public function orphanedSales()
    {
        $orphanedSales = Sale::whereNull('client_id')
                           ->with(['saleItems.product', 'user'])
                           ->latest()
                           ->paginate(15);
                           
        return view('clients.orphaned-sales', compact('orphanedSales'));
    }
}
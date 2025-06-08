<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Client;
use App\Models\Product;
use App\Models\ActivityLog;

class PrescriptionController extends Controller
{
    public function __construct() 
    { 
        $this->middleware('auth'); 
    }

    /**
     * Display a listing of the prescriptions.
     */
    public function index(Request $request)
    {
        $query = Prescription::with(['client', 'createdBy', 'prescriptionItems.product']);

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('prescription_number', 'like', "%{$search}%")
                  ->orWhere('doctor_name', 'like', "%{$search}%")
                  ->orWhereHas('client', function($clientQuery) use ($search) {
                      $clientQuery->where('first_name', 'like', "%{$search}%")
                                 ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('prescription_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('prescription_date', '<=', $request->date_to);
        }

        // Filter by expiry
        if ($request->has('expiry_filter') && $request->expiry_filter !== '') {
            if ($request->expiry_filter === 'expired') {
                $query->where('expiry_date', '<', now());
            } elseif ($request->expiry_filter === 'expiring_soon') {
                $query->where('expiry_date', '<=', now()->addDays(7))
                      ->where('expiry_date', '>', now());
            }
        }

        $prescriptions = $query->latest('prescription_date')->paginate(15);
        
        // Statistics
        $totalPrescriptions = Prescription::count();
        $pendingCount = Prescription::where('status', 'pending')->count();
        $expiredCount = Prescription::where('expiry_date', '<', now())->count();
        $expiringCount = Prescription::where('expiry_date', '<=', now()->addDays(7))
                                   ->where('expiry_date', '>', now())
                                   ->count();
        
        return view('prescriptions.index', compact(
            'prescriptions', 'totalPrescriptions', 'pendingCount', 'expiredCount', 'expiringCount'
        ));
    }

    /**
     * Show the form for creating a new prescription.
     */
    public function create()
    {
        $clients = Client::where('active', true)->orderBy('first_name')->get();
        $products = Product::where('prescription_required', true)->orderBy('name')->get();
        
        return view('prescriptions.create', compact('clients', 'products'));
    }

    /**
     * Store a newly created prescription in storage.
     */
    public function store(Request $request)
    {
        Log::info('Prescription creation attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->all()
        ]);

        // Validation complète et améliorée
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'doctor_name' => 'required|string|max:255',
            'doctor_phone' => 'nullable|string|max:20',
            'doctor_speciality' => 'nullable|string|max:255',
            'prescription_date' => 'required|date|before_or_equal:today',
            'expiry_date' => 'required|date|after:prescription_date',
            'medical_notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_prescribed' => 'required|integer|min:1',
            'items.*.dosage_instructions' => 'required|string|max:255',
            'items.*.duration_days' => 'nullable|integer|min:1|max:365',
            'items.*.instructions' => 'nullable|string|max:500',
            'items.*.is_substitutable' => 'nullable|boolean',
        ], [
            'client_id.required' => 'Veuillez sélectionner un client.',
            'client_id.exists' => 'Le client sélectionné n\'existe pas.',
            'doctor_name.required' => 'Le nom du médecin est requis.',
            'doctor_name.max' => 'Le nom du médecin ne peut pas dépasser 255 caractères.',
            'doctor_phone.max' => 'Le téléphone du médecin ne peut pas dépasser 20 caractères.',
            'doctor_speciality.max' => 'La spécialité du médecin ne peut pas dépasser 255 caractères.',
            'prescription_date.required' => 'La date de prescription est requise.',
            'prescription_date.date' => 'La date de prescription doit être une date valide.',
            'prescription_date.before_or_equal' => 'La date de prescription ne peut pas être dans le futur.',
            'expiry_date.required' => 'La date d\'expiration est requise.',
            'expiry_date.date' => 'La date d\'expiration doit être une date valide.',
            'expiry_date.after' => 'La date d\'expiration doit être postérieure à la date de prescription.',
            'medical_notes.max' => 'Les notes médicales ne peuvent pas dépasser 1000 caractères.',
            'items.required' => 'Veuillez ajouter au moins un médicament à l\'ordonnance.',
            'items.array' => 'Les données des médicaments sont invalides.',
            'items.min' => 'Veuillez ajouter au moins un médicament à l\'ordonnance.',
            'items.*.product_id.required' => 'ID du produit manquant.',
            'items.*.product_id.exists' => 'Un des médicaments sélectionnés n\'existe pas.',
            'items.*.quantity_prescribed.required' => 'Quantité prescrite manquante.',
            'items.*.quantity_prescribed.integer' => 'La quantité prescrite doit être un nombre entier.',
            'items.*.quantity_prescribed.min' => 'La quantité prescrite doit être d\'au moins 1.',
            'items.*.dosage_instructions.required' => 'Les instructions de dosage sont requises.',
            'items.*.dosage_instructions.max' => 'Les instructions de dosage ne peuvent pas dépasser 255 caractères.',
            'items.*.duration_days.integer' => 'La durée doit être un nombre entier.',
            'items.*.duration_days.min' => 'La durée doit être d\'au moins 1 jour.',
            'items.*.duration_days.max' => 'La durée ne peut pas dépasser 365 jours.',
            'items.*.instructions.max' => 'Les instructions ne peuvent pas dépasser 500 caractères.',
        ]);

        if ($validator->fails()) {
            Log::warning('Prescription creation validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Validation supplémentaire des produits
        $itemsData = [];
        $errors = [];

        try {
            // Vérifier que le client existe et est actif
            $client = Client::where('id', $request->client_id)
                           ->where('active', true)
                           ->first();
            
            if (!$client) {
                throw new \Exception('Le client sélectionné n\'est pas disponible.');
            }

            foreach ($request->items as $index => $item) {
                $product = Product::find($item['product_id']);
                if (!$product) {
                    $errors[] = "Le médicament avec l'ID {$item['product_id']} n'existe pas.";
                    continue;
                }
                
                // Vérifier que le produit nécessite une prescription
                if (!$product->prescription_required) {
                    $errors[] = "Le produit {$product->name} ne nécessite pas d'ordonnance.";
                    continue;
                }
                
                $quantity = (int) $item['quantity_prescribed'];
                if ($quantity <= 0) {
                    $errors[] = "La quantité prescrite pour {$product->name} doit être supérieure à 0.";
                    continue;
                }
                
                if (empty(trim($item['dosage_instructions']))) {
                    $errors[] = "Les instructions de dosage pour {$product->name} sont requises.";
                    continue;
                }
                
                $itemsData[] = [
                    'product' => $product,
                    'quantity_prescribed' => $quantity,
                    'dosage_instructions' => trim($item['dosage_instructions']),
                    'duration_days' => !empty($item['duration_days']) ? (int) $item['duration_days'] : null,
                    'instructions' => !empty($item['instructions']) ? trim($item['instructions']) : null,
                    'is_substitutable' => isset($item['is_substitutable']) && $item['is_substitutable']
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error processing prescription items', [
                'error' => $e->getMessage(),
                'items' => $request->items
            ]);
            $errors[] = "Erreur lors du traitement des médicaments: " . $e->getMessage();
        }

        if (!empty($errors)) {
            return redirect()->back()
                ->withErrors(['items' => implode(' ', $errors)])
                ->withInput();
        }

        if (empty($itemsData)) {
            return redirect()->back()
                ->withErrors(['items' => 'Aucun médicament valide trouvé dans l\'ordonnance.'])
                ->withInput();
        }

        DB::beginTransaction();
        
        try {
            // Create prescription
            $prescription = new Prescription();
            $prescription->client_id = $request->client_id;
            $prescription->doctor_name = trim($request->doctor_name);
            $prescription->doctor_phone = $request->doctor_phone ? trim($request->doctor_phone) : null;
            $prescription->doctor_speciality = $request->doctor_speciality ? trim($request->doctor_speciality) : null;
            $prescription->prescription_date = $request->prescription_date;
            $prescription->expiry_date = $request->expiry_date;
            $prescription->medical_notes = $request->medical_notes ? trim($request->medical_notes) : null;
            $prescription->created_by = auth()->id();
            $prescription->status = 'pending';
            $prescription->save();

            Log::info('Prescription created successfully', [
                'prescription_id' => $prescription->id,
                'prescription_number' => $prescription->prescription_number,
                'client_id' => $prescription->client_id,
                'items_count' => count($itemsData)
            ]);

            // Create prescription items
            foreach ($itemsData as $itemData) {
                $prescriptionItem = new PrescriptionItem();
                $prescriptionItem->prescription_id = $prescription->id;
                $prescriptionItem->product_id = $itemData['product']->id;
                $prescriptionItem->quantity_prescribed = $itemData['quantity_prescribed'];
                $prescriptionItem->quantity_delivered = 0; // Initialement 0
                $prescriptionItem->dosage_instructions = $itemData['dosage_instructions'];
                $prescriptionItem->duration_days = $itemData['duration_days'];
                $prescriptionItem->instructions = $itemData['instructions'];
                $prescriptionItem->is_substitutable = $itemData['is_substitutable'];
                $prescriptionItem->save();
                
                Log::info('Prescription item created', [
                    'prescription_item_id' => $prescriptionItem->id,
                    'product_id' => $itemData['product']->id,
                    'product_name' => $itemData['product']->name,
                    'quantity_prescribed' => $itemData['quantity_prescribed']
                ]);
            }

            // Log prescription creation
            try {
                ActivityLog::logActivity(
                    'create',
                    "Ordonnance créée: {$prescription->prescription_number} - Client: {$client->full_name} - Médecin: {$prescription->doctor_name}",
                    $prescription,
                    null,
                    [
                        'client_name' => $client->full_name,
                        'doctor_name' => $prescription->doctor_name,
                        'items_count' => count($itemsData),
                        'prescription_date' => $prescription->prescription_date,
                        'expiry_date' => $prescription->expiry_date
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('Failed to log prescription creation activity', [
                    'error' => $e->getMessage(),
                    'prescription_id' => $prescription->id
                ]);
            }

            DB::commit();

            return redirect()->route('prescriptions.show', $prescription->id)
                ->with('success', 'Ordonnance créée avec succès!');
                
        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Prescription creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['items']),
                'items_count' => count($itemsData ?? [])
            ]);
            
            // Log error activity
            try {
                ActivityLog::logActivity(
                    'error',
                    "Erreur lors de la création d'une ordonnance: " . $e->getMessage(),
                    null,
                    null,
                    [
                        'error_details' => $e->getMessage(),
                        'client_id' => $request->client_id,
                        'items_count' => count($itemsData ?? [])
                    ]
                );
            } catch (\Exception $logError) {
                Log::warning('Failed to log error activity', [
                    'log_error' => $logError->getMessage()
                ]);
            }
            
            $errorMessage = 'Erreur lors de la création de l\'ordonnance. Veuillez réessayer.';
            
            if (str_contains($e->getMessage(), 'client')) {
                $errorMessage = 'Problème avec le client sélectionné.';
            } elseif (str_contains($e->getMessage(), 'produit') || str_contains($e->getMessage(), 'médicament')) {
                $errorMessage = 'Problème avec un ou plusieurs médicaments de l\'ordonnance.';
            } elseif (str_contains($e->getMessage(), 'database') || str_contains($e->getMessage(), 'SQL')) {
                $errorMessage = 'Erreur de base de données. Veuillez réessayer.';
            }
            
            return redirect()->back()
                ->withErrors(['error' => $errorMessage])
                ->withInput();
        }
    }

    /**
     * Display the specified prescription.
     */
    public function show($id)
    {
        $prescription = Prescription::with(['client', 'createdBy', 'deliveredBy', 'prescriptionItems.product'])->findOrFail($id);
        return view('prescriptions.show', compact('prescription'));
    }

    /**
     * Show the form for editing the specified prescription.
     */
    public function edit($id)
    {
        $prescription = Prescription::with(['prescriptionItems.product'])->findOrFail($id);
        
        if (in_array($prescription->status, ['completed', 'expired'])) {
            return redirect()->route('prescriptions.show', $prescription->id)
                ->withErrors(['error' => 'Cette ordonnance ne peut plus être modifiée.']);
        }
        
        $clients = Client::where('active', true)->orderBy('first_name')->get();
        $products = Product::where('prescription_required', true)->orderBy('name')->get();
        
        return view('prescriptions.edit', compact('prescription', 'clients', 'products'));
    }

    /**
     * Update the specified prescription in storage.
     */
    public function update(Request $request, $id)
    {
        $prescription = Prescription::findOrFail($id);
        $oldValues = $prescription->toArray();
        
        if (in_array($prescription->status, ['completed', 'expired'])) {
            return redirect()->route('prescriptions.show', $prescription->id)
                ->withErrors(['error' => 'Cette ordonnance ne peut plus être modifiée.']);
        }

        $validator = Validator::make($request->all(), [
            'doctor_name' => 'required|string|max:255',
            'doctor_phone' => 'nullable|string|max:20',
            'doctor_speciality' => 'nullable|string|max:255',
            'prescription_date' => 'required|date|before_or_equal:today',
            'expiry_date' => 'required|date|after:prescription_date',
            'medical_notes' => 'nullable|string|max:1000',
            'pharmacist_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            $prescription->update([
                'doctor_name' => trim($request->doctor_name),
                'doctor_phone' => $request->doctor_phone ? trim($request->doctor_phone) : null,
                'doctor_speciality' => $request->doctor_speciality ? trim($request->doctor_speciality) : null,
                'prescription_date' => $request->prescription_date,
                'expiry_date' => $request->expiry_date,
                'medical_notes' => $request->medical_notes ? trim($request->medical_notes) : null,
                'pharmacist_notes' => $request->pharmacist_notes ? trim($request->pharmacist_notes) : null,
            ]);

            // Log prescription update
            ActivityLog::logActivity(
                'update',
                "Ordonnance modifiée: {$prescription->prescription_number}",
                $prescription,
                $oldValues,
                $prescription->toArray()
            );

            return redirect()->route('prescriptions.show', $prescription->id)
                ->with('success', 'Ordonnance mise à jour avec succès!');
                
        } catch (\Exception $e) {
            Log::error('Prescription update failed', [
                'prescription_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la mise à jour de l\'ordonnance.'])
                ->withInput();
        }
    }

    /**
     * Show the form for delivering a prescription.
     */
    public function deliver($id)
    {
        $prescription = Prescription::with(['client', 'prescriptionItems.product'])->findOrFail($id);
        
        if ($prescription->status === 'completed') {
            return redirect()->route('prescriptions.show', $prescription->id)
                ->withErrors(['error' => 'Cette ordonnance a déjà été complètement délivrée.']);
        }
        
        if ($prescription->isExpired()) {
            return redirect()->route('prescriptions.show', $prescription->id)
                ->withErrors(['error' => 'Cette ordonnance a expiré et ne peut plus être délivrée.']);
        }
        
        return view('prescriptions.deliver', compact('prescription'));
    }

    /**
     * Process delivery of a prescription.
     */
    public function processDelivery(Request $request, $id)
    {
        $prescription = Prescription::with(['prescriptionItems.product'])->findOrFail($id);
        
        if ($prescription->isExpired()) {
            return redirect()->route('prescriptions.show', $prescription->id)
                ->withErrors(['error' => 'Cette ordonnance a expiré.']);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:prescription_items,id',
            'items.*.quantity_to_deliver' => 'required|integer|min:0',
            'pharmacist_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        
        try {
            $deliveredItems = [];
            $stockChanges = [];
            
            foreach ($request->items as $itemData) {
                $prescriptionItem = PrescriptionItem::find($itemData['item_id']);
                if (!$prescriptionItem || $prescriptionItem->prescription_id != $prescription->id) {
                    throw new \Exception("Item d'ordonnance invalide.");
                }
                
                $quantityToDeliver = (int) $itemData['quantity_to_deliver'];
                $maxQuantity = $prescriptionItem->quantity_prescribed - $prescriptionItem->quantity_delivered;
                
                if ($quantityToDeliver > $maxQuantity) {
                    throw new \Exception("Quantité trop élevée pour {$prescriptionItem->product->name}. Maximum: {$maxQuantity}");
                }
                
                if ($quantityToDeliver > 0) {
                    // Vérifier le stock disponible
                    if ($prescriptionItem->product->stock_quantity < $quantityToDeliver) {
                        throw new \Exception("Stock insuffisant pour {$prescriptionItem->product->name}. Stock disponible: {$prescriptionItem->product->stock_quantity}");
                    }
                    
                    $oldStock = $prescriptionItem->product->stock_quantity;
                    $prescriptionItem->quantity_delivered += $quantityToDeliver;
                    $prescriptionItem->save();
                    
                    $prescriptionItem->product->decrement('stock_quantity', $quantityToDeliver);
                    $newStock = $prescriptionItem->product->fresh()->stock_quantity;
                    
                    // Log stock change
                    ActivityLog::logActivity(
                        'stock_update',
                        "Stock diminué pour {$prescriptionItem->product->name}: {$oldStock} → {$newStock} (-{$quantityToDeliver}) - Délivrance ordonnance #{$prescription->prescription_number}",
                        $prescriptionItem->product,
                        ['stock_quantity' => $oldStock],
                        ['stock_quantity' => $newStock, 'change' => -$quantityToDeliver, 'reason' => "Délivrance ordonnance #{$prescription->prescription_number}"]
                    );
                    
                    $deliveredItems[] = [
                        'product_name' => $prescriptionItem->product->name,
                        'quantity' => $quantityToDeliver
                    ];
                    
                    $stockChanges[] = [
                        'product_id' => $prescriptionItem->product->id,
                        'old_stock' => $oldStock,
                        'new_stock' => $newStock
                    ];
                }
            }
            
            if ($request->pharmacist_notes) {
                $prescription->pharmacist_notes = trim($request->pharmacist_notes);
            }
            
            // Update prescription status
            $prescription->updateStatus();
            
            if ($prescription->status === 'completed') {
                $prescription->delivered_at = now();
                $prescription->delivered_by = auth()->id();
                $prescription->save();
            }
            
            // Log delivery
            ActivityLog::logActivity(
                'deliver',
                "Délivrance de l'ordonnance: {$prescription->prescription_number} - " . count($deliveredItems) . " médicament(s) délivré(s)",
                $prescription,
                null,
                [
                    'delivered_items' => $deliveredItems,
                    'stock_changes' => $stockChanges,
                    'pharmacist_notes' => $request->pharmacist_notes,
                    'delivered_by' => auth()->user()->name
                ]
            );
            
            DB::commit();

            return redirect()->route('prescriptions.show', $prescription->id)
                ->with('success', 'Délivrance enregistrée avec succès!');
                
        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Prescription delivery failed', [
                'prescription_id' => $id,
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);
            
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la délivrance de l'ordonnance #{$prescription->prescription_number}: " . $e->getMessage(),
                $prescription
            );
            
            return redirect()->back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Print prescription.
     */
    public function print($id)
    {
        $prescription = Prescription::with(['client', 'createdBy', 'prescriptionItems.product'])->findOrFail($id);
        
        // Log print action
        ActivityLog::logActivity(
            'print',
            "Impression de l'ordonnance: {$prescription->prescription_number}",
            $prescription
        );
        
        return view('prescriptions.print', compact('prescription'));
    }
}
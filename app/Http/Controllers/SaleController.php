<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Client;
use App\Models\Product;
use App\Models\Notification;

class SaleController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the sales.
     */
    public function index(Request $request)
    {
        $query = Sale::with(['client', 'user', 'saleItems.product']);

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                  ->orWhere('prescription_number', 'like', "%{$search}%")
                  ->orWhereHas('client', function($clientQuery) use ($search) {
                      $clientQuery->where('first_name', 'like', "%{$search}%")
                                 ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by payment status
        if ($request->has('payment_status') && $request->payment_status !== '') {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('sale_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('sale_date', '<=', $request->date_to);
        }

        // Filter by prescription
        if ($request->has('has_prescription') && $request->has_prescription !== '') {
            $query->where('has_prescription', $request->has_prescription === 'yes');
        }

        $sales = $query->latest('sale_date')->paginate(15);
        
        // Calculate summary statistics
        $allSales = Sale::all();
        $totalSales = $allSales->sum('total_amount');
        $salesCount = $allSales->count();
        $averageSale = $salesCount > 0 ? $totalSales / $salesCount : 0;
        
        return view('sales.index', compact('sales', 'totalSales', 'salesCount', 'averageSale'));
    }

    /**
     * Show the form for creating a new sale.
     */
    public function create(Request $request)
    {
        $clients = Client::active()->orderBy('first_name')->get();
        $products = Product::where('stock_quantity', '>', 0)->orderBy('name')->get();
        
        // Pre-select client if passed in URL
        $selectedClientId = $request->get('client_id');
        
        return view('sales.create', compact('clients', 'products', 'selectedClientId'));
    }

    /**
     * Store a newly created sale in storage.
     */
    public function store(Request $request)
    {
        Log::info('Sale creation attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'client_id' => 'nullable|exists:clients,id',
            'payment_method' => 'required|in:cash,card,insurance,other',
            'has_prescription' => 'boolean',
            'prescription_number' => 'nullable|string|max:255',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ], [
            'products.required' => 'Veuillez ajouter au moins un produit à la vente.',
            'products.*.id.required' => 'ID produit manquant.',
            'products.*.id.exists' => 'Un des produits sélectionnés n\'existe pas.',
            'products.*.quantity.required' => 'Quantité manquante pour un produit.',
            'products.*.quantity.min' => 'La quantité doit être d\'au moins 1.',
        ]);

        if ($validator->fails()) {
            Log::warning('Sale creation validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Validate stock availability and prepare product data
        $productData = [];
        foreach ($request->products as $item) {
            $product = Product::find($item['id']);
            if (!$product) {
                return redirect()->back()
                    ->withErrors(['products' => "Produit avec l'ID {$item['id']} introuvable."])
                    ->withInput();
            }
            
            $quantity = (int) $item['quantity'];
            if ($product->stock_quantity < $quantity) {
                return redirect()->back()
                    ->withErrors(['products' => "Stock insuffisant pour {$product->name}. Stock disponible: {$product->stock_quantity}, demandé: {$quantity}"])
                    ->withInput();
            }
            
            $productData[] = [
                'product' => $product,
                'quantity' => $quantity
            ];
        }

        DB::beginTransaction();
        
        try {
            // Create sale
            $sale = new Sale();
            $sale->client_id = $request->client_id;
            $sale->user_id = auth()->id();
            $sale->payment_method = $request->payment_method;
            $sale->payment_status = 'paid';
            $sale->has_prescription = $request->has('has_prescription');
            $sale->prescription_number = $request->prescription_number;
            $sale->discount_amount = $request->discount_amount ?? 0;
            $sale->notes = $request->notes;
            $sale->sale_date = now();
            
            // Calculate totals before saving
            $subtotal = 0;
            foreach ($productData as $item) {
                $subtotal += $item['product']->selling_price * $item['quantity'];
            }
            
            $sale->subtotal = $subtotal;
            $sale->tax_amount = $subtotal * 0.20; // 20% tax
            $sale->total_amount = $subtotal + $sale->tax_amount - $sale->discount_amount;
            
            $sale->save();

            Log::info('Sale created successfully', [
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_amount' => $sale->total_amount
            ]);

            // Create sale items and update stock
            foreach ($productData as $item) {
                $saleItem = new SaleItem();
                $saleItem->sale_id = $sale->id;
                $saleItem->product_id = $item['product']->id;
                $saleItem->quantity = $item['quantity'];
                $saleItem->unit_price = $item['product']->selling_price;
                $saleItem->total_price = $item['product']->selling_price * $item['quantity'];
                $saleItem->save();

                // Update product stock
                $item['product']->decrement('stock_quantity', $item['quantity']);
                
                Log::info('Product stock updated', [
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'quantity_sold' => $item['quantity'],
                    'remaining_stock' => $item['product']->fresh()->stock_quantity
                ]);
            }

            DB::commit();

            // ========== IMMEDIATE NOTIFICATION CHECKS - ADDED ==========
            try {
                // Check for significant sales (notify admins for sales >= 50€)
                if ($sale->total_amount >= 50) {
                    $this->createSaleNotification($sale);
                }

                // Check stock levels for all products in this sale
                foreach ($productData as $item) {
                    $product = $item['product']->fresh(); // Get updated product
                    
                    // Check if product is now low stock and notify immediately
                    if ($product->isLowStock()) {
                        $this->createStockAlert($product);
                    }
                }
                
                Log::info('Immediate notifications processed for sale', [
                    'sale_id' => $sale->id,
                    'products_checked' => count($productData)
                ]);

            } catch (\Exception $notificationError) {
                // Log notification errors but don't fail the sale
                Log::warning('Notification creation failed after sale', [
                    'sale_id' => $sale->id,
                    'error' => $notificationError->getMessage()
                ]);
            }
            // ========== END IMMEDIATE NOTIFICATION CHECKS ==========

            return redirect()->route('sales.show', $sale->id)
                ->with('success', 'Vente enregistrée avec succès!');
                
        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Sale creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de l\'enregistrement de la vente. Veuillez réessayer.'])
                ->withInput();
        }
    }

    /**
     * Display the specified sale.
     */
    public function show($id)
    {
        $sale = Sale::with(['client', 'user', 'saleItems.product'])->findOrFail($id);
        
        return view('sales.show', compact('sale'));
    }

    /**
     * Show the form for editing the specified sale.
     */
    public function edit($id)
    {
        $sale = Sale::with(['saleItems.product'])->findOrFail($id);
        $clients = Client::active()->orderBy('first_name')->get();
        
        return view('sales.edit', compact('sale', 'clients'));
    }

    /**
     * Update the specified sale in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'payment_status' => 'required|in:paid,pending,failed',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $sale = Sale::findOrFail($id);
        $sale->payment_status = $request->payment_status;
        $sale->notes = $request->notes;
        $sale->save();

        return redirect()->route('sales.show', $sale->id)
            ->with('success', 'Vente mise à jour avec succès!');
    }

    /**
     * Remove the specified sale from storage.
     */
    public function destroy($id)
    {
        $sale = Sale::with(['saleItems.product'])->findOrFail($id);
        
        // Vérifier si la vente peut être supprimée
        if ($sale->sale_date < now()->subDays(7)) {
            return redirect()->route('sales.index')
                ->withErrors(['error' => 'Impossible de supprimer une vente de plus de 7 jours.']);
        }

        DB::beginTransaction();
        
        try {
            // Restaurer le stock des produits
            foreach ($sale->saleItems as $item) {
                $item->product->increment('stock_quantity', $item->quantity);
                
                Log::info('Stock restored for product', [
                    'product_id' => $item->product->id,
                    'product_name' => $item->product->name,
                    'quantity_restored' => $item->quantity,
                    'new_stock' => $item->product->fresh()->stock_quantity
                ]);
            }
            
            // Supprimer les items de vente
            $sale->saleItems()->delete();
            
            // Supprimer la vente
            $sale->delete();
            
            DB::commit();
            
            Log::info('Sale deleted successfully', [
                'sale_id' => $id,
                'sale_number' => $sale->sale_number,
                'deleted_by' => auth()->id()
            ]);

            return redirect()->route('sales.index')
                ->with('success', 'Vente supprimée avec succès! Le stock a été restauré.');
                
        } catch (\Exception $e) {
            DB::rollback();
            
            Log::error('Sale deletion failed', [
                'sale_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('sales.index')
                ->withErrors(['error' => 'Erreur lors de la suppression de la vente. Veuillez réessayer.']);
        }
    }

    /**
     * Get product details for AJAX requests.
     */
    public function getProduct($id)
    {
        $product = Product::find($id);
        
        if (!$product) {
            return response()->json(['error' => 'Produit non trouvé'], 404);
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->selling_price,
            'stock' => $product->stock_quantity,
            'prescription_required' => $product->prescription_required,
        ]);
    }

    /**
     * Print sale receipt.
     */
    public function print($id)
    {
        $sale = Sale::with(['client', 'user', 'saleItems.product'])->findOrFail($id);
        
        return view('sales.print', compact('sale'));
    }

    // ========== PRIVATE NOTIFICATION METHODS - ADDED ==========

    /**
     * Create sale notification for significant sales
     */
    private function createSaleNotification(Sale $sale)
    {
        try {
            // Get all admin users
            $admins = \App\Models\User::where('role', 'responsable')->get();
            
            $clientName = $sale->client ? $sale->client->full_name : 'Client anonyme';
            $userName = $sale->user ? $sale->user->name : 'Utilisateur';
            
            foreach ($admins as $admin) {
                // Check if similar notification exists in last 30 minutes
                $existingNotification = Notification::where('type', 'sale_created')
                    ->where('user_id', $admin->id)
                    ->where('data->sale_id', $sale->id)
                    ->where('created_at', '>=', now()->subMinutes(30))
                    ->first();

                if (!$existingNotification) {
                    Notification::create([
                        'user_id' => $admin->id,
                        'type' => 'sale_created',
                        'title' => 'Nouvelle vente importante',
                        'message' => "Vente #{$sale->sale_number} de {$sale->total_amount}€ pour {$clientName} par {$userName}",
                        'data' => [
                            'sale_id' => $sale->id,
                            'amount' => $sale->total_amount,
                            'client_name' => $clientName,
                            'user_name' => $userName
                        ],
                        'priority' => $sale->total_amount >= 100 ? 'medium' : 'low',
                        'action_url' => route('sales.show', $sale->id),
                    ]);
                }
            }

            Log::info('Sale notification created', [
                'sale_id' => $sale->id,
                'amount' => $sale->total_amount,
                'admin_count' => $admins->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create sale notification', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create stock alert notification
     */
    private function createStockAlert(Product $product)
    {
        try {
            // Get all users (both admins and pharmacists should know about stock issues)
            $users = \App\Models\User::where('is_active', true)->get();
            
            foreach ($users as $user) {
                // Check if notification already exists for this product (within last hour)
                $existingNotification = Notification::where('type', 'stock_alert')
                    ->where('user_id', $user->id)
                    ->where('data->product_id', $product->id)
                    ->where('created_at', '>=', now()->subHour())
                    ->first();

                if (!$existingNotification) {
                    Notification::create([
                        'user_id' => $user->id,
                        'type' => 'stock_alert',
                        'title' => 'Stock critique détecté',
                        'message' => "Le produit {$product->name} a un stock critique ({$product->stock_quantity} unités restantes, seuil: {$product->stock_threshold})",
                        'data' => [
                            'product_id' => $product->id,
                            'current_stock' => $product->stock_quantity,
                            'threshold' => $product->stock_threshold,
                            'product_name' => $product->name
                        ],
                        'priority' => $product->stock_quantity <= 0 ? 'high' : 'medium',
                        'action_url' => route('inventory.show', $product->id),
                    ]);
                }
            }

            Log::info('Stock alert notification created', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'current_stock' => $product->stock_quantity,
                'threshold' => $product->stock_threshold,
                'user_count' => $users->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create stock alert notification', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
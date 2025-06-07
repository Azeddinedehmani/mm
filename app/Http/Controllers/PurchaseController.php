<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\ActivityLog;

class PurchaseController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('admin'); // Seuls les admins peuvent gérer les achats
    }

    /**
     * Display a listing of the purchases.
     */
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier', 'user', 'purchaseItems.product']);

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('purchase_number', 'like', "%{$search}%")
                  ->orWhereHas('supplier', function($supplierQuery) use ($search) {
                      $supplierQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filter by supplier
        if ($request->has('supplier') && $request->supplier !== '') {
            $query->where('supplier_id', $request->supplier);
        }

        // Filter by date range
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        $purchases = $query->latest('order_date')->paginate(15);
        
        // Calculate summary statistics
        $totalPurchases = Purchase::sum('total_amount');
        $pendingCount = Purchase::pending()->count();
        $overdueCount = Purchase::overdue()->count();
        $receivedCount = Purchase::received()->count();
        
        $suppliers = Supplier::where('active', true)->get();
        
        return view('purchases.index', compact(
            'purchases', 'totalPurchases', 'pendingCount', 'overdueCount', 'receivedCount', 'suppliers'
        ));
    }

    /**
     * Show the form for creating a new purchase.
     */
    public function create(Request $request)
    {
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $selectedSupplierId = $request->get('supplier_id');
        
        return view('purchases.create', compact('suppliers', 'products', 'selectedSupplierId'));
    }

    /**
     * Store a newly created purchase in storage.
     */
    public function store(Request $request)
    {
        Log::info('Purchase creation attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.price' => 'required|numeric|min:0',
        ], [
            'products.required' => 'Veuillez ajouter au moins un produit à la commande.',
            'products.*.id.required' => 'ID produit manquant.',
            'products.*.id.exists' => 'Un des produits sélectionnés n\'existe pas.',
            'products.*.quantity.required' => 'Quantité manquante pour un produit.',
            'products.*.quantity.min' => 'La quantité doit être d\'au moins 1.',
            'products.*.price.required' => 'Prix manquant pour un produit.',
            'products.*.price.min' => 'Le prix doit être positif.',
        ]);

        if ($validator->fails()) {
            Log::warning('Purchase creation validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        DB::beginTransaction();
        
        try {
            // Create purchase
            $purchase = new Purchase();
            $purchase->supplier_id = $request->supplier_id;
            $purchase->user_id = auth()->id();
            $purchase->order_date = $request->order_date;
            $purchase->expected_date = $request->expected_date;
            $purchase->notes = $request->notes;
            
            // Calculate totals
            $subtotal = 0;
            foreach ($request->products as $item) {
                $subtotal += $item['quantity'] * $item['price'];
            }
            
            $purchase->subtotal = $subtotal;
            $purchase->tax_amount = $subtotal * 0.20; // 20% tax
            $purchase->total_amount = $subtotal + $purchase->tax_amount;
            
            $purchase->save();

            // Log purchase creation
            ActivityLog::logTransaction('purchase', $purchase, 'create', [
                'supplier_name' => $purchase->supplier->name,
                'total_amount' => $purchase->total_amount,
                'products_count' => count($request->products)
            ]);

            Log::info('Purchase created successfully', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'total_amount' => $purchase->total_amount
            ]);

            // Create purchase items
            foreach ($request->products as $item) {
                $purchaseItem = new PurchaseItem();
                $purchaseItem->purchase_id = $purchase->id;
                $purchaseItem->product_id = $item['id'];
                $purchaseItem->quantity_ordered = $item['quantity'];
                $purchaseItem->unit_price = $item['price'];
                $purchaseItem->total_price = $item['quantity'] * $item['price'];
                $purchaseItem->save();
                
                Log::info('Purchase item created', [
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id' => $item['id'],
                    'quantity' => $item['quantity']
                ]);
            }

            DB::commit();

            return redirect()->route('purchases.show', $purchase->id)
                ->with('success', 'Commande d\'achat créée avec succès!');
                
        } catch (\Exception $e) {
            DB::rollback();
            
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la création d'une commande d'achat: " . $e->getMessage(),
                null,
                null,
                ['error_details' => $e->getMessage(), 'request_data' => $request->all()]
            );
            
            Log::error('Purchase creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la création de la commande. Veuillez réessayer.'])
                ->withInput();
        }
    }

    /**
     * Display the specified purchase.
     */
    public function show($id)
    {
        $purchase = Purchase::with(['supplier', 'user', 'receivedBy', 'purchaseItems.product'])->findOrFail($id);
        
        return view('purchases.show', compact('purchase'));
    }

    /**
     * Show the form for editing the specified purchase.
     */
    public function edit($id)
    {
        $purchase = Purchase::with(['purchaseItems.product'])->findOrFail($id);
        
        if ($purchase->status === 'received') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Cette commande a été reçue et ne peut plus être modifiée.']);
        }
        
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();
        
        return view('purchases.edit', compact('purchase', 'suppliers'));
    }

    /**
     * Update the specified purchase in storage.
     */
    public function update(Request $request, $id)
    {
        $purchase = Purchase::findOrFail($id);
        $oldValues = $purchase->toArray();
        
        if ($purchase->status === 'received') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Cette commande a été reçue et ne peut plus être modifiée.']);
        }

        $validator = Validator::make($request->all(), [
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'status' => 'required|in:pending,cancelled',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $purchase->update($request->only(['expected_date', 'notes', 'status']));

        // Log purchase update
        ActivityLog::logTransaction('purchase', $purchase, 'update', [
            'changes' => array_diff_assoc($purchase->toArray(), $oldValues)
        ]);

        return redirect()->route('purchases.show', $purchase->id)
            ->with('success', 'Commande mise à jour avec succès!');
    }

    /**
     * Show the form for receiving a purchase.
     */
    public function receive($id)
    {
        $purchase = Purchase::with(['supplier', 'purchaseItems.product'])->findOrFail($id);
        
        if ($purchase->status === 'received') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Cette commande a déjà été complètement reçue.']);
        }
        
        if ($purchase->status === 'cancelled') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Cette commande a été annulée.']);
        }
        
        return view('purchases.receive', compact('purchase'));
    }

    /**
     * Process the reception of a purchase.
     */
    public function processReception(Request $request, $id)
    {
        $purchase = Purchase::with(['purchaseItems'])->findOrFail($id);
        
        if ($purchase->status === 'received') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Cette commande a déjà été reçue.']);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:purchase_items,id',
            'items.*.quantity_received' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        
        try {
            $receivedItems = [];
            $stockChanges = [];
            
            foreach ($request->items as $itemData) {
                $purchaseItem = PurchaseItem::find($itemData['item_id']);
                $quantityReceived = (int) $itemData['quantity_received'];
                
                $maxQuantity = $purchaseItem->quantity_ordered - $purchaseItem->quantity_received;
                if ($quantityReceived > $maxQuantity) {
                    throw new \Exception("Quantité trop élevée pour {$purchaseItem->product->name}. Maximum: {$maxQuantity}");
                }
                
                if ($quantityReceived > 0) {
                    $oldStock = $purchaseItem->product->stock_quantity;
                    $purchaseItem->quantity_received += $quantityReceived;
                    $purchaseItem->save();
                    
                    // Update product stock
                    $purchaseItem->product->increment('stock_quantity', $quantityReceived);
                    $newStock = $purchaseItem->product->fresh()->stock_quantity;
                    
                    // Log stock change
                    ActivityLog::logStockChange(
                        $purchaseItem->product,
                        $oldStock,
                        $newStock,
                        "Réception commande #{$purchase->purchase_number}"
                    );
                    
                    $receivedItems[] = [
                        'product_name' => $purchaseItem->product->name,
                        'quantity' => $quantityReceived
                    ];
                    
                    $stockChanges[] = [
                        'product_id' => $purchaseItem->product->id,
                        'old_stock' => $oldStock,
                        'new_stock' => $newStock
                    ];
                }
            }
            
            // Update purchase notes if provided
            if ($request->notes) {
                $purchase->notes = $request->notes;
                $purchase->save();
            }
            
            // Update purchase status
            $purchase->updateStatus();
            
            // Log reception
            ActivityLog::logTransaction('purchase', $purchase, 'receive', [
                'received_items' => $receivedItems,
                'stock_changes' => $stockChanges,
                'notes' => $request->notes
            ]);
            
            DB::commit();

            return redirect()->route('purchases.show', $purchase->id)
                ->with('success', 'Réception enregistrée avec succès!');
                
        } catch (\Exception $e) {
            DB::rollback();
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la réception de la commande #{$purchase->purchase_number}: " . $e->getMessage(),
                $purchase
            );
            
            return redirect()->back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Cancel a purchase.
     */
    public function cancel($id)
    {
        $purchase = Purchase::findOrFail($id);
        
        if ($purchase->status === 'received') {
            return redirect()->route('purchases.show', $purchase->id)
                ->withErrors(['error' => 'Impossible d\'annuler une commande déjà reçue.']);
        }
        
        $oldStatus = $purchase->status;
        $purchase->status = 'cancelled';
        $purchase->save();
        
        // Log cancellation
        ActivityLog::logActivity(
            'cancel',
            "Commande d'achat annulée: {$purchase->purchase_number}",
            $purchase,
            ['status' => $oldStatus],
            ['status' => 'cancelled']
        );
        
        return redirect()->route('purchases.show', $purchase->id)
            ->with('success', 'Commande annulée avec succès.');
    }

    /**
     * Print purchase order.
     */
    public function print($id)
    {
        $purchase = Purchase::with(['supplier', 'user', 'purchaseItems.product'])->findOrFail($id);
        
        // Log print action
        ActivityLog::logActivity(
            'print',
            "Impression du bon de commande: {$purchase->purchase_number}",
            $purchase
        );
        
        return view('purchases.print', compact('purchase'));
    }
}
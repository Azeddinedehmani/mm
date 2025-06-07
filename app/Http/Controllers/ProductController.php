<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Notification;

class ProductController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the products.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'supplier']);

        // Recherche
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filtre par catégorie
        if ($request->has('category') && $request->category != '') {
            $query->where('category_id', $request->category);
        }

        // Filtre par fournisseur
        if ($request->has('supplier') && $request->supplier != '') {
            $query->where('supplier_id', $request->supplier);
        }

        // Filtre par stock
        if ($request->has('stock_status') && !empty($request->stock_status)) {
            if ($request->stock_status == 'low') {
                $query->whereColumn('stock_quantity', '<=', 'stock_threshold');
            } elseif ($request->stock_status == 'out') {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        $products = $query->paginate(10);
        $categories = Category::all();
        $suppliers = Supplier::all();
        
        return view('inventory.index', compact('products', 'categories', 'suppliers'));
    }

    /**
     * Show the form for creating a new product.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function create(Request $request)
    {
        $categories = Category::all();
        $suppliers = Supplier::where('active', true)->get(); // Seuls les fournisseurs actifs
        $selectedSupplierId = $request->get('supplier_id'); // Pré-sélection du fournisseur
        
        return view('inventory.create', compact('categories', 'suppliers', 'selectedSupplierId'));
    }

    /**
     * Store a newly created product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'stock_threshold' => 'required|integer|min:0',
            'barcode' => 'nullable|string|unique:products',
            'description' => 'nullable|string',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'expiry_date' => 'nullable|date|after:today',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $product = new Product();
            $product->fill($request->except('image', 'prescription_required'));
            $product->prescription_required = $request->has('prescription_required');

            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('products', 'public');
                $product->image_path = $imagePath;
            }

            $product->save();

            // ========== IMMEDIATE NOTIFICATION CHECKS - ADDED ==========
            try {
                // Check if new product has low stock or is about to expire
                if ($product->isLowStock()) {
                    $this->createStockAlert($product);
                }

                if ($product->expiry_date && $product->isAboutToExpire(30)) {
                    $this->createExpiryAlert($product);
                }

                Log::info('Product created with immediate notification check', [
                    'product_id' => $product->id,
                    'is_low_stock' => $product->isLowStock(),
                    'is_expiring' => $product->expiry_date ? $product->isAboutToExpire(30) : false
                ]);

            } catch (\Exception $notificationError) {
                Log::warning('Notification creation failed after product creation', [
                    'product_id' => $product->id,
                    'error' => $notificationError->getMessage()
                ]);
            }
            // ========== END IMMEDIATE NOTIFICATION CHECKS ==========

            return redirect()->route('inventory.index')
                ->with('success', 'Produit ajouté avec succès!');

        } catch (\Exception $e) {
            Log::error('Product creation failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->except('image')
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la création du produit.'])
                ->withInput();
        }
    }

    /**
     * Display the specified product.
     *
     * @param  int  $id
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function show($id)
    {
        $product = Product::with(['category', 'supplier'])->findOrFail($id);
        return view('inventory.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product.
     *
     * @param  int  $id
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function edit($id)
    {
        $product = Product::findOrFail($id);
        $categories = Category::all();
        $suppliers = Supplier::all();
        return view('inventory.edit', compact('product', 'categories', 'suppliers'));
    }

    /**
     * Update the specified product in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'stock_threshold' => 'required|integer|min:0',
            'barcode' => 'nullable|string|unique:products,barcode,'.$id,
            'description' => 'nullable|string',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'expiry_date' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $product = Product::findOrFail($id);
            
            // Store old values for comparison
            $oldStock = $product->stock_quantity;
            $oldExpiryDate = $product->expiry_date;
            $oldThreshold = $product->stock_threshold;
            
            $product->fill($request->except('image', 'prescription_required'));
            $product->prescription_required = $request->has('prescription_required');

            if ($request->hasFile('image')) {
                // Supprimer l'ancienne image si elle existe
                if ($product->image_path) {
                    Storage::disk('public')->delete($product->image_path);
                }
                
                $imagePath = $request->file('image')->store('products', 'public');
                $product->image_path = $imagePath;
            }

            $product->save();

            // ========== IMMEDIATE NOTIFICATION CHECKS - ADDED ==========
            try {
                $stockChanged = ($oldStock != $product->stock_quantity || $oldThreshold != $product->stock_threshold);
                $expiryChanged = ($oldExpiryDate != $product->expiry_date);

                // Check stock level if it changed or if the product is now low stock
                if ($stockChanged && $product->isLowStock()) {
                    $this->createStockAlert($product);
                }

                // Check expiry date if it changed and product is about to expire
                if ($expiryChanged && $product->expiry_date && $product->isAboutToExpire(30)) {
                    $this->createExpiryAlert($product);
                }

                Log::info('Product updated with immediate notification check', [
                    'product_id' => $product->id,
                    'stock_changed' => $stockChanged,
                    'expiry_changed' => $expiryChanged,
                    'is_low_stock' => $product->isLowStock(),
                    'is_expiring' => $product->expiry_date ? $product->isAboutToExpire(30) : false
                ]);

            } catch (\Exception $notificationError) {
                Log::warning('Notification creation failed after product update', [
                    'product_id' => $product->id,
                    'error' => $notificationError->getMessage()
                ]);
            }
            // ========== END IMMEDIATE NOTIFICATION CHECKS ==========

            return redirect()->route('inventory.index')
                ->with('success', 'Produit mis à jour avec succès!');

        } catch (\Exception $e) {
            Log::error('Product update failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()
                ->withErrors(['error' => 'Erreur lors de la modification du produit.'])
                ->withInput();
        }
    }

    /**
     * Remove the specified product from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $productName = $product->name;
            
            // Supprimer l'image si elle existe
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            
            $product->delete();

            Log::info('Product deleted successfully', [
                'product_id' => $id,
                'product_name' => $productName,
                'deleted_by' => auth()->id()
            ]);

            return redirect()->route('inventory.index')
                ->with('success', 'Produit supprimé avec succès!');

        } catch (\Exception $e) {
            Log::error('Product deletion failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('inventory.index')
                ->withErrors(['error' => 'Erreur lors de la suppression du produit.']);
        }
    }

    // ========== PRIVATE NOTIFICATION METHODS - ADDED ==========

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

    /**
     * Create expiry alert notification
     */
    private function createExpiryAlert(Product $product)
    {
        try {
            // Get all users
            $users = \App\Models\User::where('is_active', true)->get();
            $daysUntilExpiry = now()->diffInDays($product->expiry_date);
            
            foreach ($users as $user) {
                // Check if notification already exists for this product (within last day)
                $existingNotification = Notification::where('type', 'expiry_alert')
                    ->where('user_id', $user->id)
                    ->where('data->product_id', $product->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->first();

                if (!$existingNotification) {
                    Notification::create([
                        'user_id' => $user->id,
                        'type' => 'expiry_alert',
                        'title' => 'Produit bientôt expiré',
                        'message' => "Le produit {$product->name} expire dans {$daysUntilExpiry} jours",
                        'data' => [
                            'product_id' => $product->id,
                            'expiry_date' => $product->expiry_date->format('Y-m-d'),
                            'days_until_expiry' => $daysUntilExpiry,
                            'product_name' => $product->name
                        ],
                        'priority' => $daysUntilExpiry <= 7 ? 'high' : 'medium',
                        'action_url' => route('inventory.show', $product->id),
                    ]);
                }
            }

            Log::info('Expiry alert notification created', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'expiry_date' => $product->expiry_date,
                'days_until_expiry' => $daysUntilExpiry,
                'user_count' => $users->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create expiry alert notification', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
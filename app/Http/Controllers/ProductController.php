<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\ActivityLog;

class ProductController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the products.
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
     */
    public function create(Request $request)
    {
        $categories = Category::all();
        $suppliers = Supplier::where('active', true)->get();
        $selectedSupplierId = $request->get('supplier_id');
        
        return view('inventory.create', compact('categories', 'suppliers', 'selectedSupplierId'));
    }

    /**
     * Store a newly created product in storage.
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

            // Log product creation
            ActivityLog::logActivity(
                'create',
                "Produit ajouté à l'inventaire: {$product->name}",
                $product,
                null,
                $product->toArray()
            );

            Log::info('Product created successfully', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'created_by' => auth()->id()
            ]);

            return redirect()->route('inventory.index')
                ->with('success', 'Produit ajouté avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la création du produit: " . $e->getMessage(),
                null,
                null,
                ['error_details' => $e->getMessage(), 'request_data' => $request->except('image')]
            );
            
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
     */
    public function show($id)
    {
        $product = Product::with(['category', 'supplier'])->findOrFail($id);
        return view('inventory.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product.
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
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $oldValues = $product->toArray();

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
            // Store old stock for logging
            $oldStock = $product->stock_quantity;
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

            // Log stock changes if they occurred
            if ($oldStock != $product->stock_quantity) {
                ActivityLog::logStockChange(
                    $product,
                    $oldStock,
                    $product->stock_quantity,
                    'Modification manuelle du stock'
                );
            }

            // Log product update
            ActivityLog::logActivity(
                'update',
                "Produit modifié: {$product->name}",
                $product,
                $oldValues,
                $product->toArray()
            );

            Log::info('Product updated successfully', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'updated_by' => auth()->id(),
                'stock_changed' => $oldStock != $product->stock_quantity
            ]);

            return redirect()->route('inventory.index')
                ->with('success', 'Produit mis à jour avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la modification du produit {$product->name}: " . $e->getMessage(),
                $product
            );
            
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
     */
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $productData = $product->toArray();
            $productName = $product->name;
            
            // Supprimer l'image si elle existe
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            
            // Log deletion before actually deleting
            ActivityLog::logActivity(
                'delete',
                "Produit supprimé de l'inventaire: {$productName}",
                null,
                $productData,
                null
            );
            
            $product->delete();

            Log::info('Product deleted successfully', [
                'product_id' => $id,
                'product_name' => $productName,
                'deleted_by' => auth()->id()
            ]);

            return redirect()->route('inventory.index')
                ->with('success', 'Produit supprimé avec succès!');

        } catch (\Exception $e) {
            ActivityLog::logActivity(
                'error',
                "Erreur lors de la suppression du produit: " . $e->getMessage(),
                null,
                null,
                ['product_id' => $id, 'error_details' => $e->getMessage()]
            );
            
            Log::error('Product deletion failed', [
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('inventory.index')
                ->withErrors(['error' => 'Erreur lors de la suppression du produit.']);
        }
    }
}
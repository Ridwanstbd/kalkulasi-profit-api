<?php

namespace App\Http\Controllers\Feature;

use App\Http\Controllers\Controller;
use App\Models\PriceScheme;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class PriceSchemeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = JWTAuth::user();
        $userProducts = Product::where('user_id', $user->id)->get();

        if ($userProducts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Produk yang terhubung masih kosong'
            ]);
        }

        $productId = $request->input('product_id', $userProducts->first()->id);

        $product = $userProducts->firstWhere('id', $productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan atau tidak dimiliki oleh user'
            ]);
        }

        $priceSchemas = PriceScheme::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->with(['product'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar skema harga berhasil ditampilkan',
            'data' => $priceSchemas,
            'product' => $product,
            'all_products' => $userProducts
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'level_name' => 'required|string|max:100',
            'discount_percentage' => 'nullable',
            'selling_price' => 'nullable',
            'purchase_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = JWTAuth::user();
        $productId = $request->product_id;
        $product = Product::where('id',$productId)
                    ->where('user_id',$user->id)
                    ->first();
        if(!$product){
            return response()->json([
                'success' => false,
                'message' => 'Produk tidak ditemukan atau bukan milik anda'
            ],404);
        }
        
        try {
            DB::beginTransaction();
            
            $maxLevelOrder = PriceScheme::where('user_id', $user->id)
                            ->where('product_id', $productId)
                            ->max('level_order') ?? 0;
            
            $nextLevelOrder = $maxLevelOrder + 1;
            
            $purchasePrice = null;
            
            if ($nextLevelOrder == 1) {
                $purchasePrice = $product->hpp ?? $request->purchase_price;
            } else {
                $previousSchema = PriceScheme::where('user_id', $user->id)
                                ->where('product_id', $productId)
                                ->where('level_order', $maxLevelOrder)
                                ->first();
                
                if ($previousSchema) {
                    $purchasePrice = $previousSchema->selling_price;
                } else {
                    $purchasePrice = $request->purchase_price ?? $product->hpp;
                }
            }
            
            if ($purchasePrice === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Harga pembelian (purchase price) diperlukan untuk skema harga pertama'
                ], 422);
            }
            
            $discountPercentage = null;
            $sellingPrice = null;
            $profitAmount = null;

            if($request->discount_percentage) {
                $discountPercentage = $request->discount_percentage;
                $sellingPrice = $this->calculateSellingPrice($purchasePrice, $discountPercentage);
            } elseif($request->selling_price) {
                $sellingPrice = $request->selling_price;
                $discountPercentage = $this->calculateDiscountPercentage($purchasePrice, $sellingPrice);
            } else {
                $discountPercentage = 0;
                $sellingPrice = $this->calculateSellingPrice($purchasePrice, $discountPercentage);
            }
            
            $profitAmount = $sellingPrice - $purchasePrice;
            $priceSchema = PriceScheme::create([
                'user_id' => $user->id,
                'product_id' => $productId,
                'level_name' => $request->level_name,
                'level_order' => $nextLevelOrder, 
                'discount_percentage' => $discountPercentage,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'profit_amount' => $profitAmount,
                'notes' => $request->notes
            ]);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Skema harga berhasil disimpan',
                'data' => $priceSchema
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Skema harga gagal',
                'errors' => $e->getMessage()
            ],500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = JWTAuth::user();
        $priceSchema = PriceScheme::where('id',$id)->where('user_id',$user->id)->first();
        if(!$priceSchema){
            return response()->json([
                'success'=> false,
                'message' => 'Skema harga belum ditambahkan untuk produk ini'
            ],404);
        }
        return response()->json([
            'success' => true,
            'message' => 'Detail skema harga',
            'data' => $priceSchema
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'level_name' => 'nullable|string|max:100',
            'level_order' => 'nullable|numeric',
            'discount_percentage' => 'nullable',
            'selling_price' => 'nullable',
            'purchase_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }
    
        $user = JWTAuth::user();
        $priceSchema = PriceScheme::where('id', $id)
                        ->where('user_id', $user->id)
                        ->first();
                        
        if(!$priceSchema) {
            return response()->json([
                'success' => false,
                'message' => 'Skema harga tidak ditemukan atau bukan milik anda'
            ], 404);
        }
        
        try {
            $purchasePrice = $priceSchema->purchase_price;
            
            if ($request->has('purchase_price') && $priceSchema->level_order == 1) {
                $purchasePrice = $request->purchase_price;
            } elseif ($request->has('level_order') && $request->level_order != $priceSchema->level_order) {
                $newLevelOrder = $request->level_order;
                
                if ($newLevelOrder == 1) {
                    $product = Product::find($priceSchema->product_id);
                    $purchasePrice = $product ? $product->hpp : $priceSchema->purchase_price;
                } else {
                    $previousSchema = PriceScheme::where('user_id', $user->id)
                                    ->where('product_id', $priceSchema->product_id)
                                    ->where('level_order', $newLevelOrder - 1)
                                    ->first();
                    
                    if ($previousSchema) {
                        $purchasePrice = $previousSchema->selling_price;
                    }
                }
            }

            $discountPercentage = $priceSchema->discount_percentage;
            $sellingPrice = $priceSchema->selling_price;

            if($request->has('discount_percentage') && !$request->has('selling_price')){
                $discountPercentage = $request->discount_percentage;
                $sellingPrice = $this->calculateSellingPrice($purchasePrice, $discountPercentage);
            }elseif(!$request->has('discount_percentage') && $request->has('selling_price')) {
                $sellingPrice = $request->selling_price;
                $discountPercentage = $this->calculateDiscountPercentage($purchasePrice, $sellingPrice);
            }elseif($request->has('discount_percentage') && $request->has('selling_price')) {
                $discountPercentage = $request->discount_percentage;
                $sellingPrice = $this->calculateSellingPrice($purchasePrice, $discountPercentage);
            }
            
            $profitAmount = $sellingPrice - $purchasePrice;
            $updateData = [
                'discount_percentage' => $discountPercentage,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'profit_amount' => $profitAmount,
            ];
            
            if($request->has('level_name')) {
                $updateData['level_name'] = $request->level_name;
            }
        
            $oldLevelOrder = $priceSchema->level_order;
            $newLevelOrder = $request->level_order;
            
            if($request->has('level_order') && $newLevelOrder != $oldLevelOrder) {
                $maxLevel = PriceScheme::where('user_id', $user->id)
                                ->where('product_id', $priceSchema->product_id)
                                ->count();
                
                $newLevelOrder = max(1, min($newLevelOrder, $maxLevel));
                $updateData['level_order'] = $newLevelOrder;
                
                if ($newLevelOrder > $oldLevelOrder) {
                    PriceScheme::where('user_id', $user->id)
                        ->where('product_id', $priceSchema->product_id)
                        ->whereBetween('level_order', [$oldLevelOrder + 1, $newLevelOrder])
                        ->decrement('level_order');
                } else {
                    PriceScheme::where('user_id', $user->id)
                        ->where('product_id', $priceSchema->product_id)
                        ->whereBetween('level_order', [$newLevelOrder, $oldLevelOrder - 1])
                        ->increment('level_order');
                }
                
                $nextLevelSchema = PriceScheme::where('user_id', $user->id)
                                ->where('product_id', $priceSchema->product_id)
                                ->where('level_order', $newLevelOrder + 1)
                                ->first();
                
                if ($nextLevelSchema) {
                    $nextLevelSchema->purchase_price = $sellingPrice;
                    $nextLevelSchema->profit_amount = $nextLevelSchema->selling_price - $sellingPrice;
                    $nextLevelSchema->save();
                }
            }
            
            if($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }
            
            $priceSchema->update($updateData);
            
            if ($sellingPrice != $priceSchema->getOriginal('selling_price')) {
                $nextSchema = PriceScheme::where('user_id', $user->id)
                            ->where('product_id', $priceSchema->product_id)
                            ->where('level_order', $priceSchema->level_order + 1)
                            ->first();
                
                if ($nextSchema) {
                    $nextSchema->purchase_price = $sellingPrice;
                    $nextSchema->profit_amount = $nextSchema->selling_price - $sellingPrice;
                    $nextSchema->save();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Skema harga berhasil diperbarui',
                'data' => $priceSchema->fresh()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Skema harga gagal diperbarui',
                'errors' => $e->getMessage()
            ], 500);
        }        
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = JWTAuth::user();
        $priceSchema = PriceScheme::where('id', $id)
                        ->where('user_id', $user->id)
                        ->first();
                        
        if(!$priceSchema) {
            return response()->json([
                'success' => false,
                'message' => 'Skema harga tidak ditemukan atau bukan milik anda'
            ], 404);
        }
        
        try {
            DB::beginTransaction();
            $productId = $priceSchema->product_id;
            $deletedLevelOrder = $priceSchema->level_order;
            
            $nextSchema = PriceScheme::where('user_id', $user->id)
                        ->where('product_id', $productId)
                        ->where('level_order', $deletedLevelOrder + 1)
                        ->first();
            
            $previousSchema = PriceScheme::where('user_id', $user->id)
                        ->where('product_id', $productId)
                        ->where('level_order', $deletedLevelOrder - 1)
                        ->first();
            
            $priceSchema->delete();
            
            PriceScheme::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->where('level_order', '>', $deletedLevelOrder)
                ->decrement('level_order');
            
            if ($nextSchema && $previousSchema) {
                $nextSchema->purchase_price = $previousSchema->selling_price;
                $nextSchema->profit_amount = $nextSchema->selling_price - $previousSchema->selling_price;
                
                $nextSchema->discount_percentage = $this->calculateDiscountPercentage(
                    $previousSchema->selling_price, 
                    $nextSchema->selling_price
                );
                
                $nextSchema->save();
            } else if ($nextSchema && !$previousSchema && $deletedLevelOrder == 1) {
                $product = Product::find($productId);
                if ($product) {
                    $nextSchema->purchase_price = $product->hpp;
                    $nextSchema->profit_amount = $nextSchema->selling_price - $product->hpp;
                    
                    $nextSchema->discount_percentage = $this->calculateDiscountPercentage(
                        $product->hpp, 
                        $nextSchema->selling_price
                    );
                    
                    $nextSchema->save();
                }
            }
            
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Skema harga berhasil dihapus'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Skema harga gagal dihapus',
                'errors' => $e->getMessage()
            ], 500);
        }
    }

    private function calculateSellingPrice($price, $discountValue)
    {
        $discountPercentage = min($discountValue,99.99);

        return $price / ((100 - $discountPercentage) /100);
    }
    private function calculateDiscountPercentage($price,$sellingPrice){
        if($sellingPrice <= 0 || $price <= 0){
            return 0;
        }
        $discountPercentage = 100 - (($price / $sellingPrice) * 100);
        return min($discountPercentage,99.99);
    }
}
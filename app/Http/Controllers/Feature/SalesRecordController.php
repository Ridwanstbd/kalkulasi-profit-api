<?php

namespace App\Http\Controllers\Feature;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class SalesRecordController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $user = JWTAuth::user();
        
        $year = $request->input('year', Carbon::now()->year);
        $month = $request->input('month', Carbon::now()->month);
        
        $validationResult = $this->validateIndexParams($year, $month);
        if ($validationResult !== true) {
            return $validationResult;
        }
        
        try {
            $salesRecords = $this->fetchSalesRecords($user->id, $year, $month);
            
            $salesData = $this->processSalesData($salesRecords);
            
            $summary = $this->calculateSummary($salesData);
            
            $filters = $this->getAvailableFilters($user->id, $year, $month);
            
            return response()->json([
                'success' => true,
                'data' => $salesData,
                'summary' => $summary,
                'filters' => $filters
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Validate year and month parameters
     *
     * @param int $year
     * @param int|null $month
     * @return bool|JsonResponse
     */
    private function validateIndexParams(int $year, ?int $month)
    {
        $validator = Validator::make([
            'year' => $year,
            'month' => $month,
        ], [
            'year' => 'required|integer|min:2000|max:2900',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        return true;
    }
    
    /**
     * Fetch sales records for the specified period
     *
     * @param int $userId
     * @param int $year
     * @param int|null $month
     * @return EloquentCollection
     */
    private function fetchSalesRecords(int $userId, int $year, ?int $month)
    {
        $query = SalesRecord::with('product')
            ->where('user_id', $userId)
            ->where('year', $year);
        
        if ($month) {
            $query->where('month', $month);
        }
        
        return $query->get();
    }
    
    
    /**
     * Process sales data including products without sales
     *
     * @param Collection $salesRecords
     * @param int $userId
     * @param array $productsWithSales
     * @param int $year
     * @param int|null $month
     * @return Collection
     */
    private function processSalesData(Collection $salesRecords) 
    {
        $salesDataArray = [];
        
        foreach ($salesRecords as $record) {
            $product = $record->product;
            $profitPerUnit = $record->selling_price - $record->hpp;
            $profitPercentage = $this->calculateProfitPercentage($record->selling_price, $profitPerUnit);
            
            $salesDataArray[] = [
                'id' => $record->id,
                'month' => $record->month,
                'year' => $record->year,
                'number_of_sales' => $record->number_of_sales,
                'profit_unit' => $profitPerUnit,
                'profit_percentage' => $profitPercentage,
                'sub_total' => $record->selling_price * $record->number_of_sales,
                'profit' => $profitPerUnit * $record->number_of_sales,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
            ];
        }
        
        
        $salesData = collect($salesDataArray);
        
        $totalProfit = $salesData->sum('profit');
        return $salesData->map(function ($item) use ($totalProfit) {
            $item['profit_contribution_percentage'] = $totalProfit > 0 ? 
                round(($item['profit'] / $totalProfit) * 100, 2) : 0;
            return $item;
        });
    }
    
    /**
     * Calculate profit percentage
     *
     * @param float $sellingPrice
     * @param float $profitPerUnit
     * @return float
     */
    private function calculateProfitPercentage(float $sellingPrice, float $profitPerUnit)
    {
        return $sellingPrice > 0 ? round(($profitPerUnit / $sellingPrice) * 100, 0) : 0;
    }
    
    /**
     * Calculate summary statistics
     *
     * @param Collection $salesData
     * @return array
     */
    private function calculateSummary(Collection $salesData)
    {
        $totalSales = $salesData->sum('sub_total');
        $totalProfit = $salesData->sum('profit');
        $totalProfitPercentage = $totalSales > 0 ? round(($totalProfit / $totalSales) * 100, 2) : 0;
        
        return [
            'total_sales' => $totalSales,
            'total_profit' => $totalProfit,
            'total_profit_percentage' => $totalProfitPercentage
        ];
    }
    
    /**
     * Get available years and months for filtering
     *
     * @param int $userId
     * @param int $year
     * @param int|null $month
     * @return array
     */
    private function getAvailableFilters(int $userId, int $year, ?int $month)
    {
        $availableYears = SalesRecord::where('user_id', $userId)
            ->select(DB::raw('DISTINCT year'))
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();
            
        $availableMonths = [];
        if ($year) {
            $availableMonths = SalesRecord::where('user_id', $userId)
                ->where('year', $year)
                ->select(DB::raw('DISTINCT month'))
                ->orderBy('month')
                ->pluck('month')
                ->toArray();
        }
        
        return [
            'available_years' => $availableYears,
            'available_months' => $availableMonths,
            'current_year' => $year,
            'current_month' => $month,
        ];
    }

    /**
     * Store a newly created resource in storage.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'number_of_sales' => 'required|integer|min:1',
            'hpp' => 'required|integer|min:1',
            'selling_price' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            $user = JWTAuth::user();
            
            $existingRecord = SalesRecord::where('user_id', $user->id)
                ->where('product_id', $request->product_id)
                ->where('month', $request->month)
                ->where('year', $request->year)
                ->exists();
                
            if ($existingRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjualan untuk produk ini sudah ada di bulan yang diminta'
                ], 422);
            }

            $product = Product::where('id', $request->product_id)
                ->where('user_id', $user->id)
                ->first();
                
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produk tidak ditemukan atau bukan milik anda'
                ], 404);
            }

            $record = SalesRecord::create([
                'user_id' => $user->id,
                'product_id' => $request['product_id'],
                'month' => $request['month'],
                'year' => $request['year'],
                'number_of_sales' => $request['number_of_sales'],
                'selling_price' => $request['selling_price'],
                'hpp' => $request['hpp']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Penjualan berhasil disimpan',
                'data' => $record
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id)
    {
        try {
            $user = JWTAuth::user();
            $salesRecord = SalesRecord::get()
            ->where('user_id',$user->id)
            ->find($id);
            
            if (!$salesRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjualan Tidak ditemukan!'
                ], 404);
            }
            
            $profitPerUnit = $salesRecord->selling_price - $salesRecord->hpp;
            $profitPercentage = $salesRecord->selling_price > 0 ? 
                round(($profitPerUnit / $salesRecord->selling_price) * 100, 0) : 0;
            $subTotal = $salesRecord->selling_price * $salesRecord->number_of_sales;
            $totalProfit = $profitPerUnit * $salesRecord->number_of_sales;
            
            $allSalesRecords = SalesRecord::with('product')
                ->where('user_id', $user->id)
                ->where('year', $salesRecord->year)
                ->where('month', $salesRecord->month)
                ->get();
                
            $totalProfitAllSales = 0;
            foreach ($allSalesRecords as $record) {
                $recordProfitPerUnit = $record->selling_price - $record->hpp;
                $totalProfitAllSales += $recordProfitPerUnit * $record->number_of_sales;
            }
            
            $profitContributionPercentage = $totalProfitAllSales > 0 ? 
                round(($totalProfit / $totalProfitAllSales) * 100, 2) : 0;
            
            $data = $salesRecord->toArray();
            $product = $salesRecord->product;
            $data['product_name'] = $product->name;
            $data['hpp'] = $salesRecord->hpp;
            $data['selling_price'] = $salesRecord->selling_price;
            $data['profit_unit'] = $profitPerUnit;
            $data['profit_percentage'] = $profitPercentage;
            $data['sub_total'] = $subTotal;
            $data['total_profit'] = $totalProfit;
            $data['profit_contribution_percentage'] = $profitContributionPercentage;
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     * 
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id)
    {
        try {
            $user = JWTAuth::user();
            $salesRecord = SalesRecord::where('user_id', $user->id)->find($id);
            
            if (!$salesRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjualan tidak ditemukan'
                ], 404);
            }
            
            $validator = Validator::make($request->all(), [
                'product_id' => 'sometimes|required|exists:products,id',
                'month' => 'sometimes|required|integer|min:1|max:12',
                'year' => 'sometimes|required|integer|min:2000|max:2100',
                'number_of_sales' => 'sometimes|required|integer|min:1',
                'hpp' => 'sometimes|required|integer|min:1',
                'selling_price' => 'sometimes|required|integer|min:1',
            ]);
            
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            
            if ($request->has('product_id') || $request->has('month') || $request->has('year')) {
                $product_id = $request->has('product_id') ? $request->product_id : $salesRecord->product_id;
                $month = $request->has('month') ? $request->month : $salesRecord->month;
                $year = $request->has('year') ? $request->year : $salesRecord->year;
                
                $existingRecord = SalesRecord::where('user_id', $user->id)
                    ->where('product_id', $product_id)
                    ->where('month', $month)
                    ->where('year', $year)
                    ->where('id', '!=', $id)
                    ->exists();
                    
                if ($existingRecord) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Penjualan untuk produk ini sudah ada di bulan yang diminta'
                    ], 422);
                }
                
                if ($request->has('product_id') && $request->product_id != $salesRecord->product_id) {
                    $product = Product::where('id', $request->product_id)
                        ->where('user_id', $user->id)
                        ->first();
                        
                    if (!$product) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Produk tidak ditemukan atau bukan milik anda'
                        ], 404);
                    }
                }
            }
            
            $salesRecord->update($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Penjualan berhasil diperbarui',
                'data' => $salesRecord
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id)
    {
        try {
            $user = JWTAuth::user();
            $salesRecord = SalesRecord::where('user_id',$user->id)->find($id);
            
            if (!$salesRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjualan tidak ditemukan'
                ], 404);
            }
            
            $salesRecord->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Penjualan berhasil dihapus'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
}
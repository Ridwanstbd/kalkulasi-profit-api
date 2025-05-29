<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'sku',
        'description',
        'hpp',
        'selling_price',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function costs()
    {
        return $this->hasMany(ProductCost::class);
    }

    public function priceSchemas()
    {
        return $this->hasMany(PriceScheme::class);
    }

    public function salesRecords ()
    {
        return $this->hasMany(SalesRecord::class);
    }
    
    public function getHppBreakdownAttribute()
    {
        $categories = [
            'direct_material' => 0,
            'direct_labor' => 0,
            'overhead' => 0,
            'packaging' => 0,
            'other' => 0,
        ];

        foreach ($this->costs as $cost) {
            $type = $cost->costComponent->component_type ?? 'other';
            $amount = $cost->calculated_amount ?? $cost->amount;

            if (array_key_exists($type, $categories)) {
                $categories[$type] += $amount;
            } else {
                $categories['other'] += $amount;
            }
        }

        $totalHpp = array_sum($categories);

        $breakdown = [];
        foreach ($categories as $type => $amount) {
            $breakdown[$type] = [
                'amount' => round($amount, 2),
                'percentage' => $totalHpp > 0 ? round(($amount / $totalHpp) * 100, 2) : 0
            ];
        }

        $breakdown['total'] = round($totalHpp, 2);

        return $breakdown;
    }

    public function getTotalHppAttribute(){
        return $this->hpp_breakdown['total'];
    }

    public function getProfitAttribute(){
        return $this->selling_price - $this->total_hpp;
    }

    public function getProfitMarginAttributes(){
        if ($this->selling_price == 0) {
            return 0;
        }
        return round(($this->profit / $this->selling_price) * 100, 2);
    }
    
}

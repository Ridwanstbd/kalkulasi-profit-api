<?php

namespace App\Observers;

use App\Models\PriceScheme;
use App\Models\Product;

class PriceSchemeObserver
{
    /**
     * Handle the PriceScheme "created" event.
     */
    public function created(PriceScheme $priceScheme): void
    {
        $this->updateProductSellingPrice($priceScheme);
    }

    /**
     * Handle the PriceScheme "updated" event.
     */
    public function updated(PriceScheme $priceScheme): void
    {
        $this->updateProductSellingPrice($priceScheme);
    }

    /**
     * Handle the PriceScheme "deleted" event.
     */
    public function deleted(PriceScheme $priceScheme): void
    {
        $this->updateProductSellingPrice($priceScheme);
    }

    /**
     * Update the product's selling price based on the highest level order schema
     */
    private function updateProductSellingPrice(PriceScheme $priceScheme): void
    {
        $productId = $priceScheme->product_id;
        
        $highestLevelSchema = PriceScheme::where('product_id', $productId)
            ->orderBy('level_order', 'desc')
            ->first();
        
        if ($highestLevelSchema) {
            Product::where('id', $productId)->update([
                'selling_price' => $highestLevelSchema->selling_price
            ]);
        } else {
            $product = Product::find($productId);
            if ($product) {
                $product->update([
                    'selling_price' => $product->hpp ?? null
                ]);
            }
        }
    }
}
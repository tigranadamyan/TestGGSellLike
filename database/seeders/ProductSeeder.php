<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /** Catalogue exactly as supplied in the assignment appendix. */
    public function run(): void
    {
        $products = [
            ['sku' => 'STEAM-TOPUP-500', 'name' => 'Пополнение Steam 500 ₽', 'type' => 'topup', 'price' => 500],
            ['sku' => 'STEAM-TOPUP-1000', 'name' => 'Пополнение Steam 1000 ₽', 'type' => 'topup', 'price' => 1000],
            ['sku' => 'STEAM-TOPUP-2500', 'name' => 'Пополнение Steam 2500 ₽', 'type' => 'topup', 'price' => 2500],
            ['sku' => 'KEY-CS2-PRIME', 'name' => 'CS2 Prime Status ключ', 'type' => 'key', 'price' => 1290],
            ['sku' => 'KEY-GTA5', 'name' => 'GTA V ключ активации', 'type' => 'key', 'price' => 1990],
            ['sku' => 'KEY-EFT', 'name' => 'Escape from Tarkov ключ', 'type' => 'key', 'price' => 3490],
            ['sku' => 'SUB-DISCORD-1M', 'name' => 'Discord Nitro 1 месяц', 'type' => 'subscription', 'price' => 399],
            ['sku' => 'SUB-YT-3M', 'name' => 'YouTube Premium 3 месяца', 'type' => 'subscription', 'price' => 1490],
            ['sku' => 'SUB-SPOTIFY-1M', 'name' => 'Spotify Premium 1 месяц', 'type' => 'subscription', 'price' => 299],
            ['sku' => 'GIFT-PSN-1000', 'name' => 'PlayStation Store карта 1000 ₽', 'type' => 'giftcard', 'price' => 1000],
            ['sku' => 'GIFT-XBOX-1500', 'name' => 'Xbox Gift Card 1500 ₽', 'type' => 'giftcard', 'price' => 1500],
            ['sku' => 'GIFT-ROBLOX-800', 'name' => 'Roblox 800 Robux', 'type' => 'giftcard', 'price' => 890],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['sku' => $product['sku']],
                $product + ['currency' => 'RUB']
            );
        }
    }
}

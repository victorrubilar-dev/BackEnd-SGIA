<?php

namespace App\Services;

use App\Models\Product;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeService
{
    /**
     * Generate a unique barcode string for a new product.
     */
    public function generateUniqueCode(string $prefix = 'SGIA'): string
    {
        do {
            $randomDigits = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
            $code = "{$prefix}-{$randomDigits}";
        } while (Product::where('barcode', $code)->exists());

        return $code;
    }

    /**
     * Generate SVG markup for a given barcode string.
     */
    public function generateSvg(string $code): string
    {
        $generator = new BarcodeGeneratorSVG();

        return $generator->getBarcode($code, $generator::TYPE_CODE_128);
    }

    /**
     * Generate HTML markup for a given barcode string.
     */
    public function generateHtml(string $code): string
    {
        $generator = new BarcodeGeneratorHTML();

        return $generator->getBarcode($code, $generator::TYPE_CODE_128);
    }

    /**
     * Generate PNG data URI if GD/Imagick exists, or SVG data URI as fallback.
     */
    public function generateBarcodeImageUri(string $code): string
    {
        if (extension_loaded('gd') || extension_loaded('imagick')) {
            $generator = new BarcodeGeneratorPNG();
            $barcodeData = $generator->getBarcode($code, $generator::TYPE_CODE_128);

            return 'data:image/png;base64,' . base64_encode($barcodeData);
        }

        $svg = $this->generateSvg($code);

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}

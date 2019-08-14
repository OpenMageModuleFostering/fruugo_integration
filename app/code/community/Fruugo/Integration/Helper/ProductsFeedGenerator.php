<?php
/**
* NOTICE OF LICENSE
*
* Magento extension which extracts a product feed from Magento, imports the feed into Fruugo and uses the Fruugo Order API to export all Fruugo orders into Magento.
*
* Copyright (C) 2015  Fruugo.com Ltd
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
* See the GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along with this program.
* If not, see <http://www.gnu.org/licenses/>.
*/

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

class Fruugo_Integration_ProductsFeedGenerator extends Mage_Core_Helper_Abstract
{
    public function generateProdcutsFeed($cached = false)
    {
        $cachedFile = Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/products.xml';
        if ($cached === true && file_exists($cachedFile)) {
            return $cachedFile;
        } else {
            Fruugo_Integration_Helper_Logger::log("Start exporting products data feed.");
            $productsXml;

            $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');
            if ($devMode == '1') {
                $productsXml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Products xmlns="http://schemas.fruugo.com/fruugoflat"></Products>');
            } else {
                $productsXml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Products></Products>');
            }

            $products = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('*') // select all attributes
                ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED) // only select enabled products
                ->addAttributeToFilter('type_id', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE); // only select simple products

            // Get store langauge map or use null if none
            $storelangsSettings = Mage::getStoreConfig('integration_options/products_options/language_store');
            $storelangsMapping = empty($storelangsSettings) ? null : unserialize($storelangsSettings);

            foreach ($products as $product) {
                $disabledOnFruugo = false;
                $productCountries = Mage::getModel('integration/countries')->load($product->getId(), 'product_id');
                if (isset($productCountries) && $productCountries->getFruugoCountries() == 'Disabled') {
                    $disabledOnFruugo = true;
                }

                $parentProduct = $this->_getParentProduct($product);
                $images = $this->_getProductImages($product, $parentProduct);

                if (!$disabledOnFruugo &&
                  $product->getSku() !== null &&
                  $product->getName() !== null &&
                  $product->getDescription() !== null &&
                  $product->getPrice() !== null &&
                  count($images) > 0) {
                    $productXml = $productsXml->addChild('Product');
                    $productXml = $this->_fillProductXml($productXml, $product, $storelangsMapping);
                }
            }

            Fruugo_Integration_Helper_Logger::log("Exporting products data feed finished.");
            return $productsXml;
        }
    }

    public function _fillProductXml($productXml, $product, $storelangsMapping)
    {
        // M: Mandatory R: Recommended O: Optional
        $parentProduct = $this->_getParentProduct($product);

        // ProductId *M
        if (isset($parentProduct)) {
            $productXml->addChild('ProductId', $parentProduct->getId());
        } else {
            $productXml->addChild('ProductId', $product->getId());
        }

        // SkuId *M
        $productXml->addChild('SkuId', htmlspecialchars($product->getSku()));

        // EAN *R
        if ($product->getEan() !== null) {
            $productXml->addChild('EAN', $product->getEan());
        }

        // ISBN *O
        if ($product->getIsbn() !== null) {
            $productXml->addChild('ISBN', $product->getIsbn());
        }

        // Brand *R
        if ($product->getBrand() !== null) {
            $productXml->addChild('Brand', htmlspecialchars($product->getBrand()));
        }

        // Manufacturer *O
        if ($product->getManufacturer() !== null && $product->getAttributeText('manufacturer')) {
            $productXml->addChild('Manufacturer', htmlspecialchars($product->getAttributeText('manufacturer')));
        }

        // Category *R
        $categoryEntity = $product->getCategoryCollection()
                ->addAttributeToSort('level', 'DESC')
                ->addAttributeToSort('position', 'DESC')
                ->addAttributeToFilter('is_active', '1')
                ->getFirstItem();

        $category = Mage::getModel('catalog/category')
            ->load($categoryEntity->getId());

        if ($category->getName() !== null) {
            $productXml->addChild('Category', htmlspecialchars($category->getName()));
        }

        // Imageurl1 *M
        // Imageurl2, Imageurl3, Imageurl4, Imageurl5 *R
        $images = $this->_getProductImages($product, $parentProduct);
        $imageIndex = 0;

        foreach ($images as $image) {
            if ($imageIndex >= 5) {
                break;
            }

            $imageUrl = $image->getUrl();
            if (strpos($imageUrl, 'placeholder') !== true) {
                $productXml->addChild('Imageurl'.($imageIndex + 1), $imageUrl);
                $imageIndex++;
            }
        }

        // StockStatus & StockQuantity *M
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);

        if ($stockItem === null || !$stockItem->getIsInStock()) {
            $productXml->addChild('StockStatus', 'OUTOFSTOCK');
        } else {
            $productXml->addChild('StockStatus', 'INSTOCK');
            $stocklevel = (int)$stockItem->getQty();
            $productXml->addChild('StockQuantity', $stocklevel);
        }

        // RestockDate *O

        // LeadTime *O

        // PackageWeight **O
        if ($product->getWeight() !== null) {
            $productXml->addChild('PackageWeight', round($product->getWeight(), 0));
        }

        // Description Node
        $stores = Mage::app()->getStores(false);

        $addedLanguages = array();
        foreach ($stores as $store) {
            // store 'fruugo' is for Fruugo orders
            if ($store->getCode() != 'fruugo') {
                $product = $product->setStoreId($store->getId())->load($product->getId());

                $localeCode = Mage::getStoreConfig('general/locale/code', $store->getId());
                $language = substr($localeCode, 0, strpos($localeCode, '_'));

                // Make sure no language is added more than once
                if (!in_array($language, $addedLanguages)) {
                    // Add description when no store selected for language OR the store is selected for the langauge in storelangsMapping array
                    if ($storelangsMapping == null || $storelangsMapping[$localeCode] == "" || $storelangsMapping[$localeCode] == $store->getCode()) {
                        // Language *R
                        $descriptionXml = $productXml->addChild('Description');
                        $descriptionXml->addChild('Language', $language);

                        $descriptionXml->addChild('Title', htmlspecialchars($product->getName()));

                        // description
                        $nestedDescriptionXml = $descriptionXml->addChild('Description');
                        $descriptionType = Mage::getStoreConfig('integration_options/products_options/descrption_type');

                        if ($descriptionType == 'long') {
                            $this->_addCData($nestedDescriptionXml, $product->getDescription());
                        } elseif ($descriptionType == 'short') {
                            $this->_addCData($nestedDescriptionXml, $product->getShortDescription());
                        } elseif ($descriptionType == 'merge_short_first') {
                            $this->_addCData($nestedDescriptionXml, $product->getShortDescription() . PHP_EOL . $product->getDescription());
                        } else {
                            $this->_addCData($nestedDescriptionXml, $product->getDescription() . PHP_EOL . $product->getShortDescription());
                        }

                        // AttributeColor *R
                        if ($product->getColor() !== null && $product->getAttributeText('color')) {
                            $descriptionXml->addChild('AttributeColor', $product->getAttributeText('color'));
                        }

                        // AttributeSize *R
                        if ($product->getShoeSize() !== null && $product->getAttributeText('shoe_size')) {
                            $descriptionXml->addChild('AttributeSize', $product->getAttributeText('shoe_size'));
                        } elseif ($product->getSize() !== null && $product->getAttributeText('size')) {
                            $descriptionXml->addChild('AttributeSize', $product->getAttributeText('size'));
                        }

                        // optional attributes: Arrtibute1 - Attribute10 *O
                        if ($product->getFit() !== null) {
                            $descriptionXml->addChild('Attribute1', $product->getFit());
                        }
                        if ($product->getLength() !== null) {
                            $descriptionXml->addChild('Attribute2', $product->getLength());
                        }
                        if ($product->getWidth() !== null) {
                            $descriptionXml->addChild('Attribute3', $product->getWidth());
                        }

                        array_push($addedLanguages, $language);
                    }
                }
            }
        }

        $addedCurrencies = array();
        foreach ($stores as $store) {
            if ($store->getCode() != 'fruugo') {
                $currencyCode = $store->getCurrentCurrencyCode();
                if (in_array($currencyCode, $addedCurrencies)) {
                    continue;
                } else {
                    array_push($addedCurrencies, $currencyCode);
                }
                $product->setStoreId($store->getId())->load($product->getId());
                // Price Node
                $priceXml = $productXml->addChild('Price');
                // Currency *R
                $priceXml->addChild('Currency', $currencyCode);
                // Country
                $existingProductCountries = Mage::getModel('integration/countries')->load($product->getId(), 'product_id');
                if (isset($existingProductCountries) && $existingProductCountries->getFruugoCountries() != null) {
                    $existingList = $existingProductCountries->getFruugoCountries();
                    $priceXml->addChild('Country', $existingList);
                }

                // Normal price.
                $taxHelper = Mage::helper('tax');
                $normalPriceExclTax = $taxHelper->getPrice($product, $product->getPrice(), false);
                $priceXml->addChild('NormalPriceWithoutVAT', number_format($normalPriceExclTax, 2, '.', ''));

                // VATRate.
                $taxCalculation = Mage::getModel('tax/calculation');
                $request = $taxCalculation->getRateRequest(null, null, null, $store);
                $taxClassId = $product->getTaxClassId();
                $percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));
                $priceXml->addChild('VATRate', number_format($percent, 2, '.', ''));

                // Discount price
                $finalPriceExclTax = $taxHelper->getPrice($product, $product->getFinalPrice(), false);
                $rulePriceExclTax = Mage::getModel('catalogrule/rule')->calcProductPriceRule($product, $normalPriceExclTax);
                $discountedPriceExclTax = null;

                if ($rulePriceExclTax == null || $finalPriceExclTax < $rulePriceExclTax) {
                    $discountedPriceExclTax = $finalPriceExclTax;
                } else {
                    $discountedPriceExclTax = $rulePriceExclTax;
                }
                if ($normalPriceExclTax > $discountedPriceExclTax) {
                    $priceXml->addChild('DiscountPriceWithoutVAT', number_format($discountedPriceExclTax, 2, '.', ''));
                    // DiscountPriceStartDate *O
                    if ($product->getSpecialFromDate()) {
                        $fromTime = strtotime($product->getSpecialFromDate());
                        $formatedFromTimeStr = date('Y-m-d',$fromTime);
                        $priceXml->addChild('DiscountPriceStartDate', $formatedFromTimeStr);
                    }

                    // DiscountPriceEndDate *O
                    if ($product->getSpecialToDate()) {
                        $toTime = strtotime($product->getSpecialToDate());
                        $fromatedToTimeStr = date('Y-m-d',$toTime);
                        $priceXml->addChild('DiscountPriceEndDate', $fromatedToTimeStr);
                    }
                }
            }
        }

        return $productXml;
    }

    private function _getProductImages($product, $parentProduct)
    {
        $productObj = $product->load($product->getId());
        $images = array();
        $skuImages = array_values($productObj->getMediaGalleryImages()->getItems());

        if (!empty($skuImages)) {
            foreach ($skuImages as $skuImage) {
                array_push($images, $skuImage);
            }
        }

        if (isset($parentProduct)) {
            $parentImages = array_values($parentProduct->getMediaGalleryImages()->getItems());
            if (!empty($parentImages)) {
                foreach ($parentImages as $parentImage) {
                    array_push($images, $parentImage);
                }
            }
        }

        return $images;
    }

    private function _getParentProduct($product)
    {
        $parentProduct = null;

        $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
        ->getParentIdsByChild($product->getId());

        if (isset($parentIds[0])) {
            $parentProduct = Mage::getModel('catalog/product')->load($parentIds[0]);
        }

        return $parentProduct;
    }

    private function _addCData($xml, $cdata_text)
    {
        $node = dom_import_simplexml($xml);
        $no   = $node->ownerDocument;
        $node->appendChild($no->createCDATASection(htmlspecialchars($cdata_text)));
        return $xml;
    }
}

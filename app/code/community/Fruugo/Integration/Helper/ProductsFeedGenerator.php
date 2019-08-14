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

            foreach ($products as $product) {
                $disabledOnFruugo = false;
                $productCountries = Mage::getModel('integration/countries')->load($product->getId(), 'product_id');
                if (isset($productCountries) && $productCountries->getFruugoCountries() == 'Disabled') {
                    $disabledOnFruugo = true;
                }
                $imageUrl = $product->getImageUrl();
                if (!$disabledOnFruugo &&
                  $product->getSku() !== null &&
                  $product->getName() !== null &&
                  $product->getDescription() !== null &&
                  $product->getPrice() !== null &&
                  !empty($imageUrl)) {
                    $productXml = $productsXml->addChild('Product');
                    $productXml = $this->_fillProductXml($productXml, $product);
                }
            }

            Fruugo_Integration_Helper_Logger::log("Exporting products data feed finished.");
            return $productsXml;
        }
    }

    public function _fillProductXml($productXml, $product)
    {
        // M: Mandatory R: Recommended O: Optional
        $parentProduct = null;

        $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
        ->getParentIdsByChild($product->getId());

        // ProductId *M
        if (isset($parentIds[0])) {
            $parentProduct = Mage::getModel('catalog/product')->load($parentIds[0]);
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
        $productXml->addChild('Imageurl1', htmlspecialchars(Mage::helper('catalog/image')->init($product, 'image')));

        // Imageurl2, Imageurl3, Imageurl4, Imageurl5 *R

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

        foreach ($stores as $store) {
            // store 'fruugo' is for Fruugo orders
            if ($store->getCode() != 'fruugo') {
                $product->setStoreId($store->getId())->load($product->getId());

                $localeCode = Mage::getStoreConfig('general/locale/code', $store->getId());
                $language = substr($localeCode, 0, strpos($localeCode, '_'));

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

                $taxCalculation = Mage::getModel('tax/calculation');
                $request = $taxCalculation->getRateRequest(null, null, null, $store);
                $normalPriceExclTax = $product->getFinalPrice();
                $priceXml->addChild('NormalPriceWithoutVAT', number_format($normalPriceExclTax, 2, '.', ''));

                $taxClassId = $product->getTaxClassId();
                $percent = $taxCalculation->getRate($request->setProductClassId($taxClassId));
                $priceXml->addChild('VATRate', number_format($percent, 2, '.', ''));

                // DiscountPriceStartDate *O
                // DiscountPriceEndDate *O
            }
        }

        return $productXml;
    }

    private function _addCData($xml, $cdata_text)
    {
        $node = dom_import_simplexml($xml);
        $no   = $node->ownerDocument;
        $node->appendChild($no->createCDATASection(htmlspecialchars($cdata_text)));
        return $xml;
    }
}

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
    // Should the script check resource usage while running
    protected $MONITOR_RESOURCES = true;

    // Maximum load average allowed in the last minute
    protected $MAX_RESOURCES = 0.5;

    // Time to sleep for if over load limit
    protected $SLEEP_TIME_SEC = 20;

    // The number of errors after which the script will abort, set to -1 to disable
    protected $MAX_ERRORS = 30;

    // The number of products to process per batch
    protected $PAGE_SIZE = 100;

    // Whether or not to track the last id processed to avoid a double process
    // if items are deleted in between selecting batches, incurs a small
    // performance cost.
    protected $TRACK_LAST_ID = true;
    protected $NUMBER_OF_REPORTS_TO_KEEP = 10;

    protected $loadCheckCount = 0;
    protected $loadCheckTotal = 0.0;
    protected $stores = null;
    protected $taxHelper = null;
    protected $taxCalculation = null;
    protected $devMode = null;
    protected $currentTimer = array();
    protected $categoryRule = null;
    protected $tmpProductsXmlPath = null;
    protected $productsXmlPath = null;
    protected $report = null;
    protected $currencyConverter = null;
    protected $storeBaseCurrencies = null;
    protected $shouldConvertCurrency = true;
    protected $tempProductObj = false;

    protected static $ALWAYS = Fruugo_Integration_Helper_Logger::ALWAYS;
    protected static $ERROR = Fruugo_Integration_Helper_Logger::ERROR;
    protected static $WARNING = Fruugo_Integration_Helper_Logger::WARNING;
    protected static $INFO = Fruugo_Integration_Helper_Logger::INFO;
    protected static $DEBUG = Fruugo_Integration_Helper_Logger::DEBUG;

    public function generateProdcutsFeed($cached = false)
    {
        $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');
        $this->devMode = ($devMode == '1') ? true : false;

        $this->productsXmlPath = Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/products.xml';
        if ($cached === true && file_exists($this->productsXmlPath)) {
            return $this->productsXmlPath;
        }

        // BEGIN LOCK CHECK
        // This makes sure that another copy of this script is not executing already
        $lockFile = Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/products.lock';
        if (!file_exists($lockFile)) {
            touch($lockFile);
        }

        $f = fopen($lockFile, 'w');
        if ($f === false) {
            $this->_writeLog('Did not start Fruugo products export because the script is already running.', self::$WARNING);
            die('FuugoMagentoProductsFeed: Cannot create lock file');
        }

        if (!flock($f, LOCK_EX | LOCK_NB)) {
            $this->_writeLog('Did not start Fruugo products export because the script is already running.', self::$WARNING);
            die('FuugoMagentoProductsFeed: Cannot create lock file');
        } else {
            $this->_writeLog('Beginning export of products feed...', self::$ALWAYS);
        }
        // END LOCK CHECK

        if ($this->devMode) {
            Fruugo_Integration_Helper_Logger::$LOG_LEVEL = self::$DEBUG;
            $this->MONITOR_RESOURCES = false;
        }

        try {
            $time = time();
            $this->_setupGlobalData();
            $this->report['start_time_utc'] = date("Y-m-d H:i:s", time());
            $productsXml;

            if ($this->devMode) {
                $productsXml = '<?xml version="1.0" encoding="UTF-8"?><Products xmlns="http://schemas.fruugo.com/fruugoflat">';
            } else {
                $productsXml = '<?xml version="1.0" encoding="UTF-8"?><Products>';
            }

            if (file_exists($this->tmpProductsXmlPath)) {
                unlink($this->tmpProductsXmlPath);
            }

            $this->_writeProductsXml($productsXml);

            // Get store langauge map or use null if none
            $storelangsSettings = Mage::getStoreConfig('integration_options/products_options/language_store');
            $storelangsMapping = empty($storelangsSettings) ? null : unserialize($storelangsSettings);

            $numOfProds = 0;
            $currentPage = 0;
            $numResults = $this->PAGE_SIZE;
            $numIterations = 0;
            $totalMemory = 0;
            $xmlBuffer = '';
            $errorsCount = 0;
            $totalProcessed = 0;
            $finalException = null; // This is a hack because old versions of PHP(<5.5) don't have finally
            $lastProcessedId = 0;

            do {
                $products = Mage::getModel('catalog/product')->getCollection();
                $products->addAttributeToSelect('*'); // select all attributes
                if ($this->TRACK_LAST_ID) {
                    $products->addAttributeToFilter('entity_id', array('gt' => $lastProcessedId)); // make sure we don't double process items due to deletions during the process
                }
                $products->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED); // only select enabled products
                $products->addAttributeToFilter('type_id', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
                $products->setOrder('entity_id', 'ASC');
                if ($this->TRACK_LAST_ID) {
                    $products->getSelect()->limit($this->PAGE_SIZE);
                } else {
                    $products->getSelect()->limit($this->PAGE_SIZE, $currentPage);
                }

                $this->_writeLog("Processing page " . $currentPage. ', max memory used ' . ((memory_get_peak_usage(true) /1024)/1024) . 'MB.', self::$DEBUG);

                $numResults = 0;
                foreach ($products as $product) {
                    $numResults++;
                    $totalProcessed++;
                    $lastProcessedId = $product->getId();

                    $disabledOnFruugo = false;
                    $productCountries = Mage::getModel('integration/countries')->load($product->getId(), 'product_id');

                    if (isset($productCountries) && $productCountries->getFruugoCountries() == 'Disabled') {
                        $disabledOnFruugo = true;
                        continue;
                    }

                    unset($productCountries);
                    if ($this->_shouldInclude($product)) {
                        try {
                            $productXml = $this->_fillProductXml($product, $storelangsMapping);

                            if ($productXml) {
                                $xmlBuffer .= $productXml;
                                $numOfProds++;
                            }
                        } catch (Exception $ex) {
                            $errorsCount += 1;
                            Mage::logException($ex);

                            if (count($this->report['errors']) < 10) {
                                $errorLog = array(
                                    'product_id' => $product->getId(),
                                    'stack_trace' => $ex->getTraceAsString(),
                                    'message' => $ex->getMessage(),
                                );
                                array_push($this->report['errors'], $errorLog);
                            }

                            if ($this->MAX_ERRORS != -1 && $errorsCount > $this->MAX_ERRORS) {
                                $this->_writeLog('Product export aborting due to hitting maximum error threshold of ' . $this->MAX_ERRORS, self::$ERROR);
                                throw new Exception('Fruugo product export aborting due to hitting maximum error threshold of ' . $this->MAX_ERRORS, 500, $ex);
                            }
                        }

                    }
                }

                $this->_writeProductsXml($xmlBuffer);
                $numIterations += 1;
                $xmlBuffer = '';
                $totalMemory += ((memory_get_usage(false) /1024)/1024);

                $currentPage = $currentPage + $this->PAGE_SIZE;

                //clear collection and free memory
                $products->resetData();
                $products->clear();
                unset($products);

                $this->checkServerLoad();

            } while ($numResults >= $this->PAGE_SIZE);

            // write file end and rename
            $this->_writeProductsXml('</Products>');
            rename($this->tmpProductsXmlPath, $this->productsXmlPath);

            $this->report['xml_file_size_mb'] = ((filesize($this->productsXmlPath) /1024)/1024);
        } catch (Exception $ex) {
            $this->report['status'] = 'failed';
            $numOfProds = 0;
            $finalException = $ex;
        }

        // This should probably go in a finally block but older
        // version of PHP don't support it,
        $this->report['total_exported'] = $numOfProds;
        $this->report['processing_time_sec'] = (time() - $time);
        $this->report['end_time_utc'] = date("Y-m-d H:i:s", time());
        $this->report['avg_ram_usage_mb'] = $totalMemory / $numIterations;
        $this->report['max_ram_usage_mb'] = ((memory_get_peak_usage(true) /1024)/1024);
        $this->report['total_processed'] = $totalProcessed;
        $this->report['error_count'] = $errorsCount;


        if (!$this->report['status']) {
            if ($errorsCount == 0) {
                $this->report['status'] = 'success';
            } else {
                $this->report['status'] = 'success_with_errors';
            }
        }

        $this->_writeReport($this->report);
        $this->_writeLog(Fruugo_Integration_Helper_Logger::getFormattedReport($this->report), self::$INFO);

        if ($finalException !== null) {
            $this->_writeLog('The Fruugo product export has stopped due to an error: ' . $ex->getMessage(), self::$ERROR);
            Mage::logException($ex);
            throw $ex;
        }

        return $this->productsXmlPath;
    }

    protected function _fillProductXml($product, $storelangsMapping)
    {
        $parentProduct = $this->_getParentProduct($product);
        $images = $this->_getProductImages($product, $parentProduct);

        if (count($images) == 0) {
            return false;
        }
        // M: Mandatory R: Recommended O: Optional
        $productXml = new SimpleXMLElement('<Product></Product>');

        // ProductId *M
        if (isset($parentProduct)) {
            $productXml->addChild('ProductId', $parentProduct->getId());
        } else {
            $productXml->addChild('ProductId', $product->getId());
        }

        // SkuId *M
        if ($product->getSku()) {
            $productXml->addChild('SkuId', htmlspecialchars($product->getSku()));
        } else {
            $skuId = isset($parentProduct)
                ? "dbid{$parentProduct->getId()}_attr{$product->getId()}"
                : "dbid{$product->getId()}";

            $productXml->addChild('SkuId', $skuId);
        }

        $mappedAttributes = $this->_getMappedProductAttributes(array(
            'EAN'           => $this->eanAttributes,
            'ISBN'          => $this->isbnAttributes,
            'Brand'         => $this->brandAttributes,
            'Manufacturer'  => $this->manufacturerAttributes
        ), $product);

        // Get parent ones if variant's Brand & Manufacturer are empty
        if (isset($parentProduct)) {
            if (!array_key_exists('Brand', $mappedAttributes)
                || empty($mappedAttributes['Brand'])
                || !array_key_exists('brand', $mappedAttributes['Brand'])
                || $mappedAttributes['Brand']['brand'] == false) {
                    $parentMappedBrandAttributes = $this->_getMappedProductAttributes(array(
                        'Brand' => $this->brandAttributes
                    ), $parentProduct);

                    if (array_key_exists('Brand', $parentMappedBrandAttributes)) {
                        $mappedAttributes['Brand'] = $parentMappedBrandAttributes['Brand'];
                    }
            }

            if (!array_key_exists('Manufacturer', $mappedAttributes)
                || empty($mappedAttributes['Manufacturer'])
                || !array_key_exists('manufacturer', $mappedAttributes['Manufacturer'])
                || $mappedAttributes['Manufacturer']['manufacturer'] == false) {
                    $parentMappedManufacturerAttributes = $this->_getMappedProductAttributes(array(
                        'Manufacturer' => $this->manufacturerAttributes
                    ), $parentProduct);

                    if (array_key_exists('Manufacturer', $parentMappedManufacturerAttributes)) {
                        $mappedAttributes['Manufacturer'] = $parentMappedManufacturerAttributes['Manufacturer'];
                    }
            }
        }

        foreach ($mappedAttributes as $attributeName => $attributes) {
            $attributeText = $this->_getAttributesText(
                0, // Language
                array_keys($attributes)[0],
                array_values($attributes)[0]
            );

            if (!empty($attributeText)) {
                $productXml->addChild(
                    $attributeName,
                    htmlspecialchars($attributeText)
                );
            }
        }

        // Category *R
        $categoryEntity = $product->getCategoryCollection()
                ->addAttributeToSelect('name')
                ->addAttributeToSort('level', 'DESC')
                ->addAttributeToSort('position', 'DESC')
                ->addAttributeToFilter('is_active', '1')
                ->getFirstItem();

        $categories = array();

        // Get parent category for each category until the root catalog
        while (isset($categoryEntity) && $categoryEntity->getName() !== null && $categoryEntity->getName() !== 'Root Catalog') {
            array_push($categories, $categoryEntity->getName());
            $categoryEntity = $categoryEntity->getParentCategory();
        }

        if (!empty($categories)) {
            // Top level category comes first
            $productXml->addChild('Category', htmlspecialchars(implode('>', array_reverse($categories))));
        }

        // Imageurl1 *M
        // Imageurl2, Imageurl3, Imageurl4, Imageurl5 *R
        $imageIndex = 0;

        foreach ($images as $imageUrl) {
            if ($imageIndex >= 5) {
                break;
            }

            $productXml->addChild('Imageurl'.($imageIndex + 1), $imageUrl);
            $imageIndex++;
        }

        // StockStatus & StockQuantity *M
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);

        if ($stockItem === null || !$stockItem->getIsInStock()) {
            $productXml->addChild('StockStatus', 'OUTOFSTOCK');
            $productXml->addChild('StockQuantity', 0);
        } else {
            $stocklevel = (int)$stockItem->getQty();

            if ($stocklevel <= 0) {
                $productXml->addChild('StockStatus', 'OUTOFSTOCK');
                $productXml->addChild('StockQuantity', 0);
            } else {
                $productXml->addChild('StockStatus', 'INSTOCK');
                $productXml->addChild('StockQuantity', $stocklevel);
            }
        }

        // RestockDate *O

        // LeadTime *O

        // PackageWeight **O
        if ($product->getWeight() !== null) {
            $productXml->addChild('PackageWeight', round($product->getWeight(), 0));
        }

        // Description Node
        $addedLanguages = array();
        $descriptionNodeCount = 0;
        foreach ($this->stores as $store) {
            // store 'fruugo' is for Fruugo orders
            if ($store->getCode() == 'fruugo') {
                continue;
            }

            $localeCode = Mage::getStoreConfig('general/locale/code', $store->getId());
            $language = substr($localeCode, 0, strpos($localeCode, '_'));

            // Make sure no language is added more than once
            if (in_array($language, $addedLanguages)) {
                continue;
            }

            // Make sure product has description based on store config
            $descriptionType = Mage::getStoreConfig('integration_options/products_options/descrption_type', $store);

            // check product description not null for each store
            $descriptionsArray = array();

            foreach ($this->descriptionAttributes['AttributeDescription'] as $attributeName) {
                $descriptionValue = null;

                if ($attributeName && $product->getData($attributeName)) {
                    $descriptionValue = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($product->getId(), $attributeName, $store->getId());
                }

                // use parent description if empty
                if ($descriptionValue == null && isset($parentProduct)) {
                    $descriptionValue = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($parentProduct->getId(), $attributeName, $store->getId());
                }

                if (!empty($descriptionValue)) {
                    $descriptionsArray['description'] = $descriptionValue;
                    break;
                }
            }

            foreach ($this->descriptionAttributes['AttributeShortDescription'] as $attributeName) {
                $shortDescriptionValue = null;

                if ($attributeName && $product->getData($attributeName)) {
                    $shortDescriptionValue = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($product->getId(), $attributeName, $store->getId());
                }

                // use parent short description if empty
                if ($shortDescriptionValue == null && isset($parentProduct)) {
                    $shortDescriptionValue = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($parentProduct->getId(), $attributeName, $store->getId());
                }

                if (!empty($shortDescriptionValue)) {
                    $descriptionsArray['short_description'] = $shortDescriptionValue;
                    break;
                }
            }

            // Check product description not null for each store
            if ($descriptionType == 'long' && empty($descriptionsArray['description'])) {
                continue;
            } elseif ($descriptionType == 'short' && empty($descriptionsArray['short_description'])) {
                continue;
            }

            // Add description when no store selected for language OR the store is selected for the langauge in storelangsMapping array
            if ($storelangsMapping == null || $storelangsMapping[$localeCode] == "" || $storelangsMapping[$localeCode] == $store->getCode()) {
                // Language *R
                $descriptionXml = $productXml->addChild('Description');
                $descriptionXml->addChild('Language', $language);

                // title
                if (isset($parentProduct)) {
                    $name = Mage::getResourceModel('catalog/product')->getAttributeRawValue($parentProduct->getId(), 'name', $store->getId());
                } else {
                    $name = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'name', $store->getId());
                }
                $descriptionXml->addChild('Title', htmlspecialchars($name));

                // description
                $nestedDescriptionXml = $descriptionXml->addChild('Description');

                if ($descriptionType == 'long') {
                    $this->_addCData($nestedDescriptionXml, $descriptionsArray['description']);
                } elseif ($descriptionType == 'short') {
                    $this->_addCData($nestedDescriptionXml, $descriptionsArray['short_description']);
                } elseif ($descriptionType == 'merge_short_first') {
                    $this->_addCData($nestedDescriptionXml, $descriptionsArray['short_description'] . PHP_EOL . $descriptionsArray['description']);
                } else {
                    $this->_addCData($nestedDescriptionXml, $descriptionsArray['description'] . PHP_EOL . $descriptionsArray['short_description']);
                }

                $this->_addAttributesXml($descriptionXml, $product, $store, $language);

                array_push($addedLanguages, $language);
                $descriptionNodeCount++;
            }
        }

        // Ignore if no descriptions added
        if ($descriptionNodeCount == 0) {
            return false;
        }

        $existingProductCountries = Mage::getModel('integration/countries')->load($product->getId(), 'product_id');

        if (isset($existingProductCountries)) {
            $existingList = $existingProductCountries->getFruugoCountries();
        }

        $addedCurrencies = array();
        foreach ($this->stores as $store) {
            if ($store->getCode() == 'fruugo') {
                continue;
            }

            // Add price for store in language mapping.
            $localeCode = Mage::getStoreConfig('general/locale/code', $store->getId());
            if ($storelangsMapping == null || $storelangsMapping[$localeCode] == $store->getCode()) {
                $currencyCode = $store->getCurrentCurrencyCode();
                $baseCurrencyCode = $this->storeBaseCurrencies[$store->getId()];

                // Skip if currency has been added.
                if (in_array($currencyCode, $addedCurrencies)) {
                    continue;
                } else {
                    array_push($addedCurrencies, $currencyCode);
                }

                // Price Node
                $priceXml = $productXml->addChild('Price');
                // Currency *R
                $priceXml->addChild('Currency', $currencyCode);
                // Country
                if (isset($existingList)) {
                    $priceXml->addChild('Country', $existingList);
                }

                // Normal price.
                $normalPriceExclTax = $this->taxHelper->getPrice($product, $product->getPrice(), false, null, null, null, $store);
                $normalPriceExclTaxConv = $this->_convertCurrency($normalPriceExclTax, $baseCurrencyCode, $currencyCode);
                $priceXml->addChild('NormalPriceWithoutVAT', number_format($normalPriceExclTaxConv, 2, '.', ''));

                // Discount price
                $discountedPriceExclTax = null;
                $finalPrice = $product->getFinalPrice();
                $finalPriceExclTax = $this->taxHelper->getPrice($product, $finalPrice, false, null, null, null, $store);
                $rulePriceExclTax = $this->categoryRule->clearInstance()->calcProductPriceRule($product, $normalPriceExclTax);

                if ($rulePriceExclTax == null || $finalPriceExclTax < $rulePriceExclTax) {
                    $discountedPriceExclTax = $finalPriceExclTax;
                } else {
                    $discountedPriceExclTax = $rulePriceExclTax;
                }

                 // VATRate.
                $request = $this->taxCalculation->clearInstance()->getRateRequest(null, null, null, $store);
                $taxClassId = $product->getTaxClassId();
                $percent = $this->taxCalculation->clearInstance()->getRate($request->setProductClassId($taxClassId));

                if ($discountedPriceExclTax && $normalPriceExclTax > $discountedPriceExclTax) {
                    $discountedPriceExclTaxConv = $this->_convertCurrency($discountedPriceExclTax, $baseCurrencyCode, $currencyCode);
                    $priceXml->addChild('DiscountPriceWithoutVAT', number_format($discountedPriceExclTaxConv, 2, '.', ''));

                    $priceXml->addChild('VATRate', number_format($percent, 2, '.', ''));
                    // DiscountPriceStartDate *O
                    if ($product->getSpecialFromDate()) {
                        $fromTime = strtotime($product->getSpecialFromDate());
                        $formatedFromTimeStr = date('Y-m-d', $fromTime);
                        $priceXml->addChild('DiscountPriceStartDate', $formatedFromTimeStr);
                    }

                    // DiscountPriceEndDate *O
                    if ($product->getSpecialToDate()) {
                        $toTime = strtotime($product->getSpecialToDate());
                        $fromatedToTimeStr = date('Y-m-d', $toTime);
                        $priceXml->addChild('DiscountPriceEndDate', $fromatedToTimeStr);
                    }
                } else {
                    // This looks strange because the VATRate is also added above but the sequence
                    // defined in the XSD requires it appears after DiscountPriceWithoutVAT if it is present
                    $priceXml->addChild('VATRate', number_format($percent, 2, '.', ''));
                }

                // Only need one price tag for each product now.
                break;
            }
        }

        $productStr = $productXml->asXML();

        if ($productStr) {
            $productStr = str_replace('<?xml version="1.0"?>', '', trim($productStr));
        }

        foreach ($productXml->children as $child) {
            unset($child);
        }
        unset($productXml);

        return $productStr;
    }

    protected function _getMappedProductAttributes($attributes, $product, $store = false)
    {
        // Check that at least one attribute is mapped
        if (!$attributes = array_filter($attributes)) {
            return array();
        }

        // Create a flat array of attribute names, and remove duplicates, and
        // find the product's attribute values for each
        $iterableAttributes = array_unique(call_user_func_array('array_merge', $attributes));

        $foundAttributes = $this->_getAttributes(
            $product,
            $iterableAttributes,
            $store ? $store->getId() : 0
        );

        // Map the attributes found for the product with the configured
        // configured Fruugo attributes
        $mappedAttributes = array();

        foreach ($attributes as $key => $attributeNames) {
            if (!$attributeNames) {
                continue;
            }

            foreach ($attributeNames as $attributeName) {
                // Skip if the attribute isn't defined for the product
                if (!isset($foundAttributes[$attributeName])) {
                    continue;
                }

                $mappedAttributes[$key][$attributeName] = $foundAttributes[$attributeName];
            }
        }

        return $mappedAttributes;
    }

    protected function _addAttributesXml($descriptionXml, $product, $store, $language)
    {
        $mappedAttributes = $this->_getMappedProductAttributes(
            $this->attributes,
            $product,
            $store
        );

        foreach ($mappedAttributes as $attributeName => $attributes) {
            $attributeText = $this->_getAttributesText(
                $language,
                array_keys($attributes)[0],
                array_values($attributes)[0],
                $store->getId()
            );

            if (!empty($attributeText)) {
                $descriptionXml->addChild(
                    $attributeName,
                    htmlspecialchars($attributeText)
                );
            }
        }
    }

    protected function _convertCurrency($price, $baseCurrencyCode, $currencyCode)
    {
        if ($this->shouldConvertCurrency && $baseCurrencyCode != $currencyCode) {
            return $this->currencyConverter->currencyConvert(
                $price,
                $baseCurrencyCode,
                $currencyCode
            );
        }

        return $price;
    }

    // This caches the options label translation for selectable product attributes.
    // They are cached by attribute name, language and then value.
    protected function _getAttributesText($language, $attributeName, $optionId, $storeId = 0)
    {
        // These are the asset keys that are cached, you should only cache attributes
        // that have selectable items
        if (!isset($this->attributeMap)) {
            $this->attributeMap = array(
                'color' => array(),
                'shoe_size' => array(),
                'size' => array(),
                'fit' => array(),
            );
        }

        // Creates attribute array if not present
        if (!isset($this->attributeMap[$attributeName])) {
            $this->attributeMap[$attributeName] = array();
        }

        // Creates language array if not present
        if (!isset($this->attributeMap[$attributeName][$language])) {
            $this->attributeMap[$attributeName][$language] = array();
        }

        if (!isset($this->attributeMap[$attributeName][$language][$optionId])) {
            // Set the option value to null if it's not yet cached
            $this->attributeMap[$attributeName][$language][$optionId] = null;
        } else {
            // Return the option value if it's already cached
            return $this->attributeMap[$attributeName][$language][$optionId];
        }

        // Find the attribute id for given attribute name
        $attributeId = Mage::getResourceModel('eav/entity_attribute')
            ->getIdByCode('catalog_product', $attributeName);

        // Find all availble attribute options for the given attribute and store id
        $collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
            ->setPositionOrder('asc')
            ->setAttributeFilter($attributeId)
            ->setStoreFilter($storeId)
            ->load()
            ->toOptionArray();

        // Cache attribute values
        $found = false;
        foreach ($collection as $option) {
            if ($option['value'] == $optionId) {
                $found = true;
            }

            $this->attributeMap[$attributeName][$language][$option['value']] = $option['label'];
        }

        // Return option id if option value isn't found
        if (!$found) {
            return $optionId;
        }

        // Return the option value
        return $this->attributeMap[$attributeName][$language][$optionId];
    }

    protected function _getAttributes($product, $attributes, $storeId = 0)
    {
        $attributeValues = [];

        // Looping through $attributes arary rather than passing the it directly to getAttributeRawValue
        // Reason is: getAttributeRawValue returns array if $attributes has more than 1 attribute, but it returns string
        // if there is only on attribute in $attributes.
        // This make sure we always got an array
        foreach ($attributes as $attribute) {
            $attributeValue = Mage::getResourceModel('catalog/product')
                ->getAttributeRawValue($product->getId(), $attribute, $storeId);

            $attributeValues[$attribute] = $attributeValue;
        }

        // This is a workaround for a bug in getAttributeRawValue which returns
        // the wrong values for product attributes that have been modified in
        // another site so to save db hits we try and get them and if they
        // differ from the main product we are processing we then load them
        // individually
        foreach ($attributes as $name) {
            if (isset($attributeValues[$name])
                && ($product->getData($name) != $attributeValues[$name])) {
                $attributeValues[$name] = Mage::getResourceModel('catalog/product')
                    ->getAttributeRawValue($product->getId(), $name, $storeId);
            }
        }

        return $attributeValues;
    }

    protected function _getProductImages($product, $parentProduct)
    {
        if (!isset($this->tempProductObj) || $this->tempProductObj === false) {
             $this->tempProductObj = Mage::getModel('catalog/product');
        }
        $images = array();

        // This is a workaround to avoid the memory leaks that seem to occur when fully
        // loading an image gallery on a product model.
        $productObj = $this->tempProductObj->clearInstance()->setId($product->getId());
        $attributes = $productObj->getTypeInstance(true)->getSetAttributes($productObj);
        $media_gallery = $attributes['media_gallery'];
        $backend = $media_gallery->getBackend();
        $backend->afterLoad($productObj);

        // Add base image
        $baseImage =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'image', 0);
        $productMediaConfig = Mage::getSingleton('catalog/product_media_config');
        if (!empty($baseImage) && $baseImage !== 'no_selection') {
            $baseImageUrl = $productMediaConfig->getMediaUrl($baseImage);
            array_push($images, $baseImageUrl);
        }

        // Add gallery
        $galleryImages = $productObj->getMediaGalleryImages();
        $skuImages = array_values($galleryImages->getItems());
        if (!empty($skuImages)) {
            foreach ($skuImages as $skuImage) {
                $imageUrl = $skuImage->getUrl();
                if (strpos($imageUrl, 'placeholder/image') !== true) {
                    array_push($images, $imageUrl);
                }
            }
        }

        if (isset($parentProduct)) {
            // Add parent base image
            $attributes = $parentProduct->getTypeInstance(true)->getSetAttributes($parentProduct);
            $media_gallery = $attributes['media_gallery'];
            $backend = $media_gallery->getBackend();
            $backend->afterLoad($parentProduct);

            $parentBaseImage =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($parentProduct->getId(), 'image', 0);
            if (!empty($parentBaseImage) && $parentBaseImage !== 'no_selection') {
                $baseImageUrl = $productMediaConfig->getMediaUrl($parentBaseImage);
                array_push($images, $baseImageUrl);
            }

            // Add parent gallery
            $parentImages = array_values($parentProduct->getMediaGalleryImages()->getItems());
            if (!empty($parentImages)) {
                foreach ($parentImages as $parentImage) {
                    $imageUrl = $parentImage->getUrl();
                    if (strpos($imageUrl, 'placeholder/image') !== true) {
                        array_push($images, $imageUrl);
                    }
                }
            }
        }

        $productObj->clearInstance();
        unset($productObj);

        // If base image is not 'disabled' there will be duplicates
        $unique = array_keys(array_flip($images)); // array_unique is supposed to be slower
        return $unique;
    }

    protected function _shouldInclude($product)
    {
        if ($product->getName() === null || $product->getPrice() === null) {
            return false;
        }

        // Check if any mapped product desscription attribute is set
        $hasDescription = null;
        foreach (($this->descriptionAttributes['AttributeDescription'] ?: []) as $attributeName) {
            if (!$product->getData($attributeName)) {
                $hasDescription = false;
            } else {
                $hasDescription = true;
            }
        }

        $parentProduct = null;
        // If no description, check parent
        if (!$hasDescription) {
            $parentProduct = $this->_getParentProduct($product);

            if (isset($parentProduct)) {
                foreach (($this->descriptionAttributes['AttributeDescription'] ?: []) as $attributeName) {
                    $description = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($parentProduct->getId(), $attributeName, 0);

                    if (!empty($description)) {
                        $hasDescription = true;
                    } else {
                        $hasDescription = false;
                    }
                }
            }
        }

        // Check if any mapped product short desscription attribute is set
        $hasShortDescription = null;
        foreach (($this->descriptionAttributes['AttributeShortDescription'] ?: []) as $attributeName) {
            if (!$product->getData($attributeName)) {
                $hasShortDescription = false;
            } else {
                $hasShortDescription = true;
            }
        }

        // If no short description, check parent
        if (!$hasShortDescription) {
            $parentProduct = $parentProduct == null ? $this->_getParentProduct($product) : $parentProduct;

            if (isset($parentProduct)) {
                foreach (($this->descriptionAttributes['AttributeShortDescription'] ?: []) as $attributeName) {
                    $shortDescription = Mage::getResourceModel('catalog/product')
                        ->getAttributeRawValue($parentProduct->getId(), $attributeName, 0);

                    if (!empty($shortDescription)) {
                        $hasShortDescription = true;
                    } else {
                        $hasShortDescription = false;
                    }
                }
            }
        }

        return ($hasDescription || $hasShortDescription);
    }

    protected function _getParentProduct($product)
    {
        $parentProduct = null;

        $parentIds = Mage::getResourceSingleton('catalog/product_type_configurable')
            ->getParentIdsByChild($product->getId());

        if (isset($parentIds[0])) {
            $parentProduct = Mage::getSingleton('catalog/product')->clearInstance()->setId($parentIds[0]);
            return $parentProduct;
        }

        return null;
    }

    protected function _addCData($xml, $cdata_text)
    {
        $node = dom_import_simplexml($xml);
        $no   = $node->ownerDocument;
        $node->appendChild($no->createCDATASection(htmlspecialchars($cdata_text, ENT_COMPAT | ENT_HTML401 | ENT_DISALLOWED)));
        return $xml;
    }

    protected function _writeProductsXml($xmlStr)
    {
        file_put_contents($this->tmpProductsXmlPath, $xmlStr, FILE_APPEND | LOCK_EX);
    }

    protected function _writeReport($report)
    {
        $reportsToWrite = array();

        if (file_exists($this->reportPath)) {
            $existingReportsArray = array();
            $existingReport = json_decode(file_get_contents($this->reportPath));

            if (!is_array($existingReport)) {
                // If a report object has already been generated before, add it to a new array
                array_push($existingReportsArray, $existingReport);
            } else {
                // Otherwise let the new array = existing reports array
                $existingReportsArray = $existingReport;
            }

            $numberOfExistingReports = count($existingReportsArray);

            if ($numberOfExistingReports == $this->NUMBER_OF_REPORTS_TO_KEEP) {
                // Remove first report in array
                array_shift($existingReportsArray);
            } else if ($numberOfExistingReports > $this->NUMBER_OF_REPORTS_TO_KEEP) {
                // If eixsting reports are more than number to keep (most likely NUMBER_OF_REPORTS_TO_KEEP is
                // changed to a smaller value), remove the difference and add 1 in order to add one more report
                $numOfReportsToRemoveBackwards = -$this->NUMBER_OF_REPORTS_TO_KEEP + 1;
                // array_splice: If length is given and is negative then the sequence will stop that many elements from the end of the array
                if ($numOfReportsToRemoveBackwards == 0) {
                    // Remove all
                    array_splice($existingReportsArray, 0);
                } else {
                    array_splice($existingReportsArray, 0, $numOfReportsToRemoveBackwards);
                }
            }

            // Add new report to array
            array_push($existingReportsArray, $report);
            $reportsToWrite = $existingReportsArray;
        } else {
            array_push($reportsToWrite, $report);
        }

        file_put_contents($this->reportPath, json_encode($reportsToWrite));
    }

    protected function _writeLog($message, $level = Fruugo_Integration_Helper_Logger::DEBUG)
    {
        Fruugo_Integration_Helper_Logger::log($message, $level);
    }

    protected function _setupGlobalData()
    {
         // Cache the stores list to avoid frequent lookups
        $this->stores = array();
        $this->storeBaseCurrencies = array();
        foreach (Mage::app()->getStores(true) as $store) {
            array_push($this->stores, $store);
            $this->storeBaseCurrencies[$store->getId()] = $store->getBaseCurrencyCode();
        }

        $this->PAGE_SIZE = Mage::getStoreConfig('integration_options/products_options/export_page_size');
        $this->MAX_RESOURCES = Mage::getStoreConfig('integration_options/products_feed_advanced_options/max_resources_load');
        $this->SLEEP_TIME_SEC = Mage::getStoreConfig('integration_options/products_feed_advanced_options/sleep_time_sec');
        $this->MAX_ERRORS = Mage::getStoreConfig('integration_options/products_feed_advanced_options/max_errors');
        $this->NUMBER_OF_REPORTS_TO_KEEP = Mage::getStoreConfig('integration_options/products_feed_advanced_options/performance_reports_to_keep');

        $this->taxHelper = Mage::helper('tax');
        $this->currencyConverter = Mage::helper('directory');
        $this->taxCalculation = Mage::getSingleton('tax/calculation');
        $this->categoryRule = Mage::getSingleton('catalogrule/rule');
        $this->tmpProductsXmlPath =  Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/tmp_products.xml';
        $this->reportPath = Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/report.json';
        $this->report = array(
            'processing_time_sec' => 0,
            'start_time_utc' => 0,
            'end_time_utc' => 0,
            'total_exported' => 0,
            'total_processed' => 0,
            'max_ram_usage_mb' => 0,
            'avg_ram_usage_mb' => 0,
            'error_count' => 0,
            'errors' => array(),
            'status' => '',
            'avg_load' => 0,
            'load_above_threshold_count' => 0,
            'time_paused_sec' => 0,
            'xml_file_size_mb' => 0,
        );

        $this->_setupAttributeMappings();
    }

    protected function _setupAttributeMappings()
    {
        // Check that attributes are configured
        if (Mage::getStoreConfig('integration_options/products_options/attribute_description') === null
            && Mage::getStoreConfig('integration_options/products_options/attribute_short_description') === null) {
            $this->_writeLog(
                'Did not start Fruugo products export because attribute mappings have not been configured.',
                self::$WARNING
            );

            die('FuugoMagentoProductsFeed: Attribute mappings not configured');
        }

        // Create an array from all configuration values
        $this->attributes = array(
            'AttributeColor' => $this->_getAttributeConfig('attribute_color'),
            'AttributeSize'  => $this->_getAttributeConfig('attribute_size'),
            'Attribute1'     => $this->_getAttributeConfig('attribute_1'),
            'Attribute2'     => $this->_getAttributeConfig('attribute_2'),
            'Attribute3'     => $this->_getAttributeConfig('attribute_3'),
            'Attribute4'     => $this->_getAttributeConfig('attribute_4'),
            'Attribute5'     => $this->_getAttributeConfig('attribute_5'),
            'Attribute6'     => $this->_getAttributeConfig('attribute_6'),
            'Attribute7'     => $this->_getAttributeConfig('attribute_7'),
            'Attribute8'     => $this->_getAttributeConfig('attribute_8'),
            'Attribute9'     => $this->_getAttributeConfig('attribute_9'),
            'Attribute10'    => $this->_getAttributeConfig('attribute_10')
        );

        $this->descriptionAttributes = array(
            'AttributeDescription'      => $this->_getAttributeConfig('attribute_description'),
            'AttributeShortDescription' => $this->_getAttributeConfig('attribute_short_description'),
        );
        $this->brandAttributes = $this->_getAttributeConfig('brand');
        $this->manufacturerAttributes = $this->_getAttributeConfig('manufacturer');
        $this->eanAttributes = $this->_getAttributeConfig('ean');
        $this->isbnAttributes = $this->_getAttributeConfig('isbn');
    }

    protected function _getAttributeConfig($handle)
    {
        // Configuration path prefix
        $p = 'integration_options/products_options';

        // Find the configuration value and deserealize into an array
        $attributes = unserialize(Mage::getStoreConfig("{$p}/{$handle}"));

        // Sort the values by priority
        if (!$attributes) {
            return;
        }

        usort($attributes, function ($a, $b) {
            return (int)$a['priority'] - (int)$b['priority'];
        });

        return array_filter(array_map(function ($x) {
            return $x['attribute'];
        }, $attributes));
    }

    // This monitors system resources and pauses execution if the utilisation is
    // above the configured threshold.
    // Recommended for systems with large numbers of products
    // Note: this feature is not available on windows servers.
    protected function checkServerLoad()
    {
        if (stristr(PHP_OS, 'win')) {
            return;
        }
        $systemLoad = sys_getloadavg();
        $this->loadCheckTotal += $systemLoad[0];
        $this->loadCheckCount++;

        $this->report['avg_load'] = $this->loadCheckTotal / $this->loadCheckCount;

        if ($this->devMode) {
            $this->_writeLog('Server load is ' . $systemLoad[0], self::$DEBUG);
        }

        if (!$this->MONITOR_RESOURCES) {
            return;
        }

        $systemLoad = sys_getloadavg();
        if ($systemLoad[0] > $this->MAX_RESOURCES) {
            $this->_writeLog(
                'High server load detected.  Usage of ' . $systemLoad[0] .
                ' is greater than configured maximum of ' . $this->MAX_RESOURCES .
                '.  The product export job will now pause for ' . $this->SLEEP_TIME_SEC . ' seconds.',
                self::$DEBUG
            );
            $this->report['load_above_threshold_count'] += 1;

            sleep($this->SLEEP_TIME_SEC);

            $this->report['time_paused_sec'] += $this->SLEEP_TIME_SEC;
            $this->_writeLog('Fruugo export resumed after waiting ' . $this->SLEEP_TIME_SEC . ' seconds.', self::$DEBUG);
        }
    }
}

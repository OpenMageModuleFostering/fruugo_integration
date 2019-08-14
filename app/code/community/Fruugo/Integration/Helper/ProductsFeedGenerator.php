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
    protected $MONITOR_RESOURCES = true; // Should the script check resource usage while running
    protected $MAX_RESOURCES = 0.5; // Maximum load average allowed in the last minute
    protected $SLEEP_TIME_SEC = 20; // Time to sleep for if over load limit

    protected $MAX_ERRORS = 30; // The number of errors after which the script will abort, set to -1 to disable
    protected $PAGE_SIZE = 100; // The number of products to process per batch
    // whether or not to track the last id processed to avoid a double process if items are deleted
    // in between selecting batches, incurs a small performance cost.
    protected $TRACK_LAST_ID = true;

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
            die('Cannot create lock file');
        }

        if (!flock($f, LOCK_EX | LOCK_NB)) {
            $this->_writeLog('Did not start Fruugo products export because the script is already running.', self::$WARNING);
            die('Cannot create lock file');
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
                            }

                            $numOfProds++;
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
                ->addAttributeToSelect('name')
                ->addAttributeToSort('level', 'DESC')
                ->addAttributeToSort('position', 'DESC')
                ->addAttributeToFilter('is_active', '1')
                ->getFirstItem();

        if ($categoryEntity->getName() !== null) {
            $productXml->addChild('Category', htmlspecialchars($categoryEntity->getName()));
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
            $descriptionsArray = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), array(
                'description',
                'short_description',
            ), $store->getId());

            // This is a workaround for a bug in getAttributeRawValue which returns the wrong values for product attributes that have been
            // modified in another site so to save db hits we try and get them and if they differ from the main product we are processing we
            // then load them individually
            if (isset($descriptionsArray['description']) && ($product->getDescription() != $descriptionsArray['description'])) {
                $descriptionsArray['description'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'description', $store->getId());
            }

            if (isset($descriptionsArray['short_description']) && ($product->getShortDescription() != $descriptionsArray['short_description'])) {
                $descriptionsArray['short_description'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'short_description', $store->getId());
            }

            // check product description not null for each store
            if ($descriptionType == 'long' && $descriptionsArray['description'] === null) {
                continue;
            } elseif ($descriptionType == 'short' && $descriptionsArray['short_description'] === null) {
                continue;
            }

            // Add description when no store selected for language OR the store is selected for the langauge in storelangsMapping array
            if ($storelangsMapping == null || $storelangsMapping[$localeCode] == "" || $storelangsMapping[$localeCode] == $store->getCode()) {
                // Language *R
                $descriptionXml = $productXml->addChild('Description');
                $descriptionXml->addChild('Language', $language);

                $attributes = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), array(
                    'shoe_size',
                    'size',
                    'color',
                    'fit',
                    'length',
                    'width',
                ), $store->getId());

                // This is a workaround for a bug in getAttributeRawValue which returns the wrong values for product attributes that have been
                // modified in another site so to save db hits we try and get them and if they differ from the main product we are processing we
                // then load them individually
                if (isset($attributes['fit']) && ($product->getFit() != $attributes['fit'])) {
                    $attributes['fit'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'fit', $store->getId());
                }

                if (isset($attributes['color']) && ($product->getColor() != $attributes['color'])) {
                    $attributes['color'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'color', $store->getId());
                }

                if (isset($attributes['size']) && ($product->getSize() != $attributes['size'])) {
                    $attributes['size'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'size', $store->getId());
                }

                if (isset($attributes['shoe_size']) && ($product->getShoe_size() != $attributes['shoe_size'])) {
                    $attributes['shoe_size'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'shoe_size', $store->getId());
                }

                if (isset($attributes['length']) && ($product->getLength() != $attributes['length'])) {
                    $attributes['length'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'length', $store->getId());
                }

                if (isset($attributes['width']) && ($product->getWidth() != $attributes['width'])) {
                    $attributes['width'] =  Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'width', $store->getId());
                }

                // title
                $name = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product->getId(), 'name', $store->getId());
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

                 // AttributeColor *R
                if (!empty($attributes['color'])) {
                    $descriptionXml->addChild('AttributeColor', $this->_getAttributesText($language, 'color', $attributes['color'], $store->getId()));
                }

                // AttributeSize *R
                if (!empty($attributes['shoe_size'])) {
                    $descriptionXml->addChild('AttributeSize', $this->_getAttributesText($language, 'shoe_size', $attributes['shoe_size'], $store->getId()));
                } elseif (!empty($attributes['size'])) {
                    $descriptionXml->addChild('AttributeSize', $this->_getAttributesText($language, 'size', $attributes['size'], $store->getId()));
                }

                // optional attributes: Arrtibute1 - Attribute10 *O
                if (!empty($attributes['fit'])) {
                    $descriptionXml->addChild('Attribute1', $this->_getAttributesText($language, 'fit', $attributes['fit'], $store->getId()));
                }
                if (!empty($attributes['length'])) {
                    $descriptionXml->addChild('Attribute2', $this->_getAttributesText($language, 'length', $attributes['length'], $store->getId()));
                }
                if (!empty($attributes['width'])) {
                    $descriptionXml->addChild('Attribute3', $this->_getAttributesText($language, 'width', $attributes['width'], $store->getId()));
                }

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
    protected function _getAttributesText($language, $attributeName, $optionId, $storeId)
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

        if (!isset($this->attributeMap[$attributeName])) {
            return $optionId;
        }

        if (!isset($this->attributeMap[$attributeName][$language])) {
            $this->attributeMap[$attributeName][$language] = array();
        }

        if (!isset($this->attributeMap[$attributeName][$language][$optionId])) {
            $this->attributeMap[$attributeName][$language][$optionId] = null;
        } else {
            return $this->attributeMap[$attributeName][$language][$optionId];
        }

        $attributeId = Mage::getResourceModel('eav/entity_attribute')->getIdByCode('catalog_product', $attributeName);
        $collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
            ->setPositionOrder('asc')
            ->setAttributeFilter($attributeId)
            ->setStoreFilter($storeId)
            ->load()
            ->toOptionArray();

        $found = false;
        foreach ($collection as $option) {
            if ($option['value'] == $optionId) {
                $found = true;
            }

            $this->attributeMap[$attributeName][$language][$option['value']] = $option['label'];
        }

        if (!$found) {
            return $optionId;
        }

        return $this->attributeMap[$attributeName][$language][$optionId];
    }

    protected $tempProductObj = false;
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
        if ($product->getSku() === null || $product->getName() === null || $product->getPrice() === null) {
            return false;
        }

        if ($product->getDescription() === null && $product->getShortDescription() === null) {
            return false;
        }

        return true;
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
        $node->appendChild($no->createCDATASection(htmlspecialchars($cdata_text)));
        return $xml;
    }

    protected function _writeProductsXml($xmlStr)
    {
        file_put_contents($this->tmpProductsXmlPath, $xmlStr, FILE_APPEND | LOCK_EX);
    }

    protected function _writeReport($report)
    {
        file_put_contents($this->reportPath, json_encode($report));
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
        $this->MAX_RESOURCES = Mage::getStoreConfig('integration_options/products_options/max_resources_load');
        $this->SLEEP_TIME_SEC = Mage::getStoreConfig('integration_options/products_options/sleep_time_sec');
        $this->MAX_ERRORS = Mage::getStoreConfig('integration_options/products_options/max_errors');

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
    }

    // This monitors system resources and pauses execution if the utilisation is
    // above the configured threshold.
    // Recommended for systems with large numbers of products
    // Note: this feature is not available on windows servers.
    protected $loadCheckCount = 0;
    protected $loadCheckTotal = 0.0;
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
        if ($this->devMode && $systemLoad[0] > $this->MAX_RESOURCES) {
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

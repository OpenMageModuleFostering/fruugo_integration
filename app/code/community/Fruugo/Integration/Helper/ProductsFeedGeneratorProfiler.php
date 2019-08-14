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
* See the GNU General Public License for more details.bitbu
*
* You should have received a copy of the GNU General Public License along with this program.
* If not, see <http://www.gnu.org/licenses/>.
*/

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

/*
* This class is for profiling and testing the performance of the feed generation.  Make sure you run
* this after you make changes to check for memory leaks and performance issues.
* It can be run by hitting this URL: http://127.0.0.1:8080/index.php/fruugo-integration/products/profiler
* NOTE: you must be in dev mode for this to work.
*/
class Fruugo_Integration_ProductsFeedGeneratorProfiler extends Fruugo_Integration_ProductsFeedGenerator
{
    protected $currentTimer = array();

    protected $openTimers = array();

    protected $executionTree = array();

    public function generateProdcutsFeed($cached = false) {
        $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');
        if($devMode != '1') {
            throw new Exception('You must be in dev mode to use this feature');
        }
        Fruugo_Integration_Helper_Logger::$LOG_LEVEL = self::$DEBUG;

        $this->_writeLog('--------------------------------------------', self::$DEBUG);
        //$this->_testReport();
        //return false;
        $this->_writeLog('Profiling the product feed generator...', self::$DEBUG);
        $this->_startTimer('generateProdcutsFeed');
        $val = parent::generateProdcutsFeed($cached);
        $this->_stopTimer('generateProdcutsFeed');
        $this->_writeTimerLog();
        return $val;
    }

    protected function _fillProductXml($product, $storelangsMapping) {
        $this->_startTimer('_fillProductXml');
        $val = parent::_fillProductXml($product, $storelangsMapping);
        $this->_stopTimer('_fillProductXml');
        return $val;
    }

    protected function _getAttributesText($language, $attributeName, $optionId, $storeId = 0) {
        $this->_startTimer('_getAttributesText');
        $val = parent::_getAttributesText($language, $attributeName, $optionId, $storeId);
        $this->_stopTimer('_getAttributesText');
        return $val;
    }

    protected function _convertCurrency($price, $baseCurrencyCode, $currencyCode) {
        $this->_startTimer('_convertCurrency');
        $val = parent::_convertCurrency($price, $baseCurrencyCode, $currencyCode);
        $this->_stopTimer('_convertCurrency');
        return $val;
    }

    protected function _getProductImages($product, $parentProduct) {
        $this->_startTimer('_getProductImages');
        $val = parent::_getProductImages($product, $parentProduct);
        $this->_stopTimer('_getProductImages');
        return $val;
    }

    protected function _getParentProduct($product)
    {
        $this->_startTimer('_getParentProduct');
        $val = parent::_getParentProduct($product);
        $this->_stopTimer('_getParentProduct');
        return $val;
    }

    protected function _startTimer($id, $message=null)
    {
        if (!array_key_exists($id, $this->currentTimer)) {
            $this->currentTimer[$id] = array(
                'start_time' => 0,
                'stop_time' => 0,
                'total' => 0,
                'length' => 0,
                'count' => 0,
                'callers' => array(),
                'start_memory' => 0,
                'total_memory_leaked' => 0,
            );
        }

        $this->currentTimer[$id]['start_memory'] = memory_get_usage(false);

        if(count($this->openTimers) > 0) {
            $caller = $this->openTimers[0];
            if(!array_key_exists($caller, $this->currentTimer[$id]['callers'])) {
                $this->currentTimer[$id]['callers'][$caller] = 1;
            }
            $this->currentTimer[$id]['callers'][$caller]++;

            $this->_addToTree($id, $caller);
        }
        else {
            $this->_addToTree($id, null);
        }

        array_unshift($this->openTimers, $id);

        $this->currentTimer[$id]['start_time'] = microtime(true);
        if($message) {
            $this->_writeLog($message, self::$DEBUG);
        }
    }

    protected function _stopTimer($id, $message=null, $threshhold = 1000, $threshholdMessage = '')
    {
        if (!array_key_exists($id, $this->currentTimer)) {
            return;
        }

        $start_memory = $this->currentTimer[$id]['start_memory'];
        $this->currentTimer[$id]['total_memory_leaked'] += memory_get_usage(false) - $start_memory;

        $this->currentTimer[$id]['stop_time'] = microtime(true);
        $stopTimer = ($this->currentTimer[$id]['stop_time'] - $this->currentTimer[$id]['start_time']);
        $this->currentTimer[$id]['length'] = $stopTimer;
        $this->currentTimer[$id]['total'] += $this->currentTimer[$id]['length'];
        $this->currentTimer[$id]['count'] += 1;

        if (round($stopTimer) >= $threshhold) {
            $this->_writeLog('=================================================', self::$DEBUG);
            $this->_writeLog($id . ' exceeded threshold of ' . $threshhold . ' with the time of ' . $stopTimer . ' threshholdMessage ' . $threshholdMessage, self::$DEBUG);
        }

        if($message) {
            $this->_writeLog($message . ' in ' . $stopTimer . ' seconds.' . ' Running total: ' . $this->currentTimer[$id]['total'], self::$DEBUG);
        }

        array_shift($this->openTimers);
    }

    protected function _writeTimerLog()
    {
        $this->_writeLog('Profiling Statistics', self::$DEBUG);
        foreach ($this->currentTimer as $key => $value) {
            $this->_writeLog("\n", self::$DEBUG);
            $this->_writeLog('--------------------------------------------------', self::$DEBUG);
            $this->_writeLog('Stats for ' . $key);
            $this->_writeLog('Total executing time:' . round($value['total'], 7), self::$DEBUG);
            $this->_writeLog('Average execution time: ' . round($value['total']/$value['count'], 7), self::$DEBUG);
            $this->_writeLog('Times executed: ' . $value['count'], self::$DEBUG);
            $this->_writeLog('Memory leaked: ' . (($value['total_memory_leaked'] / 1024) / 1024) . ' MB', self::$DEBUG);
            $this->_writeLog('Called by: ', self::$DEBUG);
            foreach ($value['callers'] as $caller => $callerCount) {
                $this->_writeLog("\t" . $caller . ' x ' . $callerCount, self::$DEBUG);
            }
        }

        $this->_writeLog("\n", self::$DEBUG);
        $this->_writeLog('--------------------------------------------', self::$DEBUG);
        $this->_generateTree();
    }

    protected function _generateTree() {
        foreach($this->executionTree[0] as $parentkey => $val) {
            $this->_writeLog($parentkey, self::$DEBUG);
            $this->_writeTree(1, $parentkey, $parentkey, 0);
        }
    }

    // Note: the tree view is not a finished feature, and there may be display issues
    protected function _writeTree($x, $parent, $caller, $indentLen) {

        if($x >= count($this->executionTree)) {
            return;
        }
        if(!array_key_exists($caller, $this->executionTree[$x])) {
            return;
        }
        foreach($this->executionTree[$x][$caller]['children'] as $key => $val) {
            $len = strlen($caller) + $indentLen;
            $indent = str_pad("",  $len, " ");
            $line = str_pad("", strlen($caller)/3, "-");
            $arrow = '|' . $line . '(x ' . $val['call_count'] . ')' . $line . '> ';
            $this->_writeLog( $indent . $arrow . $key, self::$DEBUG);
            $this->_writeTree($x + 1, $caller, $key, strlen($caller . $arrow));
        }
    }

     protected function _addToTree($id, $caller=null) {
        $depth  = count($this->openTimers);
        if(!isset($this->executionTree[$depth])) {
            $this->executionTree[$depth] = array();
        }
        $data = array(
                    'name' => $id,
                    'depth' => $depth,
                    'children' => array(),
                    'parents' => array()
                );

        $childData = array(
                    'name' => $id,
                    'depth' => $depth,
                    'children' => array(),
                    'parents' => array(),
                    'call_count' => 0,
                );

        if($caller === null) {
            $this->executionTree[$depth] = array();
            $this->executionTree[$depth][$id] = $data;
            return;
        }
        else{
            if(!array_key_exists($caller, $this->executionTree[$depth])) {
                $this->executionTree[$depth][$caller] = $data;
            }
            if(!array_key_exists($id, $this->executionTree[$depth][$caller]['children'])) {
                $this->executionTree[$depth][$caller]['children'][$id] = $childData;
            }
            $this->executionTree[$depth][$caller]['children'][$id]['call_count'] += 1;
        }
    }

    // Some fixtures data for testing the report generation
    protected function _testReport() {

        $this->executionTree = Array (
                0 => Array
                    (
                        'generateProdcutsFeed' => Array
                            (
                                'name' => 'generateProdcutsFeed',
                                'depth' => 0,
                                'children' => Array(),
                                'parents' => Array(),
                            )
                    ),

                1 => Array
                    (
                        'generateProdcutsFeed' => Array
                            (
                                'name' => '_fillProductXml',
                                'depth' => 1,
                                'children' => Array
                                    (
                                        '_fillProductXml' => Array
                                            (
                                                'name' => '_fillProductXml',
                                                'depth' => 1,
                                                'children' => Array(),
                                                'parents' => Array(),

                                                'call_count' => 477,
                                            )

                                    ),

                                'parents' => Array(),
                            )

                    ),

                2 => Array
                    (
                        '_fillProductXml' => Array
                            (
                                'name' => '_getParentProduct',
                                'depth' => 2,
                                'children' => Array
                                    (
                                        '_getParentProduct' => Array
                                            (
                                                'name' => '_getParentProduct',
                                                'depth' => 2,
                                                'children' => Array(),
                                                'parents' => Array(),
                                                'call_count' => 477,
                                            ),

                                        '_getProductImages' => Array
                                            (
                                                'name' => '_getProductImages',
                                                'depth' => 2,
                                                'children' => Array(),
                                                'parents' => Array(),
                                                'call_count' => 477,
                                            ),

                                        '_getAttributesText' => Array
                                            (
                                                'name' => '_getAttributesText',
                                                'depth' => 2,
                                                'children' => Array(),
                                                'parents' => Array(),
                                                'call_count' => 3762,
                                            ),

                                        '_convertCurrency' => Array
                                            (
                                                'name' => '_convertCurrency',
                                                'depth' => 2,
                                                'children' => Array(),
                                                'parents' => Array(),
                                                'call_count' => 1455,
                                            ),

                                    ),

                                'parents' => Array
                                    (
                                    ),

                            )

                    )

            );

        $this->_generateTree();
    }
}

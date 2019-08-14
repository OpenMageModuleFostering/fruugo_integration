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

class Fruugo_Integration_Model_Adminhtml_System_Config_Source_Attributes
{
    protected $_options = array();
    protected $excludeAttributeCodes = array();

    public function toOptionArray($isMultiselect = false)
    {
        if (!$this->_options) {
            $this->_options = $this->_getOptions();
        }

        $options = $this->_options;

        if (!$isMultiselect) {
            array_unshift($options, array(
                'value' => '',
                'label' => Mage::helper('adminhtml')->__('--Please Select--')
            ));
        }

        return $options;
    }

    protected function _getOptions()
    {
        $options = array();

        foreach (Mage::getResourceModel('catalog/product_attribute_collection')->loadData() as $attribute) {
            if (!$attribute->getIsVisible()
                || in_array($attribute->getAttributeCode(), $this->excludeAttributeCodes)) {
                continue;
            }

            array_push($options, array(
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getAttributeCode()
            ));
        }

        return $options;
    }
}

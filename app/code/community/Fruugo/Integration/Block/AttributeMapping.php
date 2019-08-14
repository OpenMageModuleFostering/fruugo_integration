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

class Fruugo_Integration_Block_AttributeMapping
    extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{
    protected $_itemRenderer;
    protected $_fieldLayout = 'Fruugo_Integration_Block_AttributesDropdown';

    public function _prepareToRender()
    {
        $this->addColumn('attribute', array(
            'label' => 'Attribute',
            'renderer' => $this->_getRenderer()
        ));

        $this->addColumn('priority', array(
            'label' => 'Priority',
            'style' => 'width: 50px',
            'type' => 'number'
        ));

        $this->_addAfter = false;
        $this->_addButtonLabel = 'Add';
    }

    protected function _getRenderer()
    {
        if (!$this->_itemRenderer) {
            $this->_itemRenderer = $this->getLayout()->createBlock(
                $this->_fieldLayout,
                '',
                array('is_render_to_js_template' => true)
            );
        }

        return $this->_itemRenderer;
    }

    protected function _prepareArrayRow(Varien_Object $row)
    {
        $row->setData(
            'option_extra_attr_' . $this->_getRenderer()
                ->calcOptionHash($row->getData('attribute')),
            'selected="selected"'
        );
    }
}

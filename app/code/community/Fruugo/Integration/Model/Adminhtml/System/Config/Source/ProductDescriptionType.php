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

/**
 * Used in creating options for Fruugo product descritption type
 *
 */
class Fruugo_Integration_Model_Adminhtml_System_Config_Source_ProductDescriptionType
{

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $descriptionTypes = array();

        array_push($descriptionTypes, array('value' => 'long', 'label' => 'Description only'));
        array_push($descriptionTypes, array('value' => 'short', 'label' => 'Short description only'));
        array_push($descriptionTypes, array('value' => 'merge', 'label' => 'Merge description and short description (description at first)'));
        array_push($descriptionTypes, array('value' => 'merge_short_first', 'label' => 'Merge short description and description (short description at first)'));

        return $descriptionTypes;
    }
}

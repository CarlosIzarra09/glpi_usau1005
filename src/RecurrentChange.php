<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2022 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

class RecurrentChange extends CommonITILRecurrent
{
    /**
     * @var string CommonDropdown
     */
    public $second_level_menu = "recurrentchange";

    /**
     * @var string Right managements
     */
    public static $rightname = 'recurrentchange';

    public static function getTypeName($nb = 0)
    {
        return __('Recurrent changes');
    }

    public static function getConcreteClass()
    {
        return Change::class;
    }

    public static function getTemplateClass()
    {
        return ChangeTemplate::class;
    }

    public static function getPredefinedFieldsClass()
    {
        return ChangeTemplatePredefinedField::class;
    }

    public function checkAgainIfMandatoryFieldsAreCorrect(array $input):bool{
        $mandatory_missing = [];
        $incorrect_format = [];

        $fields_necessary = [
            'entities_id' => 'number',
            '_glpi_csrf_token' => 'string',
            'is_recursive' => 'number',
            'name' => 'string',
            'comment' => 'string',
            'is_active' => 'number',
            'changetemplates_id' => 'number',
            'begin_date' => '',
            'end_date' => '',
            'periodicity' => 'number',
            'create_before' => 'number',
            'calendars_id' => 'number',
            ];


        foreach($fields_necessary as $key => $value){
            
            if(!isset($input[$key])){
                array_push($mandatory_missing, $key); 
            }else{
                //Si la key existe en $_POST

                if($value == 'number' && !is_numeric($input[$key]) ){
                    array_push($incorrect_format, $key);
                }
                else if($value == 'string' && !is_string($input[$key]) ){
                    array_push($incorrect_format, $key);
                }
            }
        }

        //REGLA DE NOGOCIO:


        if (count($mandatory_missing)) {
            //TRANS: %s are the fields concerned
            $message = sprintf(
                __('No se enviaron los siguientes campos en la petición. Por favor corregir: %s'),
                implode(", ", $mandatory_missing)
            );
            Session::addMessageAfterRedirect($message, false, ERROR);
        }

        if (count($incorrect_format)) {
            //TRANS: %s are the fields concerned
            $message = sprintf(
                __('Los siguientes campos fueron enviados con formato incorrecto. Por favor corregir: %s'),
                implode(", ", $incorrect_format)
            );
            Session::addMessageAfterRedirect($message, false, WARNING);
        }


        if(count($mandatory_missing) || count($incorrect_format)){
            return false;
        }else{
            return $this->checkSelectorFieldsInRange($input);
        }
    }

    public function checkSelectorFieldsInRange(array &$input):bool{
        $selector_fields_outrange = [];
        
        if($input['is_active'] < 0 || $input['is_active'] > 1){
            array_push($selector_fields_outrange,'is_active');
        }

        if($input['periodicity'] < 0 || $input['periodicity'] > 315576000 ){
            array_push($selector_fields_outrange,'periodicity');
        }

        if($input['create_before'] < 0 || $input['create_before'] > 1209600 ){
            array_push($selector_fields_outrange,'create_before');
        }

        if(isset($input['begin_date']) && isset($input['end_date'])){
            if(strtotime($input['begin_date']) > strtotime(isset($input['end_date']))){
                array_push($selector_fields_outrange,'begin_date mayor a end_date');
            }
        }

        if(count($selector_fields_outrange)){
            $message = sprintf(
                __('Los siguientes campos de selección fueron enviados con valores fuera de su rango. Por favor corregir: %s'),
                implode(", ", $selector_fields_outrange)
            );
            Session::addMessageAfterRedirect($message, false, WARNING);
            return false;
        }else{
            return true;
        }

    }
}

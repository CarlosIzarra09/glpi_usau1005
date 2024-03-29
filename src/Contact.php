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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Features\CacheableListInterface;
use Glpi\Features\AssetImage;
use Glpi\Plugin\Hooks;
use Sabre\VObject;

/**
 * Contact class
 **/
class Contact extends CommonDBTM
{
    use AssetImage;

   // From CommonDBTM
    public $dohistory           = true;

    public static $rightname           = 'contact_enterprise';
    protected $usenotepad       = true;


   


    public static function getTypeName($nb = 0)
    {
        return _n('Contact', 'Contacts', $nb);
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        return $this->managePictures($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return $this->managePictures($input);
    }

    public function cleanDBonPurge()
    {

        $this->deleteChildrenAndRelationsFromDb(
            [
                Contact_Supplier::class,
                ProjectTaskTeam::class,
                ProjectTeam::class,
            ]
        );
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Contact_Supplier', $ong, $options);
        $this->addStandardTab('Document_Item', $ong, $options);
        $this->addStandardTab('ManualLink', $ong, $options);
        $this->addStandardTab('Notepad', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }


    /**
     * Get address of the contact (company one)
     *
     * @return array|null Address related fields.
     */
    public function getAddress()
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_suppliers.name',
                'glpi_suppliers.address',
                'glpi_suppliers.postcode',
                'glpi_suppliers.town',
                'glpi_suppliers.state',
                'glpi_suppliers.country'
            ],
            'FROM'         => 'glpi_suppliers',
            'INNER JOIN'   => [
                'glpi_contacts_suppliers'  => [
                    'ON' => [
                        'glpi_contacts_suppliers'  => 'suppliers_id',
                        'glpi_suppliers'           => 'id'
                    ]
                ]
            ],
            'WHERE'        => ['contacts_id' => $this->fields['id']]
        ]);

        if ($data = $iterator->current()) {
            return $data;
        }
        return null;
    }


    /**
     * Get website of the contact (company one)
     *
     *@return string containing the website
     **/
    public function getWebsite()
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_suppliers.website AS website'
            ],
            'FROM'         => 'glpi_suppliers',
            'INNER JOIN'   => [
                'glpi_contacts_suppliers'  => [
                    'ON' => [
                        'glpi_contacts_suppliers'  => 'suppliers_id',
                        'glpi_suppliers'           => 'id'
                    ]
                ]
            ],
            'WHERE'        => ['contacts_id' => $this->fields['id']]
        ]);

        if ($data = $iterator->current()) {
            return $data['website'];
        }
        return '';
    }


    public function showForm($ID, array $options = [])
    {

        $this->initForm($ID, $options);
        $vcard_url = $this->getFormURL() . '?getvcard=1&id=' . $ID;
        TemplateRenderer::getInstance()->display('generic_show_form.html.twig', [
            'item'   => $this,
            'params' => $options,
            'header_toolbar'  => [
                '<a href="' . $vcard_url . '" target="_blank" title="' . __('Vcard') . '"><i class="fas fa-address-card"></i></a>'
            ]
        ]);

        return true;
    }


    public function getSpecificMassiveActions($checkitem = null)
    {

        $isadmin = static::canUpdate();
        $actions = parent::getSpecificMassiveActions($checkitem);

        if ($isadmin) {
            $actions['Contact_Supplier' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add']
               = _x('button', 'Add a supplier');
        }

        return $actions;
    }


    protected function computeFriendlyName()
    {

        if (isset($this->fields["id"]) && ($this->fields["id"] > 0)) {
            return formatUserName(
                '',
                '',
                (isset($this->fields["name"]) ? $this->fields["name"] : ''),
                (isset($this->fields["firstname"]) ? $this->fields["firstname"] : '')
            );
        }
        return '';
    }


    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'                 => 'common',
            'name'               => __('Characteristics')
        ];

        $tab[] = [
            'id'                 => '1',
            'table'              => $this->getTable(),
            'field'              => 'name',
            'name'               => __('Last name'),
            'datatype'           => 'itemlink',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '11',
            'table'              => $this->getTable(),
            'field'              => 'firstname',
            'name'               => __('First name'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '2',
            'table'              => $this->getTable(),
            'field'              => 'id',
            'name'               => __('ID'),
            'massiveaction'      => false,
            'datatype'           => 'number'
        ];

        $tab[] = [
            'id'                 => '3',
            'table'              => $this->getTable(),
            'field'              => 'phone',
            'name'               => Phone::getTypeName(1),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'phone2',
            'name'               => __('Phone 2'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '10',
            'table'              => $this->getTable(),
            'field'              => 'mobile',
            'name'               => __('Mobile phone'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '5',
            'table'              => $this->getTable(),
            'field'              => 'fax',
            'name'               => __('Fax'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '6',
            'table'              => $this->getTable(),
            'field'              => 'email',
            'name'               => _n('Email', 'Emails', 1),
            'datatype'           => 'email',
        ];

        $tab[] = [
            'id'                 => '82',
            'table'              => $this->getTable(),
            'field'              => 'address',
            'name'               => __('Address')
        ];

        $tab[] = [
            'id'                 => '83',
            'datatype'           => 'string',
            'table'              => $this->getTable(),
            'field'              => 'postcode',
            'name'               => __('Postal code'),
        ];

        $tab[] = [
            'id'                 => '84',
            'table'              => $this->getTable(),
            'field'              => 'town',
            'name'               => __('City'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '85',
            'table'              => $this->getTable(),
            'field'              => 'state',
            'name'               => _x('location', 'State'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '87',
            'table'              => $this->getTable(),
            'field'              => 'country',
            'name'               => __('Country'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '9',
            'table'              => 'glpi_contacttypes',
            'field'              => 'name',
            'name'               => _n('Type', 'Types', 1),
            'datatype'           => 'dropdown'
        ];

        $tab[] = [
            'id'                 => '81',
            'table'              => 'glpi_usertitles',
            'field'              => 'name',
            'name'               => __('Title'),
            'datatype'           => 'dropdown'
        ];

        $tab[] = [
            'id'                 => '8',
            'table'              => 'glpi_suppliers',
            'field'              => 'name',
            'name'               => _n('Associated supplier', 'Associated suppliers', Session::getPluralNumber()),
            'forcegroupby'       => true,
            'datatype'           => 'itemlink',
            'joinparams'         => [
                'beforejoin'         => [
                    'table'              => 'glpi_contacts_suppliers',
                    'joinparams'         => [
                        'jointype'           => 'child'
                    ]
                ]
            ]
        ];

        $tab[] = [
            'id'                 => '16',
            'table'              => $this->getTable(),
            'field'              => 'comment',
            'name'               => __('Comments'),
            'datatype'           => 'text'
        ];

        $tab[] = [
            'id'                 => '80',
            'table'              => 'glpi_entities',
            'field'              => 'completename',
            'name'               => Entity::getTypeName(1),
            'massiveaction'      => false,
            'datatype'           => 'dropdown'
        ];

        $tab[] = [
            'id'                 => '86',
            'table'              => $this->getTable(),
            'field'              => 'is_recursive',
            'name'               => __('Child entities'),
            'datatype'           => 'bool'
        ];

        $tab[] = [
            'id'                 => '19',
            'table'              => $this->getTable(),
            'field'              => 'date_mod',
            'name'               => __('Last update'),
            'datatype'           => 'datetime',
            'massiveaction'      => false
        ];

        $tab[] = [
            'id'                 => '121',
            'table'              => $this->getTable(),
            'field'              => 'date_creation',
            'name'               => __('Creation date'),
            'datatype'           => 'datetime',
            'massiveaction'      => false
        ];

        $tab[] = [
            'id'                 => '70',
            'table'              => $this->getTable(),
            'field'              => 'registration_number',
            'name'               => _x('infocom', 'Administrative number'),
            'datatype'           => 'string',
            'autocomplete'       => true
        ];

       // add objectlock search options
        $tab = array_merge($tab, ObjectLock::rawSearchOptionsToAdd(get_class($this)));

        $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());

        return $tab;
    }


    /**
     * Generate the Vcard for the current Contact
     *
     * @return void
     */
    public function generateVcard()
    {

        if (!$this->can($this->fields['id'], READ)) {
            return;
        }

        $title = null;
        if ($this->fields['usertitles_id'] !== 0) {
            $title = new UserTitle();
            $title->getFromDB($this->fields['usertitles_id']);
        }
       // build the Vcard
        $vcard = new VObject\Component\VCard([
            'N'     => [$this->fields["name"], $this->fields["firstname"]],
            'EMAIL' => $this->fields["email"],
            'NOTE'  => $this->fields["comment"],
        ]);

        if ($title) {
            $vcard->add('TITLE', $title->fields['name']);
        }
        $vcard->add('TEL', $this->fields["phone"], ['type' => 'PREF;WORK;VOICE']);
        $vcard->add('TEL', $this->fields["phone2"], ['type' => 'HOME;VOICE']);
        $vcard->add('TEL', $this->fields["mobile"], ['type' => 'WORK;CELL']);
        $vcard->add('URL', $this->GetWebsite(), ['type' => 'WORK']);

        $addr = $this->getAddress();
        if (is_array($addr)) {
            $addr_string = implode(";", array_filter($addr));
            $vcard->add('ADR', $addr_string, ['type' => 'WORK;POSTAL']);
        }

       // Get more data from plugins such as an IM contact
        $data = Plugin::doHook(Hooks::VCARD_DATA, ['item' => $this, 'data' => []])['data'];
        foreach ($data as $field => $additional_field) {
            $vcard->add($additional_field['name'], $additional_field['value'] ?? '', $additional_field['params'] ?? []);
        }

       // send the  VCard
        $output   = $vcard->serialize();
        $filename = $this->fields["name"] . "_" . $this->fields["firstname"] . ".vcf";

        @header("Content-Disposition: attachment; filename=\"$filename\"");
        @header("Content-Length: " . Toolbox::strlen($output));
        @header("Connection: close");
        @header("content-type: text/x-vcard; charset=UTF-8");

        echo $output;
    }

    public function checkAgainIfMandatoryFieldsAreCorrect(array $input):bool{
        $mandatory_missing = [];
        $incorrect_format = [];

        $fields_necessary = [
            'entities_id' => 'number',
            '_glpi_csrf_token' => 'string',
            //'is_recursive' => '',
            'name' => 'string',
            'firstname' => 'string',
            'contacttypes_id' => 'number',
            'usertitles_id' => 'number',
            'registration_number' => 'string',
            'phone' => 'string',
            'phone2' => 'string',
            'mobile' => 'string',
            'fax' => 'string',
            'email' => 'string',
            'address' => 'string',
            'town' => 'string',
            'postcode' => 'string',
            'state' => 'string',
            'country' => 'string',
            'comment' => 'string',
        ];


        foreach($fields_necessary as $key => $value){
            
            if(!isset($input[$key])){
                array_push($mandatory_missing, $key);
                break;       
            }else{
                //Si la key existe en $_POST
                if($value == 'number' && !is_numeric($input[$key]) ){
                    array_push($incorrect_format, $key);
                    break;
                }
                else if($value == 'string' && !is_string($input[$key]) ){
                    array_push($incorrect_format, $key);
                    break;
                }
                
               
            }
        }

        //REGLA DE NOGOCIO:


        if (count($mandatory_missing)) {
            //TRANS: %s are the fields concerned
            $message = sprintf(
                __('No se envio el siguiente campo en la petición HTTP. Por favor corregir: %s'),
                implode(", ", $mandatory_missing)
            );
            Session::addMessageAfterRedirect($message, false, ERROR);
        }

        if (count($incorrect_format)) {
            //TRANS: %s are the fields concerned
            $message = sprintf(
                __('El siguiente campo fue enviado con tipo de dato incorrecto al esperado. Por favor corregir: %s'),
                implode(", ", $incorrect_format)
            );
            Session::addMessageAfterRedirect($message, false, WARNING);
        }


        if(count($mandatory_missing) || count($incorrect_format)){
            return false;
        }else{
            return $this->checkAppliedBusinessRules($input);
        }
    }

    public function checkAppliedBusinessRules(array &$input):bool{
        
        $selector_ids_incorrect = [];

        if($input['entities_id'] != 0 && Entity::getById($input['entities_id']) == false){
            array_push($selector_ids_incorrect,'entities_id');
        }
        else if($input['contacttypes_id'] != 0 && ContactType::getById($input['contacttypes_id']) == false){
            array_push($selector_ids_incorrect,'contacttypes_id');
        }
        else if($input['usertitles_id'] != 0 && UserTitle::getById($input['usertitles_id']) == false){
            array_push($selector_ids_incorrect,'usertitles_id');
        }
        
    
        if(count($selector_ids_incorrect)){
            $message = sprintf(
                __('Se detectó al menos un campo con Id incorrecto. Por favor corregir: %s'),
                implode(", ", $selector_ids_incorrect)
            );
            Session::addMessageAfterRedirect($message, false, ERROR);
        }

        if(count($selector_ids_incorrect)){
            return false;
        }
        else{
            return true;
        }

    }


    public static function getIcon()
    {
        return "fas fa-user-tie";
    }
}

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


    public function add(array $input, $options = [], $history = true)
    {
        global $DB, $CFG_GLPI;

        if ($DB->isSlave()) {
            return false;
        }

        // This means we are not adding a cloned object
        if (
            (!Toolbox::hasTrait($this, \Glpi\Features\Clonable::class) || !isset($input['clone']))
            && method_exists($this, 'clone')
        ) {
            // This means we are asked to clone the object (old way). This will clone the clone method
            // that will set the clone parameter to true
            if (isset($input['_oldID'])) {
                $id_to_clone = $input['_oldID'];
            }
            if (isset($input['id'])) {
                $id_to_clone = $input['id'];
            }
            if (isset($id_to_clone) && $this->getFromDB($id_to_clone)) {
                if ($clone_id = $this->clone($input, $history)) {
                    $this->getFromDB($clone_id); // Load created items fields
                }
                return $clone_id;
            }
        }

        if(!empty(trim($input['registration_number']))){
            
            if((float)$input['registration_number'] < 0){
                $input['registration_number'] = 0;
            }

            $input['registration_number'] = round((float) $input['registration_number']);
        }

        if(!empty(trim($input['phone']))){
            
            if((float)$input['phone'] < 0){
                $input['phone'] = 0;
            }

            $input['phone'] = round((float) $input['phone']);
        }

        if(!empty(trim($input['phone2']))){
            
            if((float)$input['phone2'] < 0){
                $input['phone2'] = 0;
            }

            $input['phone2'] = round((float) $input['phone2']);
        }

        if(!empty(trim($input['mobile']))){
            
            if((float)$input['mobile'] < 0){
                $input['mobile'] = 0;
            }

            $input['mobile'] = round((float) $input['mobile']);
        }

        // Store input in the object to be available in all sub-method / hook
        $this->input = $input;

        // Manage the _no_history
        if (!isset($this->input['_no_history'])) {
            $this->input['_no_history'] = !$history;
        }

        if (isset($this->input['add'])) {
            // Input from the interface
            // Save this data to be available if add fail
            $this->saveInput();
        }

        if (isset($this->input['add'])) {
            $this->input['_add'] = $this->input['add'];
            unset($this->input['add']);
        }

        // Call the plugin hook - $this->input can be altered
        // This hook get the data from the form, not yet altered
        Plugin::doHook(Hooks::PRE_ITEM_ADD, $this);

        if ($this->input && is_array($this->input)) {
            $this->input = $this->prepareInputForAdd($this->input);
        }

        if ($this->input && is_array($this->input)) {
            // Call the plugin hook - $this->input can be altered
            // This hook get the data altered by the object method
            Plugin::doHook(Hooks::POST_PREPAREADD, $this);
        }

        if ($this->input && is_array($this->input)) {
           //Check values to inject
            $this->filterValues(!isCommandLine());
        }

        //Process business rules for assets
        $this->assetBusinessRules(\RuleAsset::ONADD);

        if ($this->input && is_array($this->input)) {
            $this->fields = [];
            $table_fields = $DB->listFields($this->getTable());

            $this->pre_addInDB();

            // fill array for add
            $this->cleanLockedsOnAdd();
            foreach (array_keys($this->input) as $key) {
                if (
                    ($key[0] != '_')
                    && isset($table_fields[$key])
                ) {
                    $this->fields[$key] = $this->input[$key];
                }
            }

            // Auto set date_creation if exsist
            if (isset($table_fields['date_creation']) && !isset($this->input['date_creation'])) {
                $this->fields['date_creation'] = $_SESSION["glpi_currenttime"];
            }

            // Auto set date_mod if exsist
            if (isset($table_fields['date_mod']) && !isset($this->input['date_mod'])) {
                $this->fields['date_mod'] = $_SESSION["glpi_currenttime"];
            }

            if ($this->checkUnicity(true, $options)) {
                if ($this->addToDB() !== false) {
                    $this->post_addItem();
                    if ($this instanceof CacheableListInterface) {
                        $this->invalidateListCache();
                    }
                    $this->addMessageOnAddAction();

                    if ($this->dohistory && $history) {
                        $changes = [
                            0,
                            '',
                            '',
                        ];
                        Log::history(
                            $this->fields["id"],
                            $this->getType(),
                            $changes,
                            0,
                            Log::HISTORY_CREATE_ITEM
                        );
                    }

                    // Auto create infocoms
                    if (
                        isset($CFG_GLPI["auto_create_infocoms"]) && $CFG_GLPI["auto_create_infocoms"]
                        && (!isset($input['clone']) || !$input['clone'])
                        && Infocom::canApplyOn($this)
                    ) {
                        $ic = new Infocom();
                        if (!$ic->getFromDBforDevice($this->getType(), $this->fields['id'])) {
                            $ic->add(['itemtype' => $this->getType(),
                                'items_id' => $this->fields['id']
                            ]);
                        }
                    }

                    // If itemtype is in infocomtype and if states_id field is filled
                    // and item is not a template
                    if (
                        Infocom::canApplyOn($this)
                        && isset($this->input['states_id'])
                            && (!isset($this->input['is_template'])
                                || !$this->input['is_template'])
                    ) {
                        //Check if we have to automatically fill dates
                        Infocom::manageDateOnStatusChange($this);
                    }
                    Plugin::doHook(Hooks::ITEM_ADD, $this);

                    // As add have succeeded, clean the old input value
                    if (isset($this->input['_add'])) {
                        $this->clearSavedInput();
                    }
                    return $this->fields['id'];
                }
            }
        }

        return false;
    }

    public function update(array $input, $history = 1, $options = [])
    {
        global $DB, $GLPI_CACHE;

        if ($DB->isSlave()) {
            return false;
        }

        if (!array_key_exists(static::getIndexName(), $input)) {
            return false;
        }

        if (!$this->getFromDB($input[static::getIndexName()])) {
            return false;
        }

        if(!empty(trim($input['registration_number']))){

            /*if(strlen($input['registration_number']) > 10){
                return false;
            }*/
            
            if((float)$input['registration_number'] < 0){
                $input['registration_number'] = 0;
            }

            $input['registration_number'] = round((float) $input['registration_number']);
        }

        if(!empty(trim($input['phone']))){
            
            if((float)$input['phone'] < 0){
                $input['phone'] = 0;
            }

            $input['phone'] = round((float) $input['phone']);
        }

        if(!empty(trim($input['phone2']))){
            
            if((float)$input['phone2'] < 0){
                $input['phone2'] = 0;
            }

            $input['phone2'] = round((float) $input['phone2']);
        }

        if(!empty(trim($input['mobile']))){
            
            if((float)$input['mobile'] < 0){
                $input['mobile'] = 0;
            }

            $input['mobile'] = round((float) $input['mobile']);
        }
        
       // Store input in the object to be available in all sub-method / hook
        $this->input = $input;

       // Manage the _no_history
        if (!isset($this->input['_no_history'])) {
            $this->input['_no_history'] = !$history;
        }

        if (isset($this->input['update'])) {
           // Input from the interface
           // Save this data to be available if add fail
            $this->saveInput();
        }

        if (isset($this->input['update'])) {
            $this->input['_update'] = $this->input['update'];
            unset($this->input['update']);
        }

       // Plugin hook - $this->input can be altered
        Plugin::doHook(Hooks::PRE_ITEM_UPDATE, $this);
        if ($this->input && is_array($this->input)) {
            $this->input = $this->prepareInputForUpdate($this->input);
            $this->filterValues(!isCommandLine());
        }

       //Process business rules for assets
        $this->assetBusinessRules(\RuleAsset::ONUPDATE);

       // Valid input for update
        if ($this->checkUnicity(false, $options)) {
            if ($this->input && is_array($this->input)) {
               // Fill the update-array with changes
                $x               = 0;
                $this->updates   = [];
                $this->oldvalues = [];

                foreach (array_keys($this->input) as $key) {
                    if ($DB->fieldExists(static::getTable(), $key)) {
                      // Prevent history for date statement (for date for example)
                        if (
                            is_null($this->fields[$key])
                            && ($this->input[$key] == 'NULL')
                        ) {
                             $this->fields[$key] = 'NULL';
                        }
                      // Compare item
                        $ischanged = true;
                        $searchopt = $this->getSearchOptionByField('field', $key, $this->getTable());
                        if (isset($searchopt['datatype'])) {
                            switch ($searchopt['datatype']) {
                                case 'string':
                                case 'text':
                                    $ischanged = (strcmp(
                                        (string)$DB->escape($this->fields[$key]),
                                        (string)$this->input[$key]
                                    ) != 0);
                                    break;

                                case 'itemlink':
                                    if ($key == 'name') {
                                        $ischanged = (strcmp(
                                            (string)$DB->escape($this->fields[$key]),
                                            (string)$this->input[$key]
                                        ) != 0);
                                        break;
                                    }
                               // else default

                                default:
                                    $ischanged = ($DB->escape($this->fields[$key]) != $this->input[$key]);
                                    break;
                            }
                        } else {
                         // No searchoption case
                            $ischanged = ($DB->escape($this->fields[$key]) != $this->input[$key]);
                        }
                        if ($ischanged) {
                            if ($key != "id") {
                         // Store old values
                                if (!in_array($key, $this->history_blacklist)) {
                                     $this->oldvalues[$key] = $this->fields[$key];
                                }

                                $this->fields[$key] = $this->input[$key];
                                $this->updates[$x]  = $key;
                                $x++;
                            }
                        }
                    }
                }
                if (count($this->updates)) {
                    if (array_key_exists('date_mod', $this->fields)) {
                        // is a non blacklist field exists
                        if (count(array_diff($this->updates, $this->history_blacklist)) > 0) {
                            $this->fields['date_mod'] = $_SESSION["glpi_currenttime"];
                            $this->updates[$x++]      = 'date_mod';
                        }
                    }
                    $this->pre_updateInDB();

                    $this->cleanLockeds();
                    if (count($this->updates)) {
                        if (
                            $this->updateInDB(
                                $this->updates,
                                ($this->dohistory && $history ? $this->oldvalues
                                : [])
                            )
                        ) {
                            $this->manageLocks();
                            $this->addMessageOnUpdateAction();
                            Plugin::doHook(Hooks::ITEM_UPDATE, $this);

                            //Fill forward_entity_to array with itemtypes coming from plugins
                            if (isset(self::$plugins_forward_entity[$this->getType()])) {
                                foreach (self::$plugins_forward_entity[$this->getType()] as $itemtype) {
                                    static::$forward_entity_to[] = $itemtype;
                                }
                            }
                           // forward entity information if needed
                            if (
                                count(static::$forward_entity_to)
                                && (in_array("entities_id", $this->updates)
                                || in_array("is_recursive", $this->updates))
                            ) {
                                 $this->forwardEntityInformations();
                            }

                           // If itemtype is in infocomtype and if states_id field is filled
                           // and item not a template
                            if (
                                Infocom::canApplyOn($this)
                                && in_array('states_id', $this->updates)
                                && ($this->getField('is_template') != NOT_AVAILABLE)
                            ) {
                               //Check if we have to automatical fill dates
                                Infocom::manageDateOnStatusChange($this, false);
                            }
                        }
                    }
                }

                // As update have suceed, clean the old input value
                if (isset($this->input['_update'])) {
                    $this->clearSavedInput();
                }

                $this->post_updateItem($history);
                if ($this instanceof CacheableListInterface) {
                    $this->invalidateListCache();
                }

                return true;
            }
        }

        return false;
    }


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


    public static function getIcon()
    {
        return "fas fa-user-tie";
    }
}

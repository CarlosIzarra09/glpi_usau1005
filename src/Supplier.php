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

use Glpi\Features\AssetImage;
use Glpi\Features\CacheableListInterface;
use Glpi\Plugin\Hooks;

/**
 * Supplier class (suppliers)
 **/
class Supplier extends CommonDBTM
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

        if(!empty(trim($input['phonenumber']))){
            
            if((float)$input['phonenumber'] < 0){
                $input['phonenumber'] = 0;
            }

            $input['phonenumber'] = round((float) $input['phonenumber']);
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
            
            if((float)$input['registration_number'] < 0){
                $input['registration_number'] = 0;
            }

            $input['registration_number'] = round((float) $input['registration_number']);
        }

        if(!empty(trim($input['phonenumber']))){
            
            if((float)$input['phonenumber'] < 0){
                $input['phonenumber'] = 0;
            }

            $input['phonenumber'] = round((float) $input['phonenumber']);
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

    /**
     * Name of the type
     *
     * @param $nb : number of item in the type
     **/
    public static function getTypeName($nb = 0)
    {
        return _n('Supplier', 'Suppliers', $nb);
    }

    public function post_getEmpty()
    {
        $this->fields['is_active'] = 1;
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
                Change_Supplier::class,
                Contact_Supplier::class,
                Contract_Supplier::class,
                Problem_Supplier::class,
                ProjectTaskTeam::class,
                ProjectTeam::class,
                Supplier_Ticket::class,
            ]
        );

       // Ticket rules use suppliers_id_assign
        Rule::cleanForItemAction($this, 'suppliers_id%');
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Contact_Supplier', $ong, $options);
        $this->addStandardTab('Contract_Supplier', $ong, $options);
        $this->addStandardTab('Infocom', $ong, $options);
        $this->addStandardTab('Document_Item', $ong, $options);
        $this->addStandardTab('Ticket', $ong, $options);
        $this->addStandardTab('Item_Problem', $ong, $options);
        $this->addStandardTab('Change_Item', $ong, $options);
        $this->addStandardTab('ManualLink', $ong, $options);
        $this->addStandardTab('Notepad', $ong, $options);
        $this->addStandardTab('KnowbaseItem_Item', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }


    public static function dropdown($options = [])
    {
        $condition = ['is_active' => true];
        $options['condition'] = (isset($options['condition']) ? $options['condition'] + $condition : $condition);
        return Dropdown::show(get_called_class(), $options);
    }

    /**
     * @see CommonDBTM::getSpecificMassiveActions()
     **/
    public function getSpecificMassiveActions($checkitem = null)
    {

        $isadmin = static::canUpdate();
        $actions = parent::getSpecificMassiveActions($checkitem);
        if ($isadmin) {
            $actions['Contact_Supplier' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add']
               = _x('button', 'Add a contact');
            $actions['Contract_Supplier' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add']
               = _x('button', 'Add a contract');
        }
        return $actions;
    }

    public function rawSearchOptions()
    {
        global $DB;

        $tab = [];

        $tab[] = [
            'id'                 => 'common',
            'name'               => __('Characteristics')
        ];

        $tab[] = [
            'id'                 => '1',
            'table'              => $this->getTable(),
            'field'              => 'name',
            'name'               => __('Name'),
            'datatype'           => 'itemlink',
            'massiveaction'      => false,
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
            'field'              => 'address',
            'name'               => __('Address'),
            'datatype'           => 'text'
        ];

        $tab[] = [
            'id'                 => '10',
            'table'              => $this->getTable(),
            'field'              => 'fax',
            'name'               => __('Fax'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '11',
            'table'              => $this->getTable(),
            'field'              => 'town',
            'name'               => __('City'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '14',
            'table'              => $this->getTable(),
            'field'              => 'postcode',
            'name'               => __('Postal code'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '12',
            'table'              => $this->getTable(),
            'field'              => 'state',
            'name'               => _x('location', 'State'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '13',
            'table'              => $this->getTable(),
            'field'              => 'country',
            'name'               => __('Country'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => $this->getTable(),
            'field'              => 'website',
            'name'               => __('Website'),
            'datatype'           => 'weblink',
        ];

        $tab[] = [
            'id'                 => '5',
            'table'              => $this->getTable(),
            'field'              => 'phonenumber',
            'name'               => Phone::getTypeName(1),
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
            'id'                 => '9',
            'table'              => 'glpi_suppliertypes',
            'field'              => 'name',
            'name'               => SupplierType::getTypeName(1),
            'datatype'           => 'dropdown'
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

        if ($_SESSION["glpinames_format"] == User::FIRSTNAME_BEFORE) {
            $name1 = 'firstname';
            $name2 = 'name';
        } else {
            $name1 = 'name';
            $name2 = 'firstname';
        }

        $tab[] = [
            'id'                 => '8',
            'table'              => 'glpi_contacts',
            'field'              => 'completename',
            'name'               => _n('Associated contact', 'Associated contacts', Session::getPluralNumber()),
            'forcegroupby'       => true,
            'datatype'           => 'itemlink',
            'massiveaction'      => false,
            'computation'        => "CONCAT(" . $DB->quoteName("TABLE.$name1") . ", ' ', " . $DB->quoteName("TABLE.$name2") . ")",
            'computationgroupby' => true,
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
            'id'                 => '29',
            'table'              => 'glpi_contracts',
            'field'              => 'name',
            'name'               => _n('Associated contract', 'Associated contracts', Session::getPluralNumber()),
            'forcegroupby'       => true,
            'datatype'           => 'itemlink',
            'massiveaction'      => false,
            'joinparams'         => [
                'beforejoin'         => [
                    'table'              => 'glpi_contracts_suppliers',
                    'joinparams'         => [
                        'jointype'           => 'child'
                    ]
                ]
            ]
        ];

        $tab[] = [
            'id'                 => '70',
            'table'              => $this->getTable(),
            'field'              => 'registration_number',
            'name'               => _x('infocom', 'Administrative number'),
            'datatype'           => 'string',
            'autocomplete'       => true
        ];

        $tab[] = [
            'id'                 => '7',
            'table'              => $this->getTable(),
            'field'              => 'is_active',
            'name'               => __('Active'),
            'datatype'           => 'bool'
        ];

       // add objectlock search options
        $tab = array_merge($tab, ObjectLock::rawSearchOptionsToAdd(get_class($this)));

        $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());

        return $tab;
    }


    /**
     * Get links for an enterprise (website / edit)
     *
     * @param $withname boolean : also display name ? (false by default)
     **/
    public function getLinks($withname = false)
    {
        global $CFG_GLPI;

        $ret = '&nbsp;&nbsp;&nbsp;&nbsp;';

        if ($withname) {
            $ret .= $this->fields["name"];
            $ret .= "&nbsp;&nbsp;";
        }

        if (!empty($this->fields['website'])) {
            $ret .= "<a href='" . Toolbox::formatOutputWebLink($this->fields['website']) . "' target='_blank'>
                  <img src='" . $CFG_GLPI["root_doc"] . "/pics/web.png' class='middle' alt=\"" .
                   __s('Web') . "\" title=\"" . __s('Web') . "\"></a>&nbsp;&nbsp;";
        }

        if ($this->can($this->fields['id'], READ)) {
            $ret .= "<a href='" . Supplier::getFormURLWithID($this->fields['id']) . "'>
                  <img src='" . $CFG_GLPI["root_doc"] . "/pics/edit.png' class='middle' alt=\"" .
                   __s('Update') . "\" title=\"" . __s('Update') . "\"></a>";
        }
        return $ret;
    }


    /**
     * Print the HTML array for infocoms linked
     *
     *@return void
     *
     **/
    public function showInfocoms()
    {
        global $DB;

        $instID = $this->fields['id'];
        if (!$this->can($instID, READ)) {
            return false;
        }

        $types_iterator = Infocom::getTypes(['suppliers_id' => $instID]);
        $number = count($types_iterator);

        echo "<div class='spaced'><table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>";
        Html::printPagerForm();
        echo "</th><th colspan='3'>";
        if ($number == 0) {
            echo __('No associated item');
        } else {
            echo _n('Associated item', 'Associated items', $number);
        }
        echo "</th></tr>";
        echo "<tr><th>" . _n('Type', 'Types', 1) . "</th>";
        echo "<th>" . Entity::getTypeName(1) . "</th>";
        echo "<th>" . __('Name') . "</th>";
        echo "<th>" . __('Serial number') . "</th>";
        echo "<th>" . __('Inventory number') . "</th>";
        echo "</tr>";

        $num = 0;
        foreach ($types_iterator as $row) {
            $itemtype = $row['itemtype'];

            if (!($item = getItemForItemtype($itemtype))) {
                continue;
            }

            if ($item->canView()) {
                $linktype  = $itemtype;
                $linkfield = 'id';
                $itemtable = getTableForItemType($itemtype);

                $criteria = [
                    'SELECT'       => [],
                    'FROM'         => 'glpi_infocoms',
                    'INNER JOIN'   => [
                        $itemtable  => [
                            'ON' => [
                                'glpi_infocoms'   => 'items_id',
                                $itemtable        => 'id'
                            ]
                        ]
                    ]
                ];

               // Set $linktype for entity restriction AND link to search engine
                if ($itemtype == 'Cartridge') {
                    $criteria['INNER JOIN']['glpi_cartridgeitems'] = [
                        'ON' => [
                            'glpi_cartridgeitems'   => 'id',
                            'glpi_cartridges'       => 'cartridgeitems_id'
                        ]
                    ];

                    $linktype  = 'CartridgeItem';
                    $linkfield = 'cartridgeitems_id';
                }

                if ($itemtype == 'Consumable') {
                    $criteria['INNER JOIN']['glpi_consumableitems'] = [
                        'ON' => [
                            'glpi_consumableitems'  => 'id',
                            'glpi_consumables'      => 'cartridgeitems_id'
                        ]
                    ];

                    $linktype  = 'ConsumableItem';
                    $linkfield = 'consumableitems_id';
                }

                if ($itemtype == 'Item_DeviceControl') {
                    $criteria['INNER JOIN']['glpi_devicecontrols'] = [
                        'ON' => [
                            'glpi_items_devicecontrols'   => 'devicecontrols_id',
                            'glpi_devicecontrols'         => 'id'
                        ]
                    ];

                    $linktype = 'DeviceControl';
                    $linkfield = 'devicecontrols_id';
                }

                $linktable = getTableForItemType($linktype);

                $criteria['SELECT'] = [
                    'glpi_infocoms.entities_id',
                    $linktype::getNameField(),
                    "$itemtable.*"
                ];

                $criteria['WHERE'] = [
                    'glpi_infocoms.itemtype'      => $itemtype,
                    'glpi_infocoms.suppliers_id'  => $instID,
                ] + getEntitiesRestrictCriteria($linktable);

                $criteria['ORDERBY'] = [
                    'glpi_infocoms.entities_id',
                    "$linktable." . $linktype::getNameField()
                ];

                $iterator = $DB->request($criteria);
                $nb = count($iterator);

                if ($nb > $_SESSION['glpilist_limit']) {
                    echo "<tr class='tab_bg_1'>";
                    $title = $item->getTypeName($nb);
                    if ($nb > 0) {
                        $title = sprintf(__('%1$s: %2$s'), $title, $nb);
                    }
                    echo "<td class='center'>" . $title . "</td>";
                    echo "<td class='center' colspan='2'>";
                    $opt = ['order'      => 'ASC',
                        'is_deleted' => 0,
                        'reset'      => 'reset',
                        'start'      => 0,
                        'sort'       => 80,
                        'criteria'   => [0 => ['value'      => '$$$$' . $instID,
                            'searchtype' => 'contains',
                            'field'      => 53
                        ]
                        ]
                    ];
                    $link = $linktype::getSearchURL();
                    $link .= (strpos($link, '?') ? '&amp;' : '?');

                    echo "<a href='$link" .
                     Toolbox::append_params($opt) . "'>" . __('Device list') . "</a></td>";

                    echo "<td class='center'>-</td><td class='center'>-</td></tr>";
                } else if ($nb) {
                    $prem = true;
                    foreach ($iterator as $data) {
                        $name = $data[$linktype::getNameField()];
                        if ($_SESSION["glpiis_ids_visible"] || empty($data["name"])) {
                            $name = sprintf(__('%1$s (%2$s)'), $name, $data["id"]);
                        }
                        $link = $linktype::getFormURLWithID($data[$linkfield]);
                        $name = "<a href='$link'>" . $name . "</a>";

                        echo "<tr class='tab_bg_1";
                        if (isset($data['is_template']) && $data['is_template'] == 1) {
                            echo " linked-template";
                        }
                        echo "'>";
                        if ($prem) {
                            $prem = false;
                            $title = $item->getTypeName($nb);
                            if ($nb > 0) {
                                $title = sprintf(__('%1$s: %2$s'), $title, $nb);
                            }
                            echo "<td class='center top' rowspan='$nb'>" . $title . "</td>";
                        }
                        echo "<td class='center'>" . Dropdown::getDropdownName(
                            "glpi_entities",
                            $data["entities_id"]
                        ) . "</td>";
                        echo "<td class='center";
                        echo ((isset($data['is_deleted']) && $data['is_deleted']) ? " tab_bg_2_2'" : "'") . ">";
                        echo $name . "</td>";
                        echo "<td class='center'>" .
                           (isset($data["serial"]) ? "" . $data["serial"] . "" : "-") . "</td>";
                        echo "<td class='center'>" .
                           (isset($data["otherserial"]) ? "" . $data["otherserial"] . "" : "-") . "</td>";
                        echo "</tr>";
                    }
                }
                $num += $nb;
            }
        }
        echo "<tr class='tab_bg_2'>";
        echo "<td class='center'>" . (($num > 0) ? sprintf(__('%1$s = %2$s'), __('Total'), $num)
                                             : "&nbsp;") . "</td>";
        echo "<td colspan='4'>&nbsp;</td></tr> ";
        echo "</table></div>";
    }

    /**
     * Get suppliers matching a given email
     *
     * @since 9.5
     *
     * @param $email boolean : also display name ? (false by default)
     **/
    public static function getSuppliersByEmail($email)
    {
        global $DB;

        $suppliers = $DB->request([
            'SELECT' => ["id"],
            'FROM' => 'glpi_suppliers',
            'WHERE' => ['email' => $email]
        ]);

        return $suppliers;
    }


    public static function getIcon()
    {
        return "fas fa-dolly";
    }
}

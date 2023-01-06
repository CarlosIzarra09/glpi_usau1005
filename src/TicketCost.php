<?php
use Glpi\Features\CacheableListInterface;
use Glpi\Plugin\Hooks;

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

/**
 * TicketCost Class
 *
 * @since 0.84
 **/
class TicketCost extends CommonITILCost
{
   // From CommonDBChild
    public static $itemtype  = 'Ticket';
    public static $items_id  = 'tickets_id';

    public static $rightname        = 'ticketcost';

    //Override Add method for ticketcost
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

        // Store input in the object to be available in all sub-method / hook


        //Validate not empty parameters - USAU
        if(empty(trim($input['begin_date'])) || empty(trim($input['end_date'])) ){
            return false;
        }

        //Si estoy poniendo una fecha de inicio por debajo de mi limite, se establecerá el minimo permitido
        if($input['begin_date'] < "1990-01-01"){
            $input['begin_date'] = "1990-01-01";
        }

        //Si mi fecha de inicio es la misma que mi fecha de fin, le agrego un dia a la fecha fin
        if($input['begin_date'] === $input['end_date']){
            $input['end_date'] = date('Y-m-d', strtotime($input['begin_date'] . ' +1 day'));
            //$input['end_date']->modify('+1 day');
        }


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
}

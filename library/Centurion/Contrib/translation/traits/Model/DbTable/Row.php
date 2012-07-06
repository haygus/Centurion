<?php
/**
 * @class Translation_Traits_Model_DbTable_Row
 * Trait to support translation  versionning
 *
 * @package Centurion
 * @subpackage Transaltion
 * @author Mathias Desloges, Laurent Chenay, Richard DELOGE, rd@octaveoctave.com
 * @copyright Octave & Octave
 */
class Translation_Traits_Model_DbTable_Row
        extends Centurion_Traits_Model_DbTable_Row_Abstract{

    /**
     * used to prefix localized columns
     * @var string
     */
    protected $_prefix = null;

    /**
     * @var Centurion_Db_Table_Row_Abstract
     */
    protected $_original_row = null;

    /**
     * Initialize this trait, connect it on preSave and postSave triggers of the row to complete action
     */
    public function init(){
        Centurion_Signal::factory('pre_save')->connect(
            array($this, 'preSave'),
            $this->_row,
            Centurion_Signal::BEHAVIOR_CAN_STOP
        );

        //To set the compliance with "save and continue" button
        Centurion_Signal::factory('post_save')->connect(
            array($this, 'postSave'),
            $this->_row,
            Centurion_Signal::BEHAVIOR_STOP_PROPAGATION
        );


        $this->_prefix = $this->getTable()->getLocalizedColsPrefix();

        //Change the behavior of permalink to support localization and add support of original row
        $special = $this->_specialGets;
        $special['original'] = array($this, 'getOriginal');
        $special['permalink'] = array($this, 'getLocalizedAbsoluteUrl');
        $this->_specialGets = $special;
    }

    /**
     * Return the permalink of the row
     * @return string
     */
    public function getLocalizedAbsoluteUrl() 
    {
        return $this->_getAbsoluteUrl($this->getAbsoluteUrl());
    }

    /**
     * Return arguments to build the permalink of the current row with the support of the language
     * @return array
     */
    public function getAbsoluteUrl()
    {
        $returnValue = $this->_row->getAbsoluteUrl();
        $returnValue[0]['language'] = Zend_Registry::get('Zend_Translate')->getLocale();
        
        return $returnValue;
    }

    /**
     * Return the original row for localized row
     * @return Centurion_Db_Table_Row_Abstract|null
     */
    public function getOriginal()
    {
        if(null === $this->_original_row){
            Centurion_Db_Table_Abstract::setFiltersStatus(false);
            //Get the original row. we disable translation fiter to get only the original row and not the localized row
            $row = null;
            $columnName = 'original';
            $referenceMap = $this->getTable()->info('referenceMap');
            if (isset($referenceMap[$columnName])) {
                $column = $referenceMap[$columnName]['columns'];
                $className = $referenceMap[$columnName]['refTableClass'];
                $this->_original_row = $this->findParentRow($referenceMap[$columnName]['refTableClass'],
                                               $columnName);
            }
            Centurion_Db_Table_Abstract::restoreFiltersStatus();
        }

        return $this->_original_row;
    }

    /**
     * Return the value for a column. If a value is translatable, this method return the localized value
     * instead of the original value
     * @param string $col
     * @return mixed
     */
    public function _getRawData($col)
    {
        $spec = $this->getTable()->getTranslationSpec();
        if (!empty($this->_data[Translation_Traits_Model_DbTable::ORIGINAL_FIELD])
            && in_array($col, $spec[Translation_Traits_Model_DbTable::SET_NULL_FIELDS])) {

            //For all set null field, we return the original value
            $this->getOriginal()->{$col};
        }

        //Add to translated field, the original id and the language id to return value of the localized value
        //instaed of the original value
        $_translatableCols = array_merge(
                $spec[Translation_Traits_Model_DbTable::TRANSLATED_FIELDS],
                array(
                    Translation_Traits_Model_DbTable::LANGUAGE_FIELD,
                    Translation_Traits_Model_DbTable::ORIGINAL_FIELD
                )
            );

        if (!array_key_exists($this->_prefix.$col, $this->_data)
            || !in_array($col, $_translatableCols)){
            return $this->_data[$col];
        }

        //Return the localized value if exist, else return the otiringal value
        if (isset($this->_data[$this->_prefix.$col])) {
            return $this->_data[$this->_prefix.$col];
        } else {
            return $this->_data[$col];
        }
    }

    /**
     * To clean value of localized row before saving thel
     * @return string
     */
    public function preSave()
    {
        $behavior = Centurion_Signal::BEHAVIOR_CONTINUE;

        if (method_exists($this->_row, 'translationPreSaveTraitBehavior')){
            //If a specific behavior is defined for the current row for this operation, retrieve it
            $behavior = $this->_row->translationPreSaveTraitBehavior();
        }

        $spec = $this->getTable()->getTranslationSpec();

        if ($this->{Translation_Traits_Model_DbTable::ORIGINAL_FIELD}) {
            //Clean non translatable fields with original value
            $parent = $this->getTable()->find($this->{Translation_Traits_Model_DbTable::ORIGINAL_FIELD})->current();
            $this->_processSpecialFields($spec[Translation_Traits_Model_DbTable::DUPLICATED_FIELDS], $parent, null, false);
            $this->_processSpecialFields($spec[Translation_Traits_Model_DbTable::SET_NULL_FIELDS]);
            $behavior = Centurion_Signal::BEHAVIOR_STOP_PROPAGATION;

        }
        else {
            //If the original row has not a defiend language, set the default language
            if (!$this->{Translation_Traits_Model_DbTable::LANGUAGE_FIELD}){
                $this->{Translation_Traits_Model_DbTable::LANGUAGE_FIELD}
                                    = Translation_Traits_Common::getDefaultLanguage()->pk;
            }

        }

        return $behavior;
    }

    /**
     * Called to update duplicated field of localized rows when the original row is updated
     */
    public function postSave(){
        if (empty($this->_row->{Translation_Traits_Model_DbTable::ORIGINAL_FIELD})) {
            //If it is an original row

            //Get all localized value
            Centurion_Db_Table::setFiltersStatus(false);
            $translations = $this->getTable()->filter(array(Translation_Traits_Model_DbTable::ORIGINAL_FIELD => $this->pk));
            Centurion_Db_Table::restoreFiltersStatus();

            //Update each loaclized velu
            $spec = $this->getTable()->getTranslationSpec();
            foreach ($translations as $translation) {
                $this->_processSpecialFields(
                        $spec[Translation_Traits_Model_DbTable::DUPLICATED_FIELDS],
                        $this,
                        $translation,
                        false
                    );

                //@todo do we reset translated field if original data changes ?
                //$this->_processSpecialFields($spec[Translation_Traits_Model_DbTable::TRANSLATED_FIELDS], $this, $translation);

                $translation->save();
            }
        }
    }

    public function getMissingTranslation()
    {
        //@todo method useless ?
        $row = $this;

        if (null !== $this->original_id) {
            $row = $this->original;
        }

        $name = $this->_row->getTable()->info(Centurion_Db_Table_Abstract::NAME);

        $select = Centurion_Db::getSingleton('translation/language')->select(true)->setIntegrityCheck(false);
        $select->joinLeft($name, $name . '.`language_id` = `translation_language`.`id` and (' . $name . '.`id` = ' . $row->id . ' or ' . $name . '.`original_id` = ' . $row->id . ')');
        $select->where($name . '.`id` is null');

        $languages = $select->fetchAll();

        $str = array();
        foreach ($languages as $language) {
            if(null !== $this->_usePermissions && $this->_usePermissions == true) {
                if(Centurion_Auth::getCurrent()->isAllowed('translation_manage_view') && Centurion_Auth::getCurrent()->isAllowed(sprintf('translation_country_%s', $language->locale))) {
                    $str[] = '<img src="' . $language->flag . '" />';
                }
            } else {
                $str[] = '<img src="' . $language->flag . '" />';
            }
        }

        return implode($str, ' ');
    }

    /**
     * Duplicate from a reference to a target some fields
     * @param string[] $fieldList
     * @param Centurion_Db_Table_Row_Abstract $reference
     * @param Centurion_Db_Table_Row_Abstract|null $target if null, the target is the current row
     * @param bool $forceCopy : to force the copy when if the column does not exist
     */
    protected function _processSpecialFields($fieldList, $reference = null, $target = null, $forceCopy=true)
    {
        if (null === $target){
            //By default the target is the current row
            $target = $this;
        }

        foreach ($fieldList as $field) {
        	if($reference && $reference->columnsExists($field) || $forceCopy){
                //If there target owns the field to duplicate (or if the copy is forced)
	            if (null !== $reference){
	                $target->$field = $reference->{$field};
                }
	            else{
	                $target->$field = null;
                }
        	}
        }
    }

    /**
     * Query a dependent table to retrieve rows matching the current row.
     *
     * @param string|Zend_Db_Table_Abstract  $dependentTable
     * @param string                         OPTIONAL $ruleKey
     * @param Zend_Db_Table_Select           OPTIONAL $select
     * @return Zend_Db_Table_Rowset_Abstract Query result from $dependentTable
     * @throws Zend_Db_Table_Row_Exception If $dependentTable is not a table or is not loadable.
     */
    public function findDependentRowset($dependentTable, $ruleKey = null, Zend_Db_Table_Select $select = null){

        $db = $this->_getTable()->getAdapter();

        if (is_string($dependentTable)) {
            $dependentTable = $this->_getTableFromString($dependentTable);
        }

        if (!$dependentTable instanceof Zend_Db_Table_Abstract) {
            $type = gettype($dependentTable);
            if ($type == 'object') {
                $type = get_class($dependentTable);
            }
            require_once 'Zend/Db/Table/Row/Exception.php';
            throw new Zend_Db_Table_Row_Exception("Dependent table must be a Zend_Db_Table_Abstract, but it is $type");
        }

        // even if we are interacting between a table defined in a class and a
        // table via extension, ensure to persist the definition
        if (($tableDefinition = $this->_table->getDefinition()) !== null
            && ($dependentTable->getDefinition() == null)) {
            $dependentTable->setOptions(array(Zend_Db_Table_Abstract::DEFINITION => $tableDefinition));
        }

        if ($select === null) {
            $select = $dependentTable->select();
        } else {
            $select->setTable($dependentTable);
        }

        $map = $this->_prepareReference($dependentTable, $this->_getTable(), $ruleKey);

        if(null === $ruleKey){
            //If the ruleKey is not passed to argument, retrieve it in dependent tables
            $_dependentTables = $this->getTable()->info(Centurion_Db_Table::DEPENDENT_TABLES);
            $dependantTableClassName = get_class($dependentTable);
            //Search all key (rule name) for this dependant table
            $ruleKey = array_keys($_dependentTables, $dependantTableClassName);
        }
        elseif(!is_array($ruleKey)){
            $ruleKey = array($ruleKey);
        }

        $_localizedPrefix = $this->getTable()->getLocalizedColsPrefix();
        $_translationSpec = $this->getTable()->getTranslationSpec();

        $_translatableRelation = count($ruleKey) > 0
            && count(   array_intersect( //Check if there are at least one translatable relation
                                    $ruleKey,
                                    $_translationSpec[Translation_Traits_Model_DbTable::TRANSLATED_FIELDS]
                        )
                    ) > 0;

        for ($i = 0; $i < count($map[Zend_Db_Table_Abstract::COLUMNS]); ++$i) {
            $parentColumnName = $db->foldCase($map[Zend_Db_Table_Abstract::REF_COLUMNS][$i]);

            if($_translatableRelation && isset($this->_data[$_localizedPrefix.$parentColumnName])){
                $value = $this->_row->{$_localizedPrefix.$parentColumnName};
            }
            else{
                $value = $this->_row->{$parentColumnName};
            }

            // Use adapter from dependent table to ensure correct query construction
            $dependentDb = $dependentTable->getAdapter();
            $dependentColumnName = $dependentDb->foldCase($map[Zend_Db_Table_Abstract::COLUMNS][$i]);
            $dependentColumn = $dependentDb->quoteIdentifier($dependentColumnName, true);
            $dependentInfo = $dependentTable->info();
            $type = $dependentInfo[Zend_Db_Table_Abstract::METADATA][$dependentColumnName]['DATA_TYPE'];
            $select->where($dependentInfo['name'].'.'."$dependentColumn = ?", $value, $type);
        }

        return $dependentTable->fetchAll($select);
    }
}

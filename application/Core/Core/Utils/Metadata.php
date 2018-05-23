<?php


namespace Core\Core\Utils;

use Core\Core\Exceptions\Error;

class Metadata
{
    protected $data = null;

    protected $objData = null;

    protected $useCache;

    private $unifier;

    private $fileManager;

    private $moduleConfig;

    private $metadataHelper;

    protected $pathToModules = 'application/Core/Modules';

    protected $cacheFile = 'data/cache/application/metadata.php';

    protected $objCacheFile = 'data/cache/application/metadata.php';

    protected $paths = array(
        'corePath' => 'application/Core/Resources/metadata',
        'modulePath' => 'application/Core/Modules/{*}/Resources/metadata',
        'customPath' => 'custom/Core/Custom/Resources/metadata',
    );

    private $moduleList = null;

    protected $frontendHiddenPathList = [
        ['app', 'formula', 'functionClassNameMap'],
        ['app', 'fileStorage', 'implementationClassNameMap'],
        ['app', 'emailNotifications', 'handlerClassNameMap']
    ];

    /**
     * Default module order
     * @var integer
     */
    protected $defaultModuleOrder = 10;

    private $deletedData = array();

    private $changedData = array();

    public function __construct(\Core\Core\Utils\File\Manager $fileManager, $useCache = false)
    {
        $this->useCache = $useCache;
        $this->fileManager = $fileManager;
    }

    protected function getFileManager()
    {
        return $this->fileManager;
    }

    protected function getUnifier()
    {
        if (!isset($this->unifier)) {
            $this->unifier = new \Core\Core\Utils\File\Unifier($this->fileManager, $this, false);
        }

        return $this->unifier;
    }

    protected function getModuleConfig()
    {
        if (!isset($this->moduleConfig)) {
            $this->moduleConfig = new \Core\Core\Utils\Module($this->fileManager, $this->useCache);
        }

        return $this->moduleConfig;
    }

    protected function getMetadataHelper()
    {
        if (!isset($this->metadataHelper)) {
            $this->metadataHelper = new Metadata\Helper($this);
        }

        return $this->metadataHelper;
    }

    public function isCached()
    {
        if (!$this->useCache) {
            return false;
        }

        if (file_exists($this->cacheFile)) {
            return true;
        }

        return false;
    }

    /**
     * Init metadata
     *
     * @param  boolean $reload
     * @return void
     */
    public function init($reload = false)
    {
        if (!$this->useCache) {
            $reload = true;
        }

        if (file_exists($this->cacheFile) && !$reload) {
            $this->data = $this->getFileManager()->getPhpContents($this->cacheFile);
        } else {
            $this->clearVars();
            $this->data = $this->getUnifier()->unify('metadata', $this->paths, true);
            $this->data = $this->addAdditionalFields($this->data);

            if ($this->useCache) {
                $isSaved = $this->getFileManager()->putPhpContents($this->cacheFile, $this->data);
                if ($isSaved === false) {
                    $GLOBALS['log']->emergency('Metadata:init() - metadata has not been saved to a cache file');
                }
            }
        }
    }

    /**
     * Get metadata array
     *
     * @return array
     */
    protected function getData()
    {
        if (empty($this->data) || !is_array($this->data)) {
            $this->init();
        }

        return $this->data;
    }

    /**
    * Get Metadata
    *
    * @param mixed string|array $key
    * @param mixed $default
    *
    * @return array
    */
    public function get($key = null, $default = null)
    {
        $result = Util::getValueByKey($this->getData(), $key, $default);
        return $result;
    }

    /**
    * Get All Metadata context
    *
    * @param $isJSON
    * @param bool $reload
    *
    * @return json | array
    */
    public function getAll($isJSON = false, $reload = false)
    {
        if ($reload) {
            $this->init(true);
        }

        if ($isJSON) {
            return Json::encode($this->data);
        }
        return $this->data;
    }

    public function getAllForFrontend()
    {
        $data = $this->getAll();

        foreach ($this->frontendHiddenPathList as $row) {
            $p =& $data;
            $path = [&$p];
            foreach ($row as $i => $item) {
                if (!array_key_exists($item, $p)) break;
                if ($i == count($row) - 1) {
                    unset($p[$item]);
                    $o =& $p;
                    for ($j = $i - 1; $j > 0; $j--) {
                        if (is_array($o) && empty($o)) {
                            $o =& $path[$j];
                            $k = $row[$j];
                            unset($o[$k]);
                        } else {
                            break;
                        }
                    }
                } else {
                    $p =& $p[$item];
                    $path[] = &$p;
                }
            }
        }
        return $data;
    }

    /**
     * todo: move to a separate file
     * Add additional fields defined from metadata -> fields
     *
     * @param array $data
     */
    protected function addAdditionalFields(array $data)
    {
        $dataCopy = $data;
        $definitionList = $data['fields'];

        foreach ($dataCopy['entityDefs'] as $entityName => $entityParams) {
            foreach ($entityParams['fields'] as $fieldName => $fieldParams) {

                $additionalFields = $this->getMetadataHelper()->getAdditionalFieldList($fieldName, $fieldParams, $definitionList);
                if (!empty($additionalFields)) {
                    //merge or add to the end of data array
                    foreach ($additionalFields as $subFieldName => $subFieldParams) {
                        if (isset($entityParams['fields'][$subFieldName])) {
                            $data['entityDefs'][$entityName]['fields'][$subFieldName] = Util::merge($subFieldParams, $entityParams['fields'][$subFieldName]);
                        } else {
                            $data['entityDefs'][$entityName]['fields'][$subFieldName] = $subFieldParams;
                        }
                    }
                }
            }
        }

        return $data;
    }

    protected function addAdditionalFieldsObj()
    {
        $data = &$this->data;

        if (!isset($data->entityDefs)) return;

        foreach (get_object_vars($data->entityDefs) as $entityType => $entityDefsItem) {
            if (!isset($entityDefsItem->fields)) continue;
            foreach (get_object_vars($entityDefsItem->fields) as $field => $fieldDefsItem) {
                $additionalFields = $this->getMetadataHelper()->getAdditionalFieldList($field, Util::objectToArray($fieldDefsItem), Util::objectToArray($data->fields));
                if (!$additionalFields) continue;
                foreach ($additionalFields as $subFieldName => $subFieldParams) {
                    if (isset($entityDefsItem->fields->$subFieldName)) {
                        $data->entityDefs->$entityType->fields->$subFieldName = DataUtil::merge(Util::arrayToObject($subFieldParams), $entityDefsItem->fields->$subFieldName);
                    } else {
                        $data->entityDefs->$entityType->fields->$subFieldName = Util::arrayToObject($subFieldParams);
                    }
                }
            }
        }
    }

    /**
    * Set Metadata data
    * Ex. $key1 = menu, $key2 = Account then will be created a file metadataFolder/menu/Account.json
    *
    * @param  string $key1
    * @param  string $key2
    * @param JSON string $data
    *
    * @return bool
    */
    public function set($key1, $key2, $data)
    {
        $newData = array(
            $key1 => array(
                $key2 => $data,
            ),
        );

        $this->changedData = Util::merge($this->changedData, $newData);
        $this->data = Util::merge($this->getData(), $newData);

        $this->undelete($key1, $key2, $data);
    }

    /**
     * Unset some fields and other stuff in metadat
     *
     * @param  string $key1
     * @param  string $key2
     * @param  array | string $unsets Ex. 'fields.name'
     *
     * @return bool
     */
    public function delete($key1, $key2, $unsets = null)
    {
        if (!is_array($unsets)) {
            $unsets = (array) $unsets;
        }

        switch ($key1) {
            case 'entityDefs':
                //unset related additional fields, e.g. a field with "address" type
                $unsetList = $unsets;
                foreach ($unsetList as $unsetItem) {
                    if (preg_match('/fields\.([^\.]+)/', $unsetItem, $matches) && isset($matches[1])) {
                        $fieldName = $matches[1];
                        $fieldPath = [$key1, $key2, 'fields', $fieldName];

                        $additionalFields = $this->getMetadataHelper()->getAdditionalFieldList($fieldName, $this->get($fieldPath));
                        if (is_array($additionalFields)) {
                            foreach ($additionalFields as $additionalFieldName => $additionalFieldParams) {
                                $unsets[] = 'fields.' . $additionalFieldName;
                            }
                        }
                    }
                }
                break;
        }

        $normalizedData = array(
            '__APPEND__',
        );
        $metadataUnsetData = array();
        foreach ($unsets as $unsetItem) {
            $normalizedData[] = $unsetItem;
            $metadataUnsetData[] = implode('.', array($key1, $key2, $unsetItem));
        }

        $unsetData = array(
            $key1 => array(
                $key2 => $normalizedData
            )
        );

        $this->deletedData = Util::merge($this->deletedData, $unsetData);
        $this->deletedData = Util::unsetInArrayByValue('__APPEND__', $this->deletedData, true);

        $this->data = Util::unsetInArray($this->getData(), $metadataUnsetData, true);
    }

    /**
     * Undelete the deleted items
     *
     * @param  string $key1
     * @param  string $key2
     * @param  array $data
     * @return void
     */
    protected function undelete($key1, $key2, $data)
    {
        if (isset($this->deletedData[$key1][$key2])) {
            foreach ($this->deletedData[$key1][$key2] as $unsetIndex => $unsetItem) {
                $value = Util::getValueByKey($data, $unsetItem);
                if (isset($value)) {
                    unset($this->deletedData[$key1][$key2][$unsetIndex]);
                }
            }
        }
    }

    /**
     * Clear unsaved changes
     *
     * @return void
     */
    public function clearChanges()
    {
        $this->changedData = array();
        $this->deletedData = array();
        $this->init(true);
    }

    /**
     * Save changes
     *
     * @return bool
     */
    public function save()
    {
        $path = $this->paths['customPath'];

        $result = true;
        if (!empty($this->changedData)) {
            foreach ($this->changedData as $key1 => $keyData) {
                foreach ($keyData as $key2 => $data) {
                    if (!empty($data)) {
                        $result &= $this->getFileManager()->mergeContents(array($path, $key1, $key2.'.json'), $data, true);
                    }
                }
            }
        }

        if (!empty($this->deletedData)) {
            foreach ($this->deletedData as $key1 => $keyData) {
                foreach ($keyData as $key2 => $unsetData) {
                    if (!empty($unsetData)) {
                        $rowResult = $this->getFileManager()->unsetContents(array($path, $key1, $key2.'.json'), $unsetData, true);
                        if ($rowResult == false) {
                            $GLOBALS['log']->warning('Metadata items ['.$key1.'.'.$key2.'] can be deleted for custom code only.');
                        }
                        $result &= $rowResult;
                    }
                }
            }
        }

        if ($result == false) {
            throw new Error("Error saving metadata. See log file for details.");
        }

        $this->clearChanges();

        return (bool) $result;
    }

    /**
     * Get Entity path, ex. Core.Entities.Account or Modules\Crm\Entities\MyModule
     *
     * @param string $entityName
     * @param bool $delim - delimiter
     *
     * @return string
     */
    public function getEntityPath($entityName, $delim = '\\')
    {
        $path = $this->getScopePath($entityName, $delim);

        return implode($delim, array($path, 'Entities', Util::normilizeClassName(ucfirst($entityName))));
    }

    public function getRepositoryPath($entityName, $delim = '\\')
    {
        $path = $this->getScopePath($entityName, $delim);

        return implode($delim, array($path, 'Repositories', Util::normilizeClassName(ucfirst($entityName))));
    }

    /**
     * Load modules
     *
     * @return void
     */
    protected function loadModuleList()
    {
        $modules = $this->getFileManager()->getFileList($this->pathToModules, false, '', false);

        $modulesToSort = array();
        if (is_array($modules)) {
            foreach ($modules as $moduleName) {
                if (!empty($moduleName) && !isset($modulesToSort[$moduleName])) {
                    $modulesToSort[$moduleName] = $this->getModuleConfig()->get($moduleName . '.order', $this->defaultModuleOrder);
                }
            }
        }

        array_multisort(array_values($modulesToSort), SORT_ASC, array_keys($modulesToSort), SORT_ASC, $modulesToSort);

        $this->moduleList = array_keys($modulesToSort);
    }

    /**
     * Get Module List
     *
     * @return array
     */
    public function getModuleList()
    {
        if (!isset($this->moduleList)) {
            $this->loadModuleList();
        }

        return $this->moduleList;
    }

    /**
     * Get module name if it's a custom module or empty string for core entity
     *
     * @param string $scopeName
     *
     * @return string
     */
    public function getScopeModuleName($scopeName)
    {
        return $this->get('scopes.' . $scopeName . '.module', false);
    }

    /**
     * Get Scope path, ex. "Modules/Crm" for Account
     *
     * @param string $scopeName
     * @param string $delim - delimiter
     *
     * @return string
     */
    public function getScopePath($scopeName, $delim = '/')
    {
        $moduleName = $this->getScopeModuleName($scopeName);

        $path = ($moduleName !== false) ? 'Core/Modules/'.$moduleName : 'Core';

        if ($delim != '/') {
           $path = str_replace('/', $delim, $path);
        }

        return $path;
    }

    /**
     * Clear metadata variables when reload meta
     *
     * @return void
     */
    protected function clearVars()
    {
        $this->data = null;
        $this->moduleList = null;
    }
}

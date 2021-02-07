<?php
namespace Gurucomkz\EagerLoading;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * Replaces DataList when EagerLoading is used. Fetches data when the main query is actually executed.
 * Appends related objects when a DataObject is actually created.
 */
class EagerLoadedDataList extends DataList{

    const ID_LIMIT = 5000;
    public $withList = [];
    public $_relatedMaps = [
        'has_one' => [],
        'has_many' => [],
        'many_many' => [],
    ];

    public function __construct($classOrList)
    {
        if(is_string($classOrList)) {
            parent::__construct($classOrList);
        } else {
            parent::__construct($classOrList->dataClass());
            $this->dataQuery = $classOrList->dataQuery();
        }

    }
    public $_relatedCache = [];
    public static function cloneFrom(DataList $list)
    {
        $clone = new EagerLoadedDataList($list);

        $clone->withList = $list->withList;
        $clone->_relatedCache = $list->_relatedCache;
        return $clone;
    }

    public static function extractField($arr,$field)
    {
        $result = [];
        foreach($arr as $record){
            $result[is_object($record) ? $record->ID : $record['ID']] = is_object($record) ? $record->$field : $record[$field];
        }
        return $result;
    }
    public static function reverseMap($arr)
    {
        $result = [];
        foreach($arr as $k => $v){
            if(!isset($result[$v])) $result[$v] = [];
            $result[$v][] = $k;
        }
        return $result;
    }

    /**
     * Create a DataObject from the given SQL row
     *
     * @param array $row
     * @return DataObject
     */
    public function createDataObject($row)
    {
        $this->prepareEagerRelations();
        $item = parent::createDataObject($row);

        $this->fulfillEagerRelations($item);
        return $item;
    }

    private $relationsPrepared = false;

    public function prepareEagerRelations() {
        if($this->relationsPrepared) return;
        $this->relationsPrepared = true;
        $localClass = $this->dataClass();
        $config = Config::forClass($localClass);
        $hasOnes = (array)$config->get('has_one');
        $hasManys = (array)$config->get('has_many');
        $manyManys = (array)$config->get('many_many');

        //collect has_ones
        $withHasOnes = array_filter($this->withList,function($dep)use($hasOnes){ return array_key_exists($dep[0],$hasOnes); });
        $withHasManys = array_filter($this->withList,function($dep)use($hasManys){ return array_key_exists($dep[0],$hasManys); });
        $withManyManys = array_filter($this->withList,function($dep)use($manyManys){ return array_key_exists($dep[0],$manyManys); });

        if(!count($withHasOnes) && !count($withHasManys) && !count($withManyManys)){
            // do nothing if no matches
            /** @todo report errors */
            return;
        }

        $data = $this->column('ID');
        if(count($withHasOnes)){
            $this->_prepareCache($hasOnes, $withHasOnes);
            $this->eagerLoadHasOne($data, $hasOnes, $withHasOnes);
        }
        if(count($withHasManys)){
            $this->_prepareCache($hasManys, $withHasManys);
            $this->eagerLoadHasMany($data, $hasManys, $withHasManys);
        }
        if(count($withManyManys)){
            $this->_prepareCache($manyManys, $withManyManys);
            $this->eagerLoadManyMany($data, $manyManys, $withManyManys);
        }

    }

    public function eagerLoadHasOne(&$ids, $hasOnes, $withHasOnes)
    {
        $schema = DataObject::getSchema();

        //collect required IDS
        $fields = ['ID'];
        foreach($withHasOnes as $depSeq) {
            $dep = $depSeq[0];
            $fields[] = "{$dep}ID";
        }
        $table = Config::forClass($this->dataClass)->get('table_name');
        $data = new SQLSelect(implode(',',$fields),[$table],["ID IN (".implode(',',$ids).")"]);
        $data = self::EnsureArray($data->execute(),'ID');

        foreach($withHasOnes as $depSeq) {
            $dep = $depSeq[0];
            $depClass = $hasOnes[$dep];

            $descriptor = [
                'class' => $depClass,
                'localField' => "{$dep}ID",
                'map' => [],
            ];

            $component = $schema->hasOneComponent($this->dataClass, $dep);

            $descriptor['map'] = self::extractField($data,$descriptor['localField']);
            $uniqueIDs = array_unique($descriptor['map']);
            while(count($uniqueIDs)) {
                $IDsubset = array_splice($uniqueIDs,0,self::ID_LIMIT);
                $result = DataObject::get($depClass)->filter('ID',$IDsubset);
                if(count($depSeq)>1){
                    $result = $result
                        ->with(implode('.',array_slice($depSeq,1)));
                }

                foreach($result as $depRecord) {
                    $this->_relatedCache[$depClass][$depRecord->ID] = $depRecord;
                }
            }

            $this->_relatedMaps['has_one'][$dep] = $descriptor;

        }
    }

    public function eagerLoadHasMany($data, $hasManys, $withHasManys)
    {
        $localClass = $this->dataClass();
        $localClassTail = basename(str_replace('\\','/',$localClass));

        foreach($withHasManys as $depSeq) {
            $dep = $depSeq[0];
            $depClass = $hasManys[$dep];
            $localNameInDep = $localClassTail;
            $depKey = "{$localNameInDep}ID";
            $descriptor = [
                'class' => $depClass,
                'remoteRelation' => $localNameInDep,
                'remoteField' => $depKey,
                'map' => [],
            ];
            $result = DataObject::get($depClass)->filter($depKey,$data);
            if(count($depSeq)>1){
                $result = $result
                    ->with(implode('.',array_slice($depSeq,1)));
            }

            $collection = [];

            foreach($data as $localRecordID){
                $collection[$localRecordID] = [];
            }
            foreach($result as $depRecord) {

                $this->_relatedCache[$depClass][$depRecord->ID] = $depRecord;
                $collection[$depRecord->$depKey][] = $depRecord->ID;
            }
            $descriptor['map'] = $collection;
            $this->_relatedMaps['has_many'][$dep] = $descriptor;

        }
    }

    public function eagerLoadManyMany(&$data, $manyManys, $withManyManys)
    {
        $localClass = $this->dataClass();
        $schema = DataObject::getSchema();

        foreach($withManyManys as $depSeq) {
            $dep = $depSeq[0];
            $depClass = $manyManys[$dep];

            $component = $schema->manyManyComponent($localClass, $dep);

            $descriptor = [
                'class' => $depClass,
                'map' => [],
            ];

            $idsQuery = SQLSelect::create(
                implode(',',[$component['childField'],$component['parentField']]),
                $component['join'],
                [
                    $component['parentField'].' IN (' . implode(',',$data).')'
                ]
                )->execute();

            $collection = [];
            $relListReverted = [];
            foreach($idsQuery as $row){
                $relID = $row[$component['childField']];
                $localID = $row[$component['parentField']];
                if(!isset($collection[$localID])) $collection[$localID] = [];
                $collection[$localID][] = $relID;
                $relListReverted[$relID] = 1;//use ids as keys to avoid
            }

            $result = DataObject::get($depClass)->filter('ID',array_keys($relListReverted));
            if(count($depSeq)>1){
                $result = $result
                    ->with(implode('.',array_slice($depSeq,1)));
            }

            foreach($result as $depRecord) {
                $this->_relatedCache[$depClass][$depRecord->ID] = $depRecord;
            }

            $descriptor['map'] = $collection;
            $this->_relatedMaps['has_many'][$dep] = $descriptor;

        }

    }


    public function fulfillEagerRelations(DataObject $item)
    {
        foreach($this->_relatedMaps['has_one'] as $dep => $depInfo){
            $depClass = $depInfo['class'];
            if(isset($depInfo['map'][$item->ID])) {
                $depID = $depInfo['map'][$item->ID];
                if(isset($this->_relatedCache[$depClass][$depID]))
                {
                    $depRecord = $this->_relatedCache[$depClass][$depID];
                    $item->setComponent($dep, $depRecord);
                }
            }
        }

        foreach($this->_relatedMaps['has_many'] as $dep => $depInfo){
            $depClass = $depInfo['class'];
            $collection = [];
            if(isset($depInfo['map'][$item->ID])){
                foreach($depInfo['map'][$item->ID] as $depID){
                    if(isset($this->_relatedCache[$depClass][$depID]))
                    {
                        $depRecord = $this->_relatedCache[$depClass][$depID];
                        $collection[] = $depRecord;
                    }
                }
            }
            if(!method_exists($item,'addEagerRelation')) {
                throw new \Exception("Model {$item->ClassName} must include Gurucomkz\EagerLoading\EagerLoaderMultiAccessor trait to use eager loading for \$has_many");
            }
            $item->addEagerRelation($dep, $collection);
        }

        foreach($this->_relatedMaps['many_many'] as $dep => $depInfo){
            $depClass = $depInfo['class'];
            $collection = [];
            if(isset($depInfo['map'][$item->ID])){
                foreach($depInfo['map'][$item->ID] as $depIDlist){
                    foreach($depIDlist as $depID){
                        if(isset($this->_relatedCache[$depClass][$depID]))
                        {
                            $depRecord = $this->_relatedCache[$depClass][$depID];
                            $collection[] = $depRecord;
                        }
                    }
                }
            }
            if(!method_exists($item,'addEagerRelation')) {
                throw new \Exception("Model {$item->ClassName} must include Gurucomkz\EagerLoading\EagerLoaderMultiAccessor trait to use eager loading for \$many_many");
            }
            $item->addEagerRelation($dep, $collection);
        }

    }
    /**
     * Returns a generator for this DataList
     *
     * @return \Generator&DataObject[]
     */
    public function getGenerator()
    {
        $query = $this->query()->execute();

        while ($row = $query->record()) {
            yield $this->createDataObject($row);
        }
    }

    private function _prepareCache($all,$selected)
    {
        foreach($selected as $depSeq) {
            $dep = $depSeq[0];
            $depClass = $all[$dep];
            if(!isset($this->_relatedCache[$depClass])) { $this->_relatedCache[$depClass] = []; }
        }
    }


    public static function EnsureArray($arr, $kfield = null)
    {
        if(is_array($arr)) return $arr;
        $result = [];
        foreach($arr as $k => $v){
            $key = $k;
            if($kfield!==null) {
                if(is_array($v) && isset($v[$kfield])) $key = $v[$kfield];
                elseif(is_object($v) && isset($v->$kfield)) $key = $v->$kfield;
            }
            $result[$key] = $v;
        }
        return $result;
    }
}

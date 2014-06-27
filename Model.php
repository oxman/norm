<?php

namespace Norm;


class Model
{


    private static $_metadata;
    private static $_staticQuery;
    private static $_setted = array();

    public static function getSetted()
    {
        return self::$_setted;

    }

    protected static function _getQuery()
    {

        return new Query();

    }


    public static function setMetadata(Metadata $metadata)
    {

        self::$_metadata = $metadata;

    }


    public static function getMetadata()
    {

        if (isset(self::$_metadata) === false) {
            return Metadata::getInstance();
        } else {
            return self::$_metadata;
        }

    }


    public static function staticSetQuery(Query $query)
    {

        self::$_staticQuery = $query;

    }


    public static function staticGetQuery()
    {

        if (isset(self::$_staticQuery) === false) {
            return new Query();
        } else {
            return self::$_staticQuery;
        }

    }


    public function setQuery(Query $query)
    {

        $this->query = $query;

    }


    public function getQuery()
    {

        if (isset($this->query) === false) {
            return new Query();
        } else {
            return $this->query;
        }

    }


    public static function all()
    {

        $metadata = self::getMetadata();
        $table = $metadata->getTable(get_called_class());
        $q = self::staticGetQuery();
        $q->from($table);
        return $q;

    }


    public static function __callStatic($name, $value)
    {

        if (substr($name, 0, 5) === 'getBy') {
            $action = 'get';
            $name = lcfirst(substr($name, 5));
        } elseif (substr($name, 0, 6) === 'findBy') {
            $action = 'find';
            $name = lcfirst(substr($name, 6));
        } else {
            throw new \Exception('Call to undefined method ' . get_called_class() . '::' . $name . '()');
        }

        $names = preg_split('/And(?<![A-Z])/', $name);

        $metadata = self::getMetadata();
        $table = $metadata->getTable(get_called_class());

        if ($table === null) {
            throw new \Exception('Can\'t find sql table name for ' . get_called_class());
        }

        $q = self::staticGetQuery();
        $q->from($table);

        $conditions = array();
        $values = array();

        foreach($names as $i => $name) {
            $columnInfo = $metadata->getColumnByName($table, lcfirst($name));
            $conditions[] = $columnInfo['key'] . ' = :' . $columnInfo['key'];
            $values = array_merge($values, array(':' . $columnInfo['key'] => $value[$i]));
        }

        $q->where(implode(' AND ', $conditions), $values, false);

        if ($action === 'get') {
            return $q->first();
        } else {
            return $q;
        }

    }


    public function __call($name, $value)
    {

        $action = substr($name, 0, 3);

        // if the method doesn't start with set or get, we remap the call in get
        // feature for Twig
        if ($action !== 'set' && $action !== 'get') {
            return $this->{'get' . ucfirst($name)}();
        }

        $name = substr($name, 3);

        if (strtoupper($name) !== $name) {
            $name = lcfirst($name);
        }

        $name = $this->_findExistingProperty($name);
        $metadata = self::getMetadata();
        $table  = $metadata->getTable(get_called_class());
        $columnInfo = $metadata->getColumnByName($table, $name);

        if ($columnInfo === null) {
            $type = 'auto';
        } else {
            $type = $columnInfo['type'];
            $backtrace = debug_backtrace();

            //We keep only setted from user's set, others came from object initialisation
            list(, $caller) = debug_backtrace(false);

            if ($action === 'set' && basename($caller["file"]) !== 'Metadata.php') {
                array_push(self::$_setted, $columnInfo["name"]);
            }
            
        }

        if ($action === 'set') {

            $lazyNullForEmpty = false;

            //allow to transform empty value to null
            if (isset($value[1]) === true) {
                $lazyNullForEmpty = $value[1];
            }

            $this->$name = $this->_cast($value[0], $type, $lazyNullForEmpty);
            return $this;
        } else {
            if (property_exists($this, $name) === false) {
                throw new \Exception('Undefined property: ' . get_called_class() . '::' . $name);
            }

            return $this->_cast($this->$name, $type);
        }

    }


    // Find property in the following order : _myProperty, myProperty, _my_property, my_property, $name
    protected function _findExistingProperty($name)
    {

        $nameUnderscore = strtolower(preg_replace('/([A-Z])/', '_$1', $name));

        if (property_exists($this, '_' . $name) === true) {
            return '_' . $name;
        } else if (property_exists($this, $name) === true) {
            return $name;
        } else if (property_exists($this, '_' . $nameUnderscore) === true) {
            return '_' . $nameUnderscore;
        } else if (property_exists($this, $nameUnderscore) === true) {
            return $nameUnderscore;
        }

        return $name;

    }


    protected function _cast($value, $type, $isNullForEmpty = false)
    {

        if (is_object($value) === true) {
            return $value;
        }

        if ($value === null or ($value === '' && $isNullForEmpty === true)) {
            return null;
        }

        switch ($type) {
            case 'int':
                return intval($value);
                break;

            case 'double':
                return floatval($value);
                break;

            case 'datetime':
                return new \Datetime($value);
                break;

            default:
                return $value;
                break;
        }

    }


    public function update()
    {

        return $this->_save('update');

    }


    public function save()
    {

        return $this->_save('insert');

    }


    public function delete()
    {

        $class    = get_called_class();
        $metadata = self::getMetadata();
        $table    = $metadata->getTable($class);
        $column   = $metadata->getPrimary($table);

        if ($column === null) {
            $this->_throwNoPrimary('delete');
        }

        $q = $this->getQuery();
        return $q->delete($table)->where($column['key'] . ' = :' . $column['key'],
            array(':' . $column['key'] => $this->{$column['params']['name']}))->execute();

    }


    protected function _save($mode)
    {

        $class    = get_called_class();
        $metadata = self::getMetadata();
        $table    = $metadata->getTable($class);
        $column   = $metadata->getPrimary($table);

        $properties = get_object_vars($this);
        $columns = array();

        foreach(self::$_setted as $name) {

            $value = $properties[$name];
            $columnInfo = $metadata->getColumnByName($table, $name);

            if (isset($columnInfo['key']) === true) {
                $columns[$columnInfo['key']] = $value;
            }
        }

        $q = $this->getQuery();

        $columns = array_replace($columns,
                        array_fill_keys(
                            array_keys($columns, null, true), new \Norm\Raw('null')
                        )
                    );

        if ($mode === 'insert') {

            $id = $q->insert($table)
                    ->set($columns)
                    ->execute();
        } else {
            if ($column === null) {
                $this->_throwNoPrimary('update');
            }

            if ($this->$column['params']['name'] === null) {
                throw new \Exception('Can\'t update : primary is null');
            }

            unset($columns[$column['key']]);
            
            // return is not an id
            $id = $q->update($table)
                    ->set($columns)
                    ->where($column['key'] . ' = :' . $column['key'], array(':' . $column['key'] => $this->$column['params']['name']))
                    ->execute();
        }

        self::$_setted = array();

        if (is_numeric($id) === true) {
            if ($mode === 'insert') {
                if ($column !== null) {
                    $this->$column['params']['name'] = $id;
                }
            }
            return true;
        } else {
            return false;
        }

    }


    protected function _throwNoPrimary($action) {
        throw new \Exception('Can\'t ' . $action . ' : no primary defined in model');
    }


    public function __toString()
    {

        $class    = get_called_class();
        $metadata = self::getMetadata();
        $table    = $metadata->getTable($class);
        $column   = $metadata->getPrimary($table);

        if ($column === null) {
            return $class;
        }

        return $class . '#' . $this->$column['params']['name'];

    }


}
<?php

namespace Admingenerator\GeneratorBundle\Guesser;

use Admingenerator\GeneratorBundle\Exception\NotImplementedException;
use Doctrine\Common\Util\Inflector;
use Symfony\Component\DependencyInjection\ContainerAware;

class PropelORMFieldGuesser extends ContainerAware
{
    private $guessRequired;

    private $defaultRequired;

    private $cache = array();

    private static $current_class;

    protected function getMetadatas($class = null)
    {
        if ($class) {
            self::$current_class = $class;
        }

        return $this->getTable(self::$current_class);
    }

    public function getAllFields($class)
    {
        $return = array();

        foreach ($this->getMetadatas($class)->getColumns() as $column) {
            $return[] = Inflector::tableize($column->getPhpName());
        }

        return $return;
    }

    /**
     * Find out the database type for given model field path.
     *
     * @param  string $model     The starting model.
     * @param  string $fieldPath The field path.
     * @return string The leaf field's primary key.
     */
    public function getDbType($model, $fieldPath)
    {
        $resolved = $this->resolveRelatedField($model, $fieldPath);
        $class = $resolved['class'];
        $field = $resolved['field'];

        $relation = $this->getRelation($field, $class);

        if ($relation) {
            return \RelationMap::MANY_TO_ONE === $relation->getType() ? 'model' : 'collection';
        }

        $column = $this->getColumn($class, $field);

        return $column ? $column->getType() : 'virtual';
    }

    protected function getRelation($fieldName, $class = null)
    {
        $table = $this->getMetadatas($class);
        $relName = Inflector::classify($fieldName);

        foreach ($table->getRelations() as $relation) {
            if ($relName === $relation->getName() || $relName === $relation->getPluralName()) {
                return $relation;
            }
        }

        return false;
    }

    public function getPhpName($class, $fieldName)
    {
        $column = $this->getColumn($class, $fieldName);

        if ($column) {
            return $column->getPhpName();
        }
    }

    public function getSortType($dbType)
    {
        $alphabeticTypes = array(
            \PropelColumnTypes::CHAR,
            \PropelColumnTypes::VARCHAR,
            \PropelColumnTypes::LONGVARCHAR,
            \PropelColumnTypes::BLOB,
            \PropelColumnTypes::CLOB,
            \PropelColumnTypes::CLOB_EMU,
        );

        $numericTypes = array(
            \PropelColumnTypes::FLOAT,
            \PropelColumnTypes::REAL,
            \PropelColumnTypes::DOUBLE,
            \PropelColumnTypes::DECIMAL,
            \PropelColumnTypes::TINYINT,
            \PropelColumnTypes::SMALLINT,
            \PropelColumnTypes::INTEGER,
            \PropelColumnTypes::BIGINT,
            \PropelColumnTypes::NUMERIC,
        );

        if (in_array($dbType, $alphabeticTypes)) {
            return 'alphabetic';
        }

        if (in_array($dbType, $numericTypes)) {
            return 'numeric';
        }

        return 'default';
    }

    public function getFormType($dbType, $columnName)
    {
        $config = $this->container->getParameter('admingenerator.propel_form_types');
        $formTypes = array();

        foreach ($config as $key => $value) {
            // if config is all uppercase use it to retrieve \PropelColumnTypes
            // constant, otherwise use it literally
            if ($key === strtoupper($key)) {
                $key = constant('\PropelColumnTypes::'.$key);
            }

            $formTypes[$key] = $value;
        }

        if (array_key_exists($dbType, $formTypes)) {
            return $formTypes[$dbType];
        } elseif ('virtual' === $dbType) {
            return 'virtual_form';
        } else {
            throw new NotImplementedException(
                'The dbType "'.$dbType.'" is not yet implemented '
                .'(column "'.$columnName.'" in "'.self::$current_class.'")'
            );
        }
    }

    public function getFilterType($dbType, $columnName)
    {
        $config = $this->container->getParameter('admingenerator.propel_filter_types');
        $filterTypes = array();

        foreach ($config as $key => $value) {
            // if config is all uppercase use it to retrieve \PropelColumnTypes
            // constant, otherwise use it literally
            if ($key === strtoupper($key)) {
                $key = constant('\PropelColumnTypes::'.$key);
            }

            $filterTypes[$key] = $value;
        }

        if (array_key_exists($dbType, $filterTypes)) {
            return $filterTypes[$dbType];
        } elseif ('virtual' === $dbType) {
            return 'virtual_filter';
        } else {
           throw new NotImplementedException(
               'The dbType "'.$dbType.'" is not yet implemented '
               .'(column "'.$columnName.'" in "'.self::$current_class.'")'
           );
       }
    }

    public function getFormOptions($formType, $dbType, $columnName)
    {
        if ('virtual' === $dbType) {
            return array();
        }

        if ((\PropelColumnTypes::BOOLEAN == $dbType || \PropelColumnTypes::BOOLEAN_EMU == $dbType) &&
            (preg_match("#^choice#i", $formType) || preg_match("#choice$#i", $formType))) {
            return array(
                'choices' => array(
                   0 => $this->container->get('translator')->trans('boolean.no', array(), 'Admingenerator'),
                   1 => $this->container->get('translator')->trans('boolean.yes', array(), 'Admingenerator')
                ),
                'empty_value' => $this->container->get('translator')->trans('boolean.yes_or_no', array(), 'Admingenerator')
            );
        }

        if (preg_match("#^model#i", $formType) || preg_match("#model$#i", $formType)) {
            $relation = $this->getRelation($columnName);
            if ($relation) {
                if (\RelationMap::MANY_TO_ONE === $relation->getType()) {
                    return array(
                        'class'     => $relation->getForeignTable()->getClassname(),
                        'multiple'  => false,
                    );
                } else {
                    return array(
                        'class'     => $relation->getLocalTable()->getClassname(),
                        'multiple'  => false,
                    );
                }
            }
        }

        if (preg_match("#^collection#i", $formType) || preg_match("#collection$#i", $formType)) {
            return array(
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
            );
        }

        if (\PropelColumnTypes::ENUM == $dbType) {
            $valueSet = $this->getMetadatas()->getColumn($columnName)->getValueSet();

            return array(
                'required' => $this->isRequired($columnName),
                'choices'  => array_combine($valueSet, $valueSet),
            );
        }

        return array('required' => $this->isRequired($columnName));
    }

    protected function isRequired($fieldName)
    {
        if (!isset($this->guessRequired) || !isset($this->defaultRequired)) {
            $this->guessRequired = $this->container->getParameter('admingenerator.guess_required');
            $this->defaultRequired = $this->container->getParameter('admingenerator.default_required');
        }

        if (!$this->guessRequired) {
            return $this->defaultRequired;
        }

        $column = $this->getColumn(self::$current_class, $fieldName);

        return $column ? $column->isNotNull() : false;
    }

    public function getFilterOptions($formType, $dbType, $ColumnName)
    {
        $options = array('required' => false);

        if (\PropelColumnTypes::BOOLEAN == $dbType || \PropelColumnTypes::BOOLEAN_EMU == $dbType) {
            $options['choices'] = array(
               0 => $this->container->get('translator')
                        ->trans('boolean.no', array(), 'Admingenerator'),
               1 => $this->container->get('translator')
                        ->trans('boolean.yes', array(), 'Admingenerator')
            );
            $options['empty_value'] = $this->container->get('translator')
                ->trans('boolean.yes_or_no', array(), 'Admingenerator');
        }

        if (\PropelColumnTypes::ENUM == $dbType) {
            $valueSet = $this->getMetadatas()->getColumn($ColumnName)->getValueSet();

            return array(
                'required' => false,
                'choices'  => array_combine($valueSet, $valueSet),
            );
        }

        if (preg_match("#^model#i", $formType) || preg_match("#model$#i", $formType)) {
            return array_merge(
                $this->getFormOptions($formType, $dbType, $ColumnName),
                $options
            );
        }

        if (preg_match("#^collection#i", $formType) || preg_match("#collection$#i", $formType)) {
            return array_merge(
                $this->getFormOptions($formType, $dbType, $ColumnName),
                $options
            );
        }

        return $options;
    }

    /**
     * Find the pk name
     */
    public function getModelPrimaryKeyName($class)
    {
        $pks = $this->getMetadatas($class)->getPrimaryKeyColumns();

        if (count($pks) == 1) {
            return $pks[0]->getPhpName();
        }

        throw new \LogicException('No valid primary keys found');
    }

    protected function getTable($class)
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        if (class_exists($queryClass = $class.'Query')) {
            $query = new $queryClass();

            return $this->cache[$class] = $query->getTableMap();
        }

        throw new \LogicException('Can\'t find query class '.$queryClass);
    }

    protected function getColumn($class, $property)
    {
        if (isset($this->cache[$class.'::'.$property])) {
            return $this->cache[$class.'::'.$property];
        }

        $table = $this->getTable($class);

        if ($table && $table->hasColumn($property)) {
            return $this->cache[$class.'::'.$property] = $table->getColumn($property);
        } else {
            foreach ($table->getColumns() as $column) {
                $tabelized = Inflector::tableize($column->getPhpName());
                if ($tabelized === $property || $column->getPhpName() === ucfirst($property)) {
                    return $this->cache[$class.'::'.$property] = $column;
                }
            }
        }
    }

    /**
     * Find out the primary key for given model field path.
     *
     * @param  string $model     The starting model.
     * @param  string $fieldPath The field path.
     * @return string The leaf field's primary key.
     */
    public function getPrimaryKeyFor($model, $fieldPath)
    {
        $resolved = $this->resolveRelatedField($model, $fieldPath);
        $class = $resolved['class'];
        $field = $resolved['field'];

        if (!$relation = $this->getRelation($field, $class)) {
            $class = $relation->getName();

            return $this->getModelPrimaryKeyName($class);
        } else {
            // if the leaf node is not an association
            return null;
        }
    }

    /**
     * Resolve field path for given model to class and field name.
     *
     * @param  string $model     The starting model.
     * @param  string $fieldPath The field path.
     * @return array  An array containing field and class information.
     */
    private function resolveRelatedField($model, $fieldPath)
    {
        $path = explode('.', $fieldPath);
        $field = array_pop($path);
        $class = $model;

        foreach ($path as $part) {
            if (!$relation = $this->getRelation($part, $class)) {
                throw new \LogicException('Field "'.$part.'" for class "'.$class.'" is not an association.');
            }

            $class = $relation->getName();
        }

        return array(
            'field' => $field,
            'class' => $class
        );
    }
}

<?php

/*
 * The MIT License
 *
 * Copyright (c) 2010 Johannes Mueller <circus2(at)web.de>
 * Copyright (c) 2012-2014 Toha <tohenk@yahoo.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace MwbExporter\Formatter\Doctrine2\Annotation\Model;

use MwbExporter\Formatter\Doctrine2\Annotation\Formatter;
use MwbExporter\Formatter\Doctrine2\Annotation\OnCorps;
use MwbExporter\Formatter\Doctrine2\Annotation\OnCorps\Assertion\ClassLevelAssertionBuilderProvider;
use MwbExporter\Formatter\Doctrine2\Annotation\OnCorps\Assertion\PropertyLevelAssertionBuilderProvider;
use MwbExporter\Formatter\Doctrine2\CustomComment;
use MwbExporter\Formatter\Doctrine2\Model\Table as BaseTable;
use MwbExporter\Helper\Comment;
use MwbExporter\Helper\ReservedWords;
use MwbExporter\Model\ForeignKey;
use MwbExporter\Object\Annotation;
use MwbExporter\Writer\WriterInterface;

class Table extends BaseTable
{
    protected $ormPrefix = null;
    protected $collectionClass = 'Doctrine\Common\Collections\ArrayCollection';
    protected $collectionInterface = 'Doctrine\Common\Collections\Collection';
    protected $relationVarNames = [];

    /**
     * Get the array collection class name.
     *
     * @param bool $useFQCN return full qualified class name
     * @return string
     */
    public function getCollectionClass($useFQCN = true)
    {
        $class = $this->collectionClass;
        if (!$useFQCN && count($array = explode('\\', $class))) {
            $class = array_pop($array);
        }

        return $class;
    }

    /**
     * Get collection interface class name.
     *
     * @param bool $absolute Use absolute class name
     * @return string
     */
    public function getCollectionInterface($absolute = true)
    {
        return ($absolute ? '\\' : '').$this->collectionInterface;
    }

    /**
     * Get annotation prefix.
     *
     * @param string $annotation Annotation type
     * @param string $prefix Prefix override
     * @return string
     */
    public function addPrefix($annotation = null, $prefix = null)
    {
        if (null === $this->ormPrefix) {
            $this->ormPrefix = '@'.$this->getConfig()->get(Formatter::CFG_ANNOTATION_PREFIX);
        }
        $prefix = $prefix ?? $this->ormPrefix;

        return $prefix.($annotation ? $annotation : '');
    }

    /**
     * Quote identifier if necessary.
     *
     * @param string $value  The identifier to quote
     * @return string
     */
    public function quoteIdentifier($value)
    {
        $quote = false;
        switch ($this->getConfig()->get(Formatter::CFG_QUOTE_IDENTIFIER_STRATEGY)) {
            case Formatter::QUOTE_IDENTIFIER_AUTO:
                $quote = ReservedWords::isReserved($value);
                break;

            case Formatter::QUOTE_IDENTIFIER_ALWAYS:
                $quote = true;
                break;
        }

        return $quote ? '`'.$value.'`' : $value;
    }

    /**
     * Get annotation object.
     *
     * @param string $annotation  The annotation name
     * @param mixed  $content     The annotation content
     * @param array  $options     The annotation options
     * @param string $prefix      A prefix override if not the standard one
     * @return \MwbExporter\Object\Annotation
     */
    public function getAnnotation($annotation, $content = null, $options = array(), $prefix = null)
    {
        return new Annotation($this->addPrefix($annotation, $prefix), $content, $options);
    }

    /**
     * Get indexes annotation.
     *
     * @param string $type Index annotation type, Index or UniqueConstraint
     * @return array|null
     */
    protected function getIndexesAnnotation($type = 'Index')
    {
        $indices = array();
        foreach ($this->getTableIndices() as $index) {
            switch (true) {
                case $type === 'Index' && $index->isIndex():
                case $type === 'UniqueConstraint' && $index->isUnique():
                    $columns = array();
                    foreach ($index->getColumns() as $column) {
                        $columns[] = $this->quoteIdentifier($column->getColumnName());
                    }
                    $indices[] = $this->getAnnotation($type, array('name' => $index->getName(), 'columns' => $columns));
                    break;

                default:
                    break;
            }
        }

        return count($indices) ? $indices : null;
    }

    /**
     * Get join annotation.
     *
     * @param string $joinType    Join type
     * @param string $entity      Entity name
     * @param string $mappedBy    Column mapping
     * @param string $inversedBy  Reverse column mapping
     * @return \MwbExporter\Object\Annotation
     */
    public function getJoinAnnotation($joinType, $entity, $mappedBy = null, $inversedBy = null)
    {
        return $this->getAnnotation($joinType, array('targetEntity' => $entity, 'mappedBy' => $mappedBy, 'inversedBy' => $inversedBy));
    }

    /**
     * Get foreign key join annotation. If foreign key is composite
     * JoinColumns returned, otherwise JoinColumn returned.
     *
     * @param \MwbExporter\Model\ForeignKey $fkey  Foreign key
     * @param boolean $owningSide  Is join for owning side or vice versa
     * @return \MwbExporter\Object\Annotation
     */
    protected function getJoins(ForeignKey $fkey, $owningSide = true)
    {
        $joins = array();
        $lcols = $owningSide ? $fkey->getForeigns() : $fkey->getLocals();
        $fcols = $owningSide ? $fkey->getLocals() : $fkey->getForeigns();
        $onDelete = $this->getFormatter()->getDeleteRule($fkey->getParameters()->get('deleteRule'));
        for ($i = 0; $i < count($lcols); $i++) {
            $joins[] = $this->getAnnotation('JoinColumn', array(
                'name'                  => $this->quoteIdentifier($lcols[$i]->getColumnName()),
                'referencedColumnName'  => $this->quoteIdentifier($fcols[$i]->getColumnName()),
                'nullable'              => $lcols[$i]->getNullableValue(true),
                'onDelete'              => $onDelete,
            ));
        }
        return count($joins) > 1 ? $this->getAnnotation('JoinColumns', array($joins), array('multiline' => true, 'wrapper' => ' * %s')) : $joins[0];
    }

    public function writeTable(WriterInterface $writer)
    {
        switch (true) {
            case $this->isExternal():
                return self::WRITE_EXTERNAL;

            case $this->getConfig()->get(Formatter::CFG_SKIP_M2M_TABLES) && $this->isManyToMany():
                return self::WRITE_M2M;

            default:
                $this->writeEntity($writer);
                return self::WRITE_OK;
        }
    }

    protected function writeEntity(WriterInterface $writer)
    {
        $this->getDocument()->addLog(sprintf('Writing table "%s"', $this->getModelName()));

        if ($repositoryNamespace = $this->getConfig()->get(Formatter::CFG_REPOSITORY_NAMESPACE)) {
            $repositoryNamespace .= '\\';
        }
        $skipGetterAndSetter = $this->getConfig()->get(Formatter::CFG_SKIP_GETTER_SETTER);
        $serializableEntity  = $this->getConfig()->get(Formatter::CFG_GENERATE_ENTITY_SERIALIZATION);
        $extendableEntity    = $this->getConfig()->get(Formatter::CFG_GENERATE_EXTENDABLE_ENTITY);
        $extendableEntityHasDiscriminator = $this->getConfig()->get(Formatter::CFG_EXTENDABLE_ENTITY_HAS_DISCRIMINATOR);
        $useBehavioralExtensions = $this->getConfig()->get(Formatter::CFG_USE_BEHAVIORAL_EXTENSIONS);
        $lifecycleCallbacks  = $this->getLifecycleCallbacks();
        $cacheMode           = $this->getEntityCacheMode();

        $namespace = $this->getEntityNamespace().($extendableEntity ? '\\Base' : '');

        $extendsClass = $this->getClassToExtend();
        $implementsInterface = $this->getInterfaceToImplement();

        $hasDeletableBehaviour = false;
        $hasTimestampableBehaviour = false;
        if ($useBehavioralExtensions) {
            foreach ($this->getColumns() as $column) {
                if ($column->getColumnName() === 'deleted_at') {
                    $hasDeletableBehaviour = true;
                } elseif ($column->getColumnName() === 'created_at') {
                    $hasTimestampableBehaviour = true;
                } elseif ($column->getColumnName() === 'updated_at') {
                    $hasTimestampableBehaviour = true;
                }
            }
        }

        $comment = $this->getComment();
        $writer
            ->open($this->getClassFileName($extendableEntity ? true : false))
            ->write('<?php')
            ->write('')
            ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                if ($_this->getConfig()->get(Formatter::CFG_ADD_COMMENT)) {
                    $writer
                        ->write($_this->getFormatter()->getComment(Comment::FORMAT_PHP))
                        ->write('')
                    ;
                }
            })
            ->write('namespace %s;', $namespace)
            ->write('')
            ->writeIf($useBehavioralExtensions &&
                (strstr($this->getClassName($extendableEntity), 'Img') || $hasDeletableBehaviour || $hasTimestampableBehaviour),
                'use Gedmo\Mapping\Annotation as Gedmo;')
            ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                $_this->writeUsedClasses($writer);
            })
            ->write('/**')
            ->write(' * '.$this->getNamespace(null, false))
            ->write(' *')
            ->writeIf($comment, $comment);

        $apiPlatformManager = new OnCorps\ApiPlatformManager($this);

        $apiResourceAnnotations = $apiPlatformManager->getApiResourceAnnotations();
        if($apiResourceAnnotations) {
            foreach($apiResourceAnnotations as $apiResourceAnnotation) {
                $writer->write(' * ' . $apiResourceAnnotation);
            }
        }

        $filterAnnotations = $apiPlatformManager->getApiFilterAnnotations();
        if($filterAnnotations) {
            foreach ($filterAnnotations as $filterAnnotation) {
                $writer->write(' * ' . $filterAnnotation);
            }
        }

        $classLevelAssertionAnnotationsBuilder = (new ClassLevelAssertionBuilderProvider())->getClassLevelAssertionBuilder();
        $classLevelAssertionAnnotations = $classLevelAssertionAnnotationsBuilder->buildAnnotations($this);
        if ($classLevelAssertionAnnotations) {
            foreach ($classLevelAssertionAnnotations as $classLevelAssertionAnnotation) {
                $writer->write($classLevelAssertionAnnotation);
            }
        }

        $writer
            ->writeIf($extendableEntity, ' * @ORM\MappedSuperclass')
            ->writeIf($hasDeletableBehaviour,
                    ' * @Gedmo\SoftDeleteable(fieldName="deleted_at", timeAware=false)')
            ->writeIf(!$extendableEntity,
                    ' * '.$this->getAnnotation('Entity', array('repositoryClass' => $this->getConfig()->get(Formatter::CFG_AUTOMATIC_REPOSITORY) ? $repositoryNamespace.$this->getModelName().'Repository' : null)))
            ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
            ->write(' * '.$this->getAnnotation('Table', array('name' => $this->quoteIdentifier($this->getRawTableName()), 'indexes' => $this->getIndexesAnnotation('Index'), 'uniqueConstraints' => $this->getIndexesAnnotation('UniqueConstraint'))))
            ->writeIf($extendableEntityHasDiscriminator,
                    ' * '.$this->getAnnotation('InheritanceType', array('SINGLE_TABLE')))
            ->writeIf($extendableEntityHasDiscriminator,
                    ' * '.$this->getAnnotation('DiscriminatorColumn', $this->getInheritanceDiscriminatorColumn()))
            ->writeIf($extendableEntityHasDiscriminator,
                    ' * '.$this->getAnnotation('DiscriminatorMap', array($this->getInheritanceDiscriminatorMap())))
            ->writeIf($lifecycleCallbacks, ' * @HasLifecycleCallbacks')
            ->writeIf($useBehavioralExtensions && strstr($this->getClassName($extendableEntity), 'Img'),
                    ' * @Gedmo\Uploadable(path="./public/upload/' . $this->getClassName($extendableEntity) . '", filenameGenerator="SHA1", allowOverwrite=true, appendNumber=true)')
            ->write(' */')
            ->write('class '.$this->getClassName($extendableEntity).$extendsClass.$implementsInterface)
            ->write('{')
            ->indent()
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($skipGetterAndSetter, $serializableEntity, $lifecycleCallbacks) {
                    $_this->writePreClassHandler($writer);
                    $_this->writeVars($writer);
                    $_this->writeConstructor($writer);
                    if (!$skipGetterAndSetter) {
                        $_this->writeGetterAndSetter($writer);
                    }
                    $_this->writePostClassHandler($writer);
                    foreach ($lifecycleCallbacks as $callback => $handlers) {
                        foreach ($handlers as $handler) {
                            $writer
                                ->write('/**')
                                ->write(' * @%s', ucfirst($callback))
                                ->write(' */')
                                ->write('public function %s()', $handler)
                                ->write('{')
                                ->write('}')
                                ->write('')
                            ;
                        }
                    }
                    if ($serializableEntity) {
                        $_this->writeSerialization($writer);
                    }
                })
            ->outdent()
            ->write('}')
            ->close()
        ;

        $namespace = $this->getEntityNamespace();

        if ($extendableEntity && !$writer->getStorage()->hasFile($this->getClassFileName())) {
            $writer
                ->open($this->getClassFileName())
                ->write('<?php')
                ->write('')
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                    if ($_this->getConfig()->get(Formatter::CFG_ADD_COMMENT)) {
                        $writer
                            ->write($_this->getFormatter()->getComment(Comment::FORMAT_PHP))
                            ->write('')
                        ;
                    }
                })
                ->write('namespace %s;', $namespace)
                ->write('')
                ->write('use %s\\%s;', $namespace, $this->getClassName(true, 'Base\\'))
                ->write('')
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                    $_this->writeExtendedUsedClasses($writer);
                })
                ->write('/**')
                ->write(' * '.$this->getNamespace(null, false))
                ->write(' *')
                ->writeIf($comment, $comment)
                ->write(' * '.$this->getAnnotation('Entity', array('repositoryClass' => $this->getConfig()->get(Formatter::CFG_AUTOMATIC_REPOSITORY) ? $repositoryNamespace.$this->getModelName().'Repository' : null)))
                ->write(' * '.$this->getAnnotation('Table', array('name' => $this->quoteIdentifier($this->getRawTableName()))))
                ->write(' */')
                ->write('class %s extends %s', $this->getClassName(), $this->getClassName(true))
                ->write('{')
                ->write('}')
                ->close()
            ;
        }
    }

    /**
     * Get the generated class name.
     *
     * @param bool $base
     * @return string
     */
    protected function getClassFileName($base = false)
    {
        return ($base ? $this->getTableFileName(null, array('%entity%' => 'Base'.DIRECTORY_SEPARATOR.'Base'.$this->getModelName())) : $this->getTableFileName());
    }

    /**
     * Get the generated class name.
     *
     * @param bool $base
     * @return string
     */
    protected function getClassName($base = false, $prefix = '')
    {
        return $prefix.($base ? 'Base' : '').$this->getModelName();
    }

    /**
     * Get the class name to implement.
     *
     * @return string
     */
    protected function getClassImplementations()
    {
    }

    /**
     * Get the class name to extend
     *
     * @return string
     */
    protected function getClassToExtend()
    {
        $class = $this->getConfig()->get(Formatter::CFG_EXTENDS_CLASS);
        if(empty($class)) {
            return '';
        }

        return " extends $class";
    }

    /**
     * Get the class name to implement
     *
     * @return string
     */
    protected function getInterfaceToImplement()
    {
        $interface = $this->getClassImplementations();
        if(empty($interface)) {
            return '';
        }

        return " implements $interface";
    }

    /**
     * Get the use class for ORM if applicable.
     *
     * @return string
     */
    protected function getOrmUse()
    {
        if ('@ORM\\' === $this->addPrefix()) {
            return 'Doctrine\ORM\Mapping as ORM';
        }
    }

    /**
     * Get used classes.
     *
     * @return array
     */
    protected function getUsedClasses()
    {
        $uses = array();
        if ($orm = $this->getOrmUse()) {
            $uses[] = $orm;
        }
        if (count($this->getTableM2MRelations()) || count($this->getAllLocalForeignKeys())) {
            $uses[] = $this->getCollectionClass();
        }
        if($this->getConfig()->get(Formatter::CFG_RASMEY_UUID_PROVIDER)) {
            $uses[] = 'Ramsey\Uuid\Uuid';
        }
        if($this->getConfig()->get(Formatter::CFG_API_PLATFORM_ANNOTATIONS)) {
            $uses[] = 'ApiPlatform\Core\Annotation\ApiResource';
            $uses[] = 'ApiPlatform\Core\Annotation\ApiSubresource';
            $uses[] = 'ApiPlatform\Core\Annotation\ApiFilter';
            $uses[] = 'ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter';
            $uses[] = 'ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter';
            $uses[] = 'ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\BooleanFilter';
            $uses[] = 'ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter';
            $uses[] = 'ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter';
        }

        $assertionBuilder = (new ClassLevelAssertionBuilderProvider());
        $uses = array_merge($uses, $assertionBuilder->getUsages());

        $columnUsages = (new PropertyLevelAssertionBuilderProvider())->getUsages();
        $uses = array_merge($uses, $columnUsages);

        return $uses;
    }

    protected function getInheritanceDiscriminatorColumn()
    {
        $result = array();
        if ($column = trim($this->parseComment('discriminator'))) {
            $result['name'] = $column;
            foreach ($this->getColumns() as $col) {
                if ($column == $col->getColumnName()) {
                    $result['type'] = $this->getFormatter()->getDatatypeConverter()->getDataType($col->getColumnType());
                    break;
                }
            }
        } else {
            $result['name'] = 'discr';
            $result['type'] = 'string';
        }

        return $result;
    }

    protected function getInheritanceDiscriminatorMap()
    {
        return array('base' => $this->getNamespace($this->getClassName(true, 'Base\\')), 'extended' => $this->getNamespace());
    }

    public function writeUsedClasses(WriterInterface $writer)
    {
        $this->writeUses($writer, $this->getUsedClasses());

        return $this;
    }

    public function writeExtendedUsedClasses(WriterInterface $writer)
    {
        $uses = array();
        if ($orm = $this->getOrmUse()) {
            $uses[] = $orm;
        }
        $uses[] = sprintf('%s\%s', $this->getEntityNamespace(), $this->getClassName(true));
        $this->writeUses($writer, $uses);

        return $this;
    }

    protected function writeUses(WriterInterface $writer, $uses = array())
    {
        if (count($uses)) {
            foreach ($uses as $use) {
                $writer->write('use %s;', $use);
            }
            $writer->write('');
        }

        return $this;
    }

    /**
     * Write pre class handler.
     *
     * @param \MwbExporter\Writer\WriterInterface $writer
     * @return \MwbExporter\Formatter\Doctrine2\Annotation\Model\Table
     */
    public function writePreClassHandler(WriterInterface $writer)
    {
        return $this;
    }

    public function writeVars(WriterInterface $writer)
    {
        $this->writeColumnsVar($writer);
        $this->writeRelationsVar($writer);
        $this->writeManyToManyVar($writer);

        return $this;
    }

    protected function writeColumnsVar(WriterInterface $writer)
    {
        foreach ($this->getColumns() as $column) {
            $column->writeVar($writer);
        }
    }

    protected function writeRelationsVar(WriterInterface $writer)
    {
        // 1 <=> N references
        foreach ($this->getAllLocalForeignKeys() as $local) {
            if ($this->isLocalForeignKeyIgnored($local)) {
                $this->getDocument()->addLog(sprintf('  Local relation "%s" was ignored', $local->getOwningTable()->getModelName()));
                continue;
            }

            $targetEntity = $local->getOwningTable()->getModelName();
            $targetEntityFQCN = $local->getOwningTable()->getModelNameAsFQCN();
            $mappedBy = $local->getReferencedTable()->getModelName();
            $related = $local->getForeignM2MRelatedName();
            $cacheMode = $this->getFormatter()->getCacheOption($local->parseComment('cache'));

            $this->getDocument()->addLog(sprintf('  Writing 1 <=> ? relation "%s"', $targetEntity));

            $annotationOptions = array(
                'targetEntity' => $targetEntityFQCN,
                'mappedBy' => lcfirst($local->getOwningTable()->getRelatedVarName($mappedBy, $related)),
                'cascade' => $this->getFormatter()->getCascadeOption($local->parseComment('cascade')),
                'fetch' => $this->getFormatter()->getFetchOption($local->parseComment('fetch')),
                'orphanRemoval' => $this->getFormatter()->getBooleanOption($local->parseComment('orphanRemoval')),
            );

            if ($local->isManyToOne()) {
                $this->getDocument()->addLog('  Relation considered as "1 <=> N"');

                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('OneToMany', $annotationOptions))
                    ->write(' * '.$this->getJoins($local));

                $this->writeAnnotationAssertionsGivenJoinAnnotation($writer, $this->getJoins($local));


                $writer->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($local) {
                        if (count($orders = $_this->getFormatter()->getOrderOption($local->parseComment('order')))) {
                            $writer
                                ->write(' * '.$_this->getAnnotation('OrderBy', array($orders)))
                            ;
                        }
                    })
                    ->write(' */')
                    ->write('protected $'.lcfirst($this->getRelatedVarName($targetEntity, $related, true)).';')
                    ->write('')
                ;
                $this->relationVarNames[] = lcfirst($this->getRelatedVarName($targetEntity, $related, true));
            } else {
                $this->getDocument()->addLog('  Relation considered as "1 <=> 1"');

                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('OneToOne', $annotationOptions));

                $this->writeAnnotationAssertionsGivenJoinAnnotation($writer,$this->getAnnotation('OneToOne', $annotationOptions));

                $writer->write(' */')
                    ->write('protected $'.lcfirst($targetEntity).';')
                    ->write('')
                ;
                $this->relationVarNames[] = lcfirst($targetEntity);
            }
        }

        // N <=> 1 references
        foreach ($this->getAllForeignKeys() as $foreign) {
            if ($this->isForeignKeyIgnored($foreign)) {
                $this->getDocument()->addLog(sprintf('  Foreign relation "%s" was ignored', $foreign->getOwningTable()->getModelName()));
                continue;
            }

            $targetEntity = $foreign->getReferencedTable()->getModelName();
            $targetEntityFQCN = $foreign->getReferencedTable()->getModelNameAsFQCN($foreign->getOwningTable()->getEntityNamespace());
            $inversedBy = $foreign->getOwningTable()->getModelName();
            $related = $this->getRelatedName($foreign);

            $this->getDocument()->addLog(sprintf('  Writing N <=> ? relation "%s"', $targetEntity));

            $annotationOptions = array(
                'targetEntity' => $targetEntityFQCN,
                'inversedBy' => $foreign->isUnidirectional() ? null : lcfirst($this->getRelatedVarName($inversedBy, $related, true)),
                'cascade' => $this->getFormatter()->getCascadeOption($foreign->parseComment('cascade')),
                'fetch' => $this->getFormatter()->getFetchOption($foreign->parseComment('fetch')),
            );
            $cacheMode = $this->getFormatter()->getCacheOption($foreign->parseComment('cache'));

            if ($foreign->isManyToOne()) {
                $this->getDocument()->addLog('  Relation considered as "N <=> 1"');

                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('ManyToOne', $annotationOptions))
                    ->write(' * '.$this->getJoins($foreign, false));

                $this->writeAnnotationAssertionsGivenJoinAnnotation($writer, $this->getJoins($foreign));

                $writer
                    ->write(' */')
                    ->write('protected $'.lcfirst($this->getRelatedVarName($targetEntity, $related)).';')
                    ->write('')
                ;
                $this->relationVarNames[] = lcfirst($this->getRelatedVarName($targetEntity, $related));
            } else {
                $this->getDocument()->addLog('  Relation considered as "1 <=> 1"');

                if (null !== $annotationOptions['inversedBy']) {
                    $annotationOptions['inversedBy'] = lcfirst($this->getRelatedVarName($inversedBy, $related));
                }
                $annotationOptions['cascade'] = $this->getFormatter()->getCascadeOption($foreign->parseComment('cascade'));

                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('OneToOne', $annotationOptions))
                    ->write(' * '.$this->getJoins($foreign, false));
                    $this->writeAnnotationAssertionsGivenJoinAnnotation($writer, $this->getJoins($foreign));

                    $writer->write(' */')
                    ->write('protected $'.lcfirst($targetEntity).';')
                    ->write('')
                ;
                $this->relationVarNames[] = lcfirst($targetEntity);
            }
        }

        return $this;
    }

    protected function writeManyToManyVar(WriterInterface $writer)
    {
        foreach ($this->getTableM2MRelations() as $relation) {
            $this->getDocument()->addLog(sprintf('  Writing setter/getter for N <=> N "%s"', $relation['refTable']->getModelName()));

            $fk1 = $relation['reference'];
            $isOwningSide = $this->getFormatter()->isOwningSide($relation, $fk2);
            $annotationOptions = array(
                'targetEntity' => $relation['refTable']->getModelNameAsFQCN(),
                'mappedBy' => null,
                'inversedBy' => lcfirst($this->getPluralModelName()),
                'cascade' => $this->getFormatter()->getCascadeOption($fk1->parseComment('cascade')),
                'fetch' => $this->getFormatter()->getFetchOption($fk1->parseComment('fetch')),
            );
            $cacheMode = $this->getFormatter()->getCacheOption($fk1->parseComment('cache'));

            // if this is the owning side, also output the JoinTable Annotation
            // otherwise use "mappedBy" feature
            if ($isOwningSide) {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for N <=> N "%s"', "owner"));

                if ($fk1->isUnidirectional()) {
                    unset($annotationOptions['inversedBy']);
                }

                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('ManyToMany', $annotationOptions))
                    ->write(' * '.$this->getAnnotation('JoinTable',
                        array(
                            'name'               => $this->quoteIdentifier($relation['reference']->getOwningTable()->getRawTableName()),
                            'joinColumns'        => array($this->getJoins($fk1, false)),
                            'inverseJoinColumns' => array($this->getJoins($fk2, false)),
                        ), array('multiline' => true, 'wrapper' => ' * %s')))
                    ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($fk2) {
                        if (count($orders = $_this->getFormatter()->getOrderOption($fk2->parseComment('order')))) {
                            $writer
                                ->write(' * '.$_this->getAnnotation('OrderBy', array($orders)))
                            ;
                        }
                    })
                    ->write(' */')
                ;
            } else {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for N <=> N "%s"', "inverse"));

                if ($fk2->isUnidirectional()) {
                    continue;
                }

                $annotationOptions['mappedBy'] = $annotationOptions['inversedBy'];
                $annotationOptions['inversedBy'] = null;
                $writer
                    ->write('/**')
                    ->writeIf($cacheMode, ' * '.$this->getAnnotation('Cache', array($cacheMode)))
                    ->write(' * '.$this->getAnnotation('ManyToMany', $annotationOptions))
                    ->write(' */')
                ;
            }
            $writer
                ->write('protected $'.lcfirst($relation['refTable']->getPluralModelName()).';')
                ->write('')
            ;
            $this->relationVarNames[] = lcfirst($relation['refTable']->getPluralModelName());
        }

        return $this;
    }

    public function writeConstructor(WriterInterface $writer)
    {
        $rasmeyUuidProvider  = $this->getConfig()->get(Formatter::CFG_RASMEY_UUID_PROVIDER);

        /** @var Column $column */
        foreach ($this->columns as $column) {
            if ($column->isPrimary()) {
                $id = $column->getName();
                $isUuid = $column->isUuid();
            }
        }

        $writer
            ->writeIf($rasmeyUuidProvider && $isUuid, 'public function __construct(?string $uuid = null)')
            ->writeIf(!($rasmeyUuidProvider && $isUuid), 'public function __construct()')
            ->write('{')
            ->indent()
            ->writeIf($rasmeyUuidProvider && $isUuid, '$uuid = $uuid ? $uuid : Uuid::uuid1()->toString();')
            ->writeIf(($rasmeyUuidProvider && $isUuid && $id), '$this->'.$id.' = $uuid;')
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                    $_this->writeCurrentTimestampConstructor($writer);
                    $_this->writeRelationsConstructor($writer);
                    $_this->writeManyToManyConstructor($writer);
                })
            ->outdent()
            ->write('}')
            ->write('')
        ;

        return $this;
    }

    public function writeCurrentTimestampConstructor(WriterInterface $writer)
    {
        foreach ($this->getColumns() as $column) {
            if ('CURRENT_TIMESTAMP' === $column->getDefaultValue()) {
                $writer->write('$this->%s = new \DateTime(\'now\');', $column->getColumnName());
            }
        }
    }

    public function writeRelationsConstructor(WriterInterface $writer)
    {
        foreach ($this->getAllLocalForeignKeys() as $local) {
            if ($this->isLocalForeignKeyIgnored($local)) {
                continue;
            }
            $this->getDocument()->addLog(sprintf('  Writing N <=> 1 constructor "%s"', $local->getOwningTable()->getModelName()));

            $related = $local->getForeignM2MRelatedName();
            $writer->write('$this->%s = new %s();', lcfirst($this->getRelatedVarName($local->getOwningTable()->getModelName(), $related, true)), $this->getCollectionClass(false));
        }
    }

    public function writeManyToManyConstructor(WriterInterface $writer)
    {
        foreach ($this->getTableM2MRelations() as $relation) {
            $this->getDocument()->addLog(sprintf('  Writing M2M constructor "%s"', $relation['refTable']->getModelName()));
            $writer->write('$this->%s = new %s();', lcfirst($relation['refTable']->getPluralModelName()), $this->getCollectionClass(false));
        }
    }

    public function writeGetterAndSetter(WriterInterface $writer)
    {
        $this->writeColumnsGetterAndSetter($writer);
        $this->writeRelationsGetterAndSetter($writer);
        $this->writeManyToManyGetterAndSetter($writer);

        return $this;
    }

    protected function writeColumnsGetterAndSetter(WriterInterface $writer)
    {
        foreach ($this->getColumns() as $column) {
            $column->writeGetterAndSetter($writer);
        }
    }
    
    protected function writeRelationsGetterAndSetter(WriterInterface $writer)
    {
        // N <=> 1 references
        foreach ($this->getAllLocalForeignKeys() as $local) {
            if ($this->isLocalForeignKeyIgnored($local)) {
                continue;
            }

            $this->getDocument()->addLog(sprintf('  Writing setter/getter for N <=> ? "%s"', $local->getParameters()->get('name')));

            if ($local->isManyToOne()) {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for "%s"', 'N <=> 1'));

                $related = $local->getForeignM2MRelatedName();
                $related_text = $local->getForeignM2MRelatedName(false);

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Add '.trim($local->getOwningTable()->getModelName().' entity '.$related_text). ' to collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()))
                    ->write(' * @return '.$this->getNamespace())
                    ->write(' */')
                    ->write('public function add'.$this->getRelatedVarName($local->getOwningTable()->getModelName(), $related).'('.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()).')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.lcfirst($this->getRelatedVarName($local->getOwningTable()->getModelName(), $related, true)).'[] = $'.lcfirst($local->getOwningTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // remover
                    ->write('/**')
                    ->write(' * Remove '.trim($local->getOwningTable()->getModelName().' entity '.$related_text). ' from collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()))
                    ->write(' * @return '.$this->getNamespace())
                    ->write(' */')
                    ->write('public function remove'.$this->getRelatedVarName($local->getOwningTable()->getModelName(), $related).'('.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()).')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.lcfirst($this->getRelatedVarName($local->getOwningTable()->getModelName(), $related, true)).'->removeElement($'.lcfirst($local->getOwningTable()->getModelName()).');')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.trim($local->getOwningTable()->getModelName().' entity '.$related_text).' collection (one to many).')
                    ->write(' *')
                    ->write(' * @return '.$this->getCollectionInterface())
                    ->write(' */')
                    ->write('public function get'.$this->getRelatedVarName($local->getOwningTable()->getModelName(), $related, true).'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.lcfirst($this->getRelatedVarName($local->getOwningTable()->getModelName(), $related, true)).';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            } else {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for "%s"', '1 <=> 1'));

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$local->getOwningTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()))
                    ->write(' * @return '.$this->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$local->getOwningTable()->getModelName().'('.$local->getOwningTable()->getNamespace().' $'.lcfirst($local->getOwningTable()->getModelName()).' = null)')
                    ->write('{')
                    ->indent()
                        ->writeIf(!$local->isUnidirectional(), '$'.lcfirst($local->getOwningTable()->getModelName()).'->set'.$local->getReferencedTable()->getModelName().'($this);')
                        ->write('$this->'.lcfirst($local->getOwningTable()->getModelName()).' = $'.lcfirst($local->getOwningTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$local->getOwningTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$local->getOwningTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$local->getOwningTable()->getModelName().'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.lcfirst($local->getOwningTable()->getModelName()).';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            }
        }

        // 1 <=> N references
        foreach ($this->getAllForeignKeys() as $foreign) {
            if ($this->isForeignKeyIgnored($foreign)) {
                continue;
            }

            $this->getDocument()->addLog(sprintf('  Writing setter/getter for 1 <=> ? "%s"', $foreign->getParameters()->get('name')));

            if ($foreign->isManyToOne()) {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for "%s"', '1 <=> N'));

                $related = $this->getRelatedName($foreign);
                $related_text = $this->getRelatedName($foreign, false);

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.trim($foreign->getReferencedTable()->getModelName().' entity '.$related_text).' (many to one).')
                    ->write(' *')
                    ->write(' * @param '.$foreign->getReferencedTable()->getNamespace().' $'.lcfirst($foreign->getReferencedTable()->getModelName()))
                    ->write(' * @return '.$this->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$this->getRelatedVarName($foreign->getReferencedTable()->getModelName(), $related).'('.$foreign->getReferencedTable()->getNamespace().' $'.lcfirst($foreign->getReferencedTable()->getModelName()).' = null)')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.lcfirst($this->getRelatedVarName($foreign->getReferencedTable()->getModelName(), $related)).' = $'.lcfirst($foreign->getReferencedTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.trim($foreign->getReferencedTable()->getModelName().' entity '.$related_text).' (many to one).')
                    ->write(' *')
                    ->write(' * @return '.$foreign->getReferencedTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$this->getRelatedVarName($foreign->getReferencedTable()->getModelName(), $related).'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.lcfirst($this->getRelatedVarName($foreign->getReferencedTable()->getModelName(), $related)).';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            } else {
                $this->getDocument()->addLog(sprintf('  Applying setter/getter for "%s"', '1 <=> 1'));

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$foreign->getReferencedTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$foreign->getReferencedTable()->getNamespace().' $'.lcfirst($foreign->getReferencedTable()->getModelName()))
                    ->write(' * @return '.$this->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$foreign->getReferencedTable()->getModelName().'('.$foreign->getReferencedTable()->getNamespace().' $'.lcfirst($foreign->getReferencedTable()->getModelName()).')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.lcfirst($foreign->getReferencedTable()->getModelName()).' = $'.lcfirst($foreign->getReferencedTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$foreign->getReferencedTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$foreign->getReferencedTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$foreign->getReferencedTable()->getModelName().'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.lcfirst($foreign->getReferencedTable()->getModelName()).';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            }
        }

        return $this;
    }

    protected function writeManyToManyGetterAndSetter(WriterInterface $writer)
    {
        foreach ($this->getTableM2MRelations() as $relation) {
            $this->getDocument()->addLog(sprintf('  Writing N <=> N relation "%s"', $relation['refTable']->getModelName()));

            $isOwningSide = $this->getFormatter()->isOwningSide($relation, $fk2);
            $writer
                ->write('/**')
                ->write(' * Add '.$relation['refTable']->getModelName().' entity to collection.')
                ->write(' *')
                ->write(' * @param '. $relation['refTable']->getNamespace().' $'.lcfirst($relation['refTable']->getModelName()))
                ->write(' * @return '.$this->getNamespace($this->getModelName()))
                ->write(' */')
                ->write('public function add'.$relation['refTable']->getModelName().'('.$relation['refTable']->getNamespace().' $'.lcfirst($relation['refTable']->getModelName()).')')
                ->write('{')
                ->indent()
                    ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($isOwningSide, $relation) {
                        if ($isOwningSide) {
                            $writer->write('$%s->add%s($this);', lcfirst($relation['refTable']->getModelName()), $_this->getModelName());
                        }
                    })
                    ->write('$this->'.lcfirst($relation['refTable']->getPluralModelName()).'[] = $'.lcfirst($relation['refTable']->getModelName()).';')
                    ->write('')
                    ->write('return $this;')
                ->outdent()
                ->write('}')
                ->write('')
                ->write('/**')
                ->write(' * Remove '.$relation['refTable']->getModelName().' entity from collection.')
                ->write(' *')
                ->write(' * @param '. $relation['refTable']->getNamespace().' $'.lcfirst($relation['refTable']->getModelName()))
                ->write(' * @return '.$this->getNamespace($this->getModelName()))
                ->write(' */')
                ->write('public function remove'.$relation['refTable']->getModelName().'('.$relation['refTable']->getNamespace().' $'.lcfirst($relation['refTable']->getModelName()).')')
                ->write('{')
                ->indent()
                    ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($isOwningSide, $relation) {
                        if ($isOwningSide) {
                            $writer->write('$%s->remove%s($this);', lcfirst($relation['refTable']->getModelName()), $_this->getModelName());
                        }
                    })
                    ->write('$this->'.lcfirst($relation['refTable']->getPluralModelName()).'->removeElement($'.lcfirst($relation['refTable']->getModelName()).');')
                    ->write('')
                    ->write('return $this;')
                ->outdent()
                ->write('}')
                ->write('')
                ->write('/**')
                ->write(' * Get '.$relation['refTable']->getModelName().' entity collection.')
                ->write(' *')
                ->write(' * @return '.$this->getCollectionInterface())
                ->write(' */')
                ->write('public function get'.$relation['refTable']->getPluralModelName().'()')
                ->write('{')
                ->indent()
                    ->write('return $this->'.lcfirst($relation['refTable']->getPluralModelName()).';')
                ->outdent()
                ->write('}')
                ->write('')
            ;
        }

        return $this;
    }

    /**
     * Write post class handler.
     *
     * @param \MwbExporter\Writer\WriterInterface $writer
     * @return \MwbExporter\Formatter\Doctrine2\Annotation\Model\Table
     */
    public function writePostClassHandler(WriterInterface $writer)
    {
        return $this;
    }

    public function writeSerialization(WriterInterface $writer)
    {
        $varNames = $this->relationVarNames;
        foreach ($this->getColumns() as $column) {
            /** @var Column $column */
            if(!$column->isIgnored()) {
                $varNames[] = $column->getColumnName();
            }
        }
        $writer
            ->write('public function __sleep()')
            ->write('{')
            ->indent()
                ->write('return array(%s);', implode(', ', array_map(function($column) {
                    return sprintf('\'%s\'', $column);
                },$varNames))) // was $this->getColumns()->getColumnNames()
            ->outdent()
            ->write('}')
        ;

        return $this;
    }

    private function writeAnnotationAssertionsGivenJoinAnnotation(WriterInterface $writer, Annotation $joinAnnotation): void {
        $propertyLevelBuilder = (new PropertyLevelAssertionBuilderProvider())->getPropertyLevelAssertionBuilder();
        $joinColumnAssertions = $propertyLevelBuilder->buildAnnotationsForJoinColumn($joinAnnotation);
        foreach ($joinColumnAssertions as $joinColumnAssertion) {
            $writer->write($joinColumnAssertion);
        }
    }
}

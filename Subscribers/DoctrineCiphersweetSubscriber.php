<?php
/*
 * @copyright  Copyright (C) 2020 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/doctrine-ciphersweet
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Subscribers;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use DoctrineCiphersweetBundle\Configuration\EncryptedWithBlindIndex;
use DoctrineCiphersweetBundle\Encryptors\EncryptorInterface;

/**
 * Doctrine event subscriber which encrypt/decrypt entities.
 *
 * @author Parts of this file were forked a longtime ago from ambta/DoctrineEncryptBundle
 */
class DoctrineCiphersweetSubscriber implements EventSubscriber
{
    const ENCRYPTED_ANN_NAME = EncryptedWithBlindIndex::class;

    private EncryptorInterface $encryptor;
    private Reader $annReader;

    /**
     * @var array
     */
    public $_originalValues = [];
    /**
     * @var array
     */
    private $decodedRegistry = [];
    /**
     * Caches information on an entity's encrypted fields in an array keyed on
     * the entity's class name. The value will be a list of Reflected fields that are encrypted.
     *
     * @var array
     */
    private $encryptedFieldCache = [];

    /**
     * Before flushing the objects out to the database, we modify their password value to the
     * encrypted value. Since we want the password to remain decrypted on the entity after a flush,
     * we have to write the decrypted value back to the entity.
     *
     * @var array
     */
    private $postFlushDecryptQueue = [];

    /**
     * Initialization of subscriber.
     */
    public function __construct(Reader $annReader, $encryptorClass)
    {
        $this->annReader = $annReader;
        $this->encryptor = $encryptorClass;
    }

    /**
     * Encrypt the password before it is written to the database.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em         = $args->getEntityManager();
        $unitOfWork = $em->getUnitOfWork();

        $this->postFlushDecryptQueue = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->entityOnFlush($entity, $em);
            $unitOfWork->recomputeSingleEntityChangeSet($em->getClassMetadata(\get_class($entity)), $entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->entityOnFlush($entity, $em);
            $unitOfWork->recomputeSingleEntityChangeSet($em->getClassMetadata(\get_class($entity)), $entity);
        }
    }

    /**
     * Processes the entity for an onFlush event.
     *
     * @param object $entity
     */
    private function entityOnFlush($entity, EntityManagerInterface $em): void
    {
        $objId = spl_object_hash($entity);

        $fields = [];

        foreach ($this->getEncryptedFields($entity, $em) as $field) {
            $fields[$field->getName()] = [
                'field' => $field,
                'value' => $field->getValue($entity),
            ];
        }
        $this->postFlushDecryptQueue[$objId] = [
            'entity' => $entity,
            'fields' => $fields,
        ];

        $this->processFields($entity, $em);
    }

    /**
     * @param bool $entity
     *
     * @return \ReflectionProperty[]
     */
    private function getEncryptedFields($entity, EntityManagerInterface $em): array
    {
        $className = \get_class($entity);

        if (isset($this->encryptedFieldCache[$className])) {
            return $this->encryptedFieldCache[$className];
        }

        $meta            = $em->getClassMetadata($className);
        $encryptedFields = [];

        foreach ($meta->getReflectionProperties() as $refProperty) {
            /** @var \ReflectionProperty $refProperty */
            if ($this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME)) {
                $refProperty->setAccessible(true);
                $encryptedFields[] = $refProperty;
            }
        }

        $this->encryptedFieldCache[$className] = $encryptedFields;

        return $encryptedFields;
    }

    /**
     * Process (encrypt/decrypt) entities fields.
     */
    public function processFields(object $entity, EntityManagerInterface $em, $isEncryptOperation = true, $force = null): bool
    {
        $properties = $this->getEncryptedFields($entity, $em);
        $unitOfWork = $em->getUnitOfWork();

        $oid = spl_object_hash($entity);

        foreach ($properties as $refProperty) {
            $AnnotationConfig = $this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME);

            $value = $refProperty->getValue($entity);
            $value = null === $value ? '' : $value;

            switch ($isEncryptOperation) {
                case true:
                    if ('encrypt' === $force) {
                        list($value, $indexes) = $this->encryptor->prepareForStorage($entity, $refProperty->getName(), $value);
                        foreach ($indexes as $key => $blindIndexValue) {
                            $setter = 'set'.str_replace('_', '', ucwords($key, '_'));
                            $entity->$setter($blindIndexValue);
                        }
                    } else {
                        if (\array_key_exists($oid, $this->_originalValues) && \array_key_exists($refProperty->getName(), $this->_originalValues[$oid])) {
                            $oldValue = @$this->_originalValues[$oid][$refProperty->getName()];

                            if ('nacl' == substr($oldValue, 0, 4)) {
                                $class_name =$em->getClassMetadata(get_class($entity))->getName();
                                $oldValue = $this->encryptor->decrypt($entity, $refProperty->getName(), $oldValue);
                            }
                        } else {
                            $oldValue = null;
                        }

                        if ($oldValue === $value || (null === $oldValue && null === $value)) {
                            $value = $oldValue;
                        } else {
                            list($value, $indexes) = $this->encryptor->prepareForStorage($entity, $refProperty->getName(), $value);
                            foreach ($indexes as $key => $blindIndexValue) {
                                $setter = 'set'.str_replace('_', '', ucwords($key, '_'));
                                $entity->$setter($blindIndexValue);
                            }
                        }
                    }

                    break;

                case false:
                    $this->_originalValues[$oid][$refProperty->getName()] = $value;

                    if ('nacl' == substr($value, 0, 4)) {
                        $class_name =$em->getClassMetadata(get_class($entity))->getName();
                        $value = $this->encryptor->decrypt($class_name, $refProperty->getName(), $value);
                    }

                    break;
            }

            if (null !== $value) {
                $refProperty->setValue($entity, $value);
            }

            if (!$isEncryptOperation && !\defined('_DONOTENCRYPT')) {
                //we don't want the object to be dirty immediately after reading
                $unitOfWork->setOriginalEntityProperty($oid, $refProperty->getName(), $value);
            }
        }

        return !empty($properties);
    }

    /**
     * After we have persisted the entities, we want to have the
     * decrypted information available once more.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $unitOfWork = $args->getEntityManager()->getUnitOfWork();

        foreach ($this->postFlushDecryptQueue as $pair) {
            $fieldPairs = $pair['fields'];
            $entity     = $pair['entity'];
            $oid        = spl_object_hash($entity);

            foreach ($fieldPairs as $fieldPair) {
                /** @var \ReflectionProperty $field */
                $field = $fieldPair['field'];
                $field->setValue($entity, $fieldPair['value']);
                $unitOfWork->setOriginalEntityProperty($oid, $field->getName(), $fieldPair['value']);
            }

            $this->addToDecodedRegistry($entity);
        }
        $this->postFlushDecryptQueue = [];
    }

    /**
     * Adds entity to decoded registry.
     *
     * @param object $entity Some doctrine entity
     */
    private function addToDecodedRegistry($entity): void
    {
        $this->decodedRegistry[spl_object_hash($entity)] = true;
    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @EncryptedWithBlindIndex annotations.
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        if (!$this->hasInDecodedRegistry($entity)) {
            if ($this->processFields($entity, $args->getEntityManager(), false)) {
                $this->addToDecodedRegistry($entity);
            }
        }
    }

    /**
     * Check if we have entity in decoded registry.
     *
     * @param object $entity Some doctrine entity
     */
    private function hasInDecodedRegistry($entity): bool
    {
        return isset($this->decodedRegistry[spl_object_hash($entity)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postLoad,
            Events::onFlush,
            Events::postFlush,
        ];
    }
}

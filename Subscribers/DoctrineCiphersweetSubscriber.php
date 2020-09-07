<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
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
use DoctrineCiphersweetBundle\Encryptors\EncryptorInterface;

/**
 * Doctrine event subscriber which encrypt/decrypt entities.
 */
class DoctrineCiphersweetSubscriber implements EventSubscriber
{
    /**
     * Encryptor interface namespace.
     */
    const ENCRYPTOR_INTERFACE_NS = 'DoctrineCiphersweetBundle\Encryptors\EncryptorInterface';

    /**
     * Encrypted annotation full name.
     */
    const ENCRYPTED_ANN_NAME = 'DoctrineCiphersweetBundle\Configuration\Encrypted';

    /**
     * Count amount of decrypted values in this service.
     *
     * @var int
     */
    public $decryptCounter = 0;

    /**
     * Count amount of encrypted values in this service.
     *
     * @var int
     */
    public $encryptCounter = 0;

    /**
     * @var array
     */
    public $_originalValues = [];

    /**
     * Encryptor.
     *
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var array
     */
    private $decodedRegistry = [];

    /**
     * Annotation reader.
     *
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $annReader;
    /**
     * Secret key.
     *
     * @var array
     */
    private $secretKeys = [];

    /**
     * Used for restoring the encryptor after changing it.
     *
     * @var string
     */
    private $restoreEncryptor;

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
     *
     * @param string                  $encryptorClass The encryptor class.  This can be empty if a service is being provided.
     * @param string                  $secretKey      the secret key
     * @param EncryptorInterface|null $service        (Optional)  An EncryptorInterface.
     *
     * This allows for the use of dependency injection for the encrypters.
     */
    public function __construct(Reader $annReader, $encryptorClass, array $secretKeys, EncryptorInterface $service = null)
    {
        $this->annReader  = $annReader;
        $this->secretKeys = $secretKeys;

        if ($service instanceof EncryptorInterface) {
            $this->encryptor = $service;
        } else {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $secretKeys);
        }

        $this->restoreEncryptor = $this->encryptor;
    }

    /**
     * Encryptor factory. Checks and create needed encryptor.
     *
     * @param string $classFullName Encryptor namespace and name
     * @param string $secretKey     Secret key for encryptor
     *
     * @return EncryptorInterface
     *
     * @throws \RuntimeException
     */
    private function encryptorFactory($classFullName, $secretKeys)
    {
        $refClass = new \ReflectionClass($classFullName);
        if ($refClass->implementsInterface(self::ENCRYPTOR_INTERFACE_NS)) {
            return new $classFullName($secretKeys);
        } else {
            throw new \RuntimeException('Encryptor must implements interface EncryptorInterface');
        }
    }

    public static function capitalize(string $word): string
    {
        if (\is_array($word)) {
            $word = $word[0];
        }

        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $word)));
    }

    public function getSecretKeys(): array
    {
        return $this->secretKeys;
    }

    /**
     * Restore encryptor set in config.
     */
    public function restoreEncryptor()
    {
        $this->encryptor = $this->restoreEncryptor;
    }

    /**
     * Encrypt the password before it is written to the database.
     */
    public function onFlush(OnFlushEventArgs $args)
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
    private function entityOnFlush($entity, EntityManagerInterface $em)
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
    private function getEncryptedFields($entity, EntityManagerInterface $em)
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
     *
     * @param object $entity Some doctrine entity
     */
    public function processFields($entity, EntityManagerInterface $em, $isEncryptOperation = true, $force = null): bool
    {
        $properties = $this->getEncryptedFields($entity, $em);
        $unitOfWork = $em->getUnitOfWork();

        $oid = spl_object_hash($entity);

        foreach ($properties as $refProperty) {
            $AnnotationConfig = $this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME);

            if (null === $this->getEncryptor()) {
                continue;
            }

            // if using @Encrypted without key_name then use key called "main" as a sensible default.
            if (null === $AnnotationConfig->key_name) {
                $AnnotationConfig->key_name = 'main';
            }
            $this->encryptor->setKeyName($AnnotationConfig->key_name);

            $value = $refProperty->getValue($entity);
            $value = null === $value ? '' : $value;

            switch ($isEncryptOperation) {
                case true:
                    if ('encrypt' === $force) {
                        $value = $this->encryptor->encrypt($value);
                    } else {
                        if (\array_key_exists($oid, $this->_originalValues) && \array_key_exists($refProperty->getName(), $this->_originalValues[$oid])) {
                            $oldValue = @$this->_originalValues[$oid][$refProperty->getName()];

                            if ('<Ha>' == substr($oldValue, \strlen($oldValue) - 4)) {
                                $oldValue = $this->encryptor->decrypt(substr($oldValue, 0, \strlen($oldValue) - 4));
                            }
                        } else {
                            $oldValue = null;
                        }

                        if ($oldValue === $value || (null === $oldValue && null === $value)) {
                            $value = $oldValue;
                        } else {
                            $value = $this->encryptor->encrypt($value);
                        }
                    }

                    break;

                case false:
                    $this->_originalValues[$oid][$refProperty->getName()] = $value;

                    if ('decrypt' === $force && '<Ha>' == substr($value, \strlen($value) - 4)) {
                        $value = $this->encryptor->decrypt(substr($value, 0, \strlen($value) - 4));
                    } elseif ('<Ha>' == substr($value, \strlen($value) - 4)) {
                        $value = $this->encryptor->decrypt(substr($value, 0, \strlen($value) - 4));
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
     * Get the current encryptor.
     */
    public function getEncryptor()
    {
        if (!empty($this->encryptor)) {
            return \get_class($this->encryptor);
        } else {
            return;
        }
    }

    /**
     * Change the encryptor.
     *
     * @param $encryptorClass
     */
    public function setEncryptor($encryptorClass)
    {
        if (null !== $encryptorClass) {
            $this->encryptor = $this->encryptorFactory($encryptorClass, $this->secretKeys);

            return;
        }

        $this->encryptor = null;
    }

    /**
     * After we have persisted the entities, we want to have the
     * decrypted information available once more.
     */
    public function postFlush(PostFlushEventArgs $args)
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
    private function addToDecodedRegistry($entity)
    {
        $this->decodedRegistry[spl_object_hash($entity)] = true;
    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @Encrypted annotations.
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $em     = $args->getEntityManager();

        if (!$this->hasInDecodedRegistry($entity)) {
            if ($this->processFields($entity, $em, false)) {
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

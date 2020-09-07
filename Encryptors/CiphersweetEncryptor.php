<?php
/*
 * @copyright  Copyright (C) 2020 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/doctrine-ciphersweet
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Encryptors;

use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedField;

class CiphersweetEncryptor implements EncryptorInterface
{
    private CipherSweet $engine;

    public function __construct(CipherSweet $engine)
    {
        $this->engine = $engine;
    }

    public function prepareForStorage(object $entity, string $fieldName, string $string, int $filterBits = 32): array
    {
        return (new EncryptedField($this->engine, \get_class($entity), $fieldName))
            ->addBlindIndex(
                new BlindIndex($fieldName.'_bi', [], $filterBits)
            )
            ->prepareForStorage($string);
    }

    public function decrypt(object $entity, string $fieldName, string $string, int $filterBits = 32): string
    {
        return (new EncryptedField($this->engine, \get_class($entity), $fieldName))
            ->addBlindIndex(
                new BlindIndex($fieldName.'_bi', [], $filterBits)
            )
            ->decryptValue($string);
    }

    public function getBlindIndex($entityName, $fieldName, $value, int $filterBits = 32): string
    {
        return (new EncryptedField($this->engine, $entityName, $fieldName))
            ->addBlindIndex(
                new BlindIndex($fieldName.'_bi', [], $filterBits)
            )
            ->getBlindIndex($value, $fieldName.'_bi');
    }
}

<?php
/*
 * @copyright  Copyright (C) 2020 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/doctrine-ciphersweet
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Encryptors;

/**
 * Encryptor interface for encryptors.
 */
interface EncryptorInterface
{
    /**
     * Must accept secret key for encryption.
     *
     * @param string $secretKey the encryption key
     */
    public function __construct($secretKey);

    public function prepareForStorage(object $entity, string $fieldName, string $string, int $filterBits = 32): array;

    public function decrypt(object $entity, string $fieldName, string $string, int $filterBits = 32): string;

    /**
     * @param $entityName
     * @param $fieldName
     * @param $value
     */
    public function getBlindIndex($entityName, $fieldName, $value, int $filterBits = 32): string;
}

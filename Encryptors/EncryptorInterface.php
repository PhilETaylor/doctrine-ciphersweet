<?php
/*
 * @copyright  Copyright (C) 2020 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/doctrine-ciphersweet
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Encryptors;

use ParagonIE\CipherSweet\CipherSweet;

interface EncryptorInterface
{
    public function __construct(CipherSweet $engine);

    public function prepareForStorage(object $entity, string $fieldName, string $string, int $filterBits = 32): array;

    public function decrypt(string $entity_classname, string $fieldName, string $string, int $filterBits = 32): string;

    public function getBlindIndex($entityName, $fieldName, $value, int $filterBits = 32): string;
}

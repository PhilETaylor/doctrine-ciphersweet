<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Configuration;

/**
 * The Encrypted class handles the @EncryptedWithBlindIndex annotation.
 */
class EncryptedWithBlindIndex
{
    /**
     * @var string the key nameindex to use for encryption/decryption
     */
    public $key_name;
}

<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\Encryptors;

use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedField;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\Halite\Alerts\CannotPerformOperation;
use ParagonIE\Halite\Alerts\InvalidKey;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto;
use ParagonIE\HiddenString\HiddenString;

class CiphersweetEncryptor implements EncryptorInterface
{
    /**
     * @var array of key name/filepaths
     */
    private array $enc_keys;

    private CipherSweet $engine;

    /**
     * @var HiddenString
     */
    private $enc_key;

    /**
     * @var string the name of the config param for the key to use
     */
    private $enc_key_name;

    /**
     * @var string
     */
    private $initializationVector;

    /**
     * {@inheritdoc}
     */
    public function __construct($keys)
    {
        //        dd(bin2hex(random_bytes(32)));
        $this->enc_keys = $keys;

        $provider = new StringProvider(
            trim(file_get_contents($keys['ciphersweet']))
        );

        // From this point forward, you only need your Engine:
        $this->engine = new CipherSweet($provider);
    }

    public function prepareForStorage(object $entity, string $fieldName, string $string, int $filterBits = 32)
    {
        $entityName = get_class($entity);
        dump($entityName);
        dump($fieldName);
        dump($string);
        $fieldName = (new EncryptedField($this->engine, $entityName, $fieldName))
            ->addBlindIndex(
                new BlindIndex($fieldName.'_bi', [], 32)
            );

        return $fieldName->prepareForStorage($string);
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt($data)
    {
        dd(debug_backtrace());
        dd($data);
        die;

        // already encrypted!
        if (false !== strpos($data, '<Ha>')) {
            return $data;
        }

        if (\is_string($data)) {
            $ciphertext = Crypto::encrypt(
                new HiddenString(
                    $data
                ),
                $this->enc_key
            );

            return $ciphertext.'<Ha>';
        } else {
            return $data;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt($ciphertext)
    {
        if (\is_string($ciphertext)) {
            $plaintext = Crypto::decrypt(
                $ciphertext,
                $this->enc_key
            );

            return $plaintext->getString();
        } else {
            return $ciphertext;
        }
    }

    /**
     * Choice a key from the available keys.
     *
     * @param $key_name the key name to use
     *
     * @throws CannotPerformOperation
     * @throws InvalidKey
     */
    public function setKeyName($key_name)
    {
        if (\array_key_exists($key_name, $this->enc_keys)) {
            $this->enc_key_name = $key_name;
            $this->enc_key = KeyFactory::loadEncryptionKey($this->enc_keys[$key_name]);
        }
    }
}

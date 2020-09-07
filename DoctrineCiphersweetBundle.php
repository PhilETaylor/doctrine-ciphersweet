<?php
/*
 * @copyright  Copyright (C) 2020 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/doctrine-ciphersweet
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle;

use DoctrineCiphersweetBundle\DependencyInjection\DoctrineCiphersweetExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DoctrineCiphersweetBundle extends Bundle
{
    /**
     * @return DoctrineCiphersweetExtension
     */
    public function getContainerExtension()
    {
        return new DoctrineCiphersweetExtension();
    }
}

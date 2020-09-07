<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle;

use DoctrineCiphersweetBundle\DependencyInjection\DoctrineCiphersweetExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use DoctrineCiphersweetBundle\DependencyInjection\Compiler\RegisterServiceCompilerPass;

class DoctrineCiphersweetBundle extends Bundle
{
    /**
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new RegisterServiceCompilerPass(), PassConfig::TYPE_AFTER_REMOVING);
    }

    /**
     * @return DoctrineCiphersweetExtension
     */
    public function getContainerExtension()
    {
        return new DoctrineCiphersweetExtension();
    }
}

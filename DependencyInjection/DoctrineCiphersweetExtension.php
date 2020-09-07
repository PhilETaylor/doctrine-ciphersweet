<?php
/*
 * @copyright  Copyright (C) 2017, 2018, 2019 Blue Flame Digital Solutions Limited / Phil Taylor. All rights reserved.
 * @author     Phil Taylor <phil@phil-taylor.com>
 * @see        https://github.com/PhilETaylor/mysites.guru
 * @license    MIT
 */

namespace DoctrineCiphersweetBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Initialization of bundle.
 *
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class DoctrineCiphersweetExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        //Create configuration object
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);
        //Set orm-service in array of services
        $services = ['orm' => 'orm-services'];

        $container->setParameter('doctrine_ciphersweet.encryptor_class_name', 'DoctrineCiphersweetBundle\Encryptors\CiphersweetEncryptor');
        $container->setParameter('doctrine_ciphersweet.keys', $config['keys']);
        //Load service file
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load(sprintf('%s.yml', $services['orm']));
        $loader->load('commands.yml');
    }

    /**
     * Get alias for configuration.
     *
     * @return string
     */
    public function getAlias()
    {
        return 'doctrine_ciphersweet';
    }
}

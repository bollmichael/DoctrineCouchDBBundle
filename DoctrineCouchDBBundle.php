<?php

/*
 * Doctrine CouchDB Bundle
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Doctrine\Bundle\CouchDBBundle;

use Doctrine\Common\Proxy\Autoloader;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\DoctrineValidationPass;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\RegisterEventListenersAndSubscribersPass;
use Symfony\Bridge\Doctrine\DependencyInjection\Security\UserProvider\EntityFactory;

class DoctrineCouchDBBundle extends Bundle
{
    /**
     * @var \Closure
     */
    private $autoloader;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * {@inheritDoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterEventListenersAndSubscribersPass('doctrine_couchdb.connections', 'doctrine_couchdb.odm.%s_connection.event_manager', 'doctrine'));

        if ($container->hasExtension('security')) {
            $container->getExtension('security')->addUserProviderFactory(new EntityFactory('couchdb', 'doctrine_couchdb.odm.security.user.provider'));
        }

        $container->addCompilerPass(new DoctrineValidationPass('couchdb'));
    }

    /**
     * {@inheritDoc}
     */
    public function boot()
    {
        // Register an autoloader for proxies to avoid issues when unserializing them
        // when the ORM is used.
        if ($this->container->hasParameter('doctrine_couchdb.odm.proxy_namespace')) {
            $namespace = $this->container->getParameter('doctrine_couchdb.odm.proxy_namespace');
            $dir = $this->container->getParameter('doctrine_couchdb.odm.proxy_dir');
            $proxyGenerator = null;

            if ($this->container->getParameter('doctrine_couchdb.odm.auto_generate_proxy_classes')) {
                // See https://github.com/symfony/symfony/pull/3419 for usage of references
                $container = &$this->container;

                $proxyGenerator = function ($proxyDir, $proxyNamespace, $class) use (&$container) {
                    $originalClassName = ClassUtils::getRealClass($class);
                    /** @var $registry ManagerRegistry */
                    $registry = $container->get('doctrine_couchdb');

                    // Tries to auto-generate the proxy file
                    /** @var $em \Doctrine\ORM\EntityManager */
                    foreach ($registry->getManagers() as $em) {
                        if (!$em->getConfiguration()->getAutoGenerateProxyClasses()) {
                            continue;
                        }

                        $metadataFactory = $em->getMetadataFactory();

                        if ($metadataFactory->isTransient($originalClassName)) {
                            continue;
                        }

                        $classMetadata = $metadataFactory->getMetadataFor($originalClassName);

                        $em->getProxyFactory()->generateProxyClasses(array($classMetadata));

                        clearstatcache(true, Autoloader::resolveFile($proxyDir, $proxyNamespace, $class));

                        break;
                    }
                };
            }

            $this->autoloader = Autoloader::register($dir, $namespace, $proxyGenerator);
        }
    }

    public function shutdown()
    {
        if (null !== $this->autoloader) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }

        // Clear all entity managers to clear references to entities for GC
        if ($this->container->hasParameter('doctrine_couchdb.document_managers')) {
            foreach ($this->container->getParameter('doctrine_couchdb.document_managers') as $id) {
                if ($this->container->initialized($id)) {
                    $this->container->get($id)->clear();
                }
            }
        }

        // Close all connections to avoid reaching too many connections in the process when booting again later (tests)
        if ($this->container->hasParameter('doctrine_couchdb.connections')) {
            foreach ($this->container->getParameter('doctrine_couchdb.connections') as $id) {
                if ($this->container->initialized($id)) {
                    $this->container->get($id)->close();
                }
            }
        }
    }
}

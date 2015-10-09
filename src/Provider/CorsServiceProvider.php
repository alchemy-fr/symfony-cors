<?php
/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2015 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\CorsProvider;

use Alchemy\Cors\Configuration\CorsConfiguration;
use Alchemy\Cors\CorsListener;
use Alchemy\Cors\Options\DefaultProvider;
use Alchemy\Cors\Options\DefaultResolver;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Definition\Processor;

class CorsServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['alchemy_cors.cache_path'] = null;
        $app['alchemy_cors.debug'] = false;

        $app['alchemy_cors.defaults'] = null;
        $app['alchemy_cors.map'] = null;

        $app['alchemy_cors.config'] = $app->share(function (Application $app) {
            $config = null;

            if ('' != $cachePath = $app['alchemy_cors.cache_path']) {
                $config = new ConfigCache($cachePath, $app['alchemy_cors.debug']);

                if ($config->isFresh()) {
                    return unserialize(file_get_contents($cachePath));
                }
            }

            $processor = new Processor();
            $configuration = new CorsConfiguration();

            $processed = $processor->processConfiguration($configuration, array(
                'alchemy_cors' => array(
                    'defaults' => $app['alchemy_cors.defaults'],
                    'paths' => $app['alchemy_cors.map'],
                ),
            ));

            if ($config) {
                $config->write(serialize($processed));
            }

            return $processed;
        });

        $app['alchemy_cors.options_provider.config'] = function (Application $app) {
            $config = $app['alchemy_cors.config'];

            return new DefaultProvider($config['paths'], $config['defaults']);
        };
        $app['alchemy_cors.options_providers'] = array(
            array('priority' => -1, 'service' => 'alchemy_cors.options_provider.config'),
        );

        $that = $this;
        $app['alchemy_cors.options_resolver'] = $app->share(function (Application $app) use ($that) {
            $providers = array();

            foreach ($that->sortProviders($app['alchemy_cors.options_providers']) as $serviceNames) {
                foreach ($serviceNames as $serviceName) {
                    $providers[] = $app[$serviceName];
                }
            }

            return new DefaultResolver($providers);
        });

        $app['alchemy_cors.listener'] = $app->share(function (Application $app) {
            return new CorsListener($app['dispatcher'], $app['alchemy_cors.options_resolver']);
        });
    }

    public function boot(Application $app)
    {
        $app->before(array($app['alchemy_cors.listener'], 'onKernelRequest'), 10000);
    }

    public function sortProviders(array $providers)
    {
        $providersByPriority = array();

        foreach ($providers as $service) {
            $priority = 0;

            if (is_array($service)) {
                if (!isset($service['service'])) {
                    throw new \InvalidArgumentException(
                        'Providers should be a string or an array with provider key'
                    );
                }
                if (isset($service['priority'])) {
                    $priority = $service['priority'];
                }
                $service = $service['service'];
            }

            if (!isset($providersByPriority[$priority])) {
                $providersByPriority[$priority] = array();
            }

            $providersByPriority[$priority][] = $service;
        }

        ksort($providersByPriority);

        return $providersByPriority;
    }
}

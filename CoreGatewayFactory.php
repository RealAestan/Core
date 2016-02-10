<?php
namespace Payum\Core;

use Payum\Core\Action\CapturePaymentAction;
use Payum\Core\Action\ExecuteSameRequestWithModelDetailsAction;
use Payum\Core\Action\GetCurrencyAction;
use Payum\Core\Action\GetTokenAction;
use Payum\Core\Bridge\Guzzle\HttpClientFactory;
use Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Bridge\Twig\Action\RenderTemplateAction;
use Payum\Core\Extension\EndlessCycleDetectorExtension;

class CoreGatewayFactory implements GatewayFactoryInterface
{
    /**
     * @var array
     */
    protected $defaultConfig;

    /**
     * @var \Twig_Loader_Filesystem
     */
    protected $twigLoader;

    /**
     * @param array $defaultConfig
     */
    public function __construct(array $defaultConfig = array())
    {
        $this->defaultConfig = $defaultConfig;
        $this->twigLoader = new \Twig_Loader_Filesystem();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $config = array())
    {
        $config = ArrayObject::ensureArrayObject($config);
        $config->defaults($this->createConfig());

        $gateway = new Gateway();

        $this->buildClosures($config);

        $this->buildActions($gateway, $config);
        $this->buildApis($gateway, $config);
        $this->buildExtensions($gateway, $config);

        return $gateway;
    }

    /**
     * {@inheritDoc}
     */
    public function createConfig(array $config = array())
    {
        $config = ArrayObject::ensureArrayObject($config);
        $config->defaults($this->defaultConfig);

        /** @var \Twig_Environment|null $twig */
        $twig = $config['twig.env'];
        $config['twig.env'] = null;

        $config->defaults(array(
            'payum.template.layout' => '@PayumCore/layout.html.twig',

            'payum.http_client' => HttpClientFactory::create(),
            'guzzle.client' => HttpClientFactory::createGuzzle(),
            'twig.loader' => function(ArrayObject $config) {
                foreach ($config['payum.paths'] as $namespace => $path) {
                    $this->twigLoader->addPath($path, $namespace);
                }

                return $this->twigLoader;
            },
            'twig.env' => function(ArrayObject $config) use ($twig) {
                if ($twig) {
                    $twig->setLoader(new \Twig_Loader_Chain([$twig->getLoader(), $config['twig.loader']]));

                    return $twig;
                } else {
                    return new \Twig_Environment($config['twig.loader']);
                }
            },
            'payum.action.get_http_request' => new GetHttpRequestAction(),
            'payum.action.capture_payment' => new CapturePaymentAction(),
            'payum.action.execute_same_request_with_model_details' => new ExecuteSameRequestWithModelDetailsAction(),
            'payum.action.render_template' => function (ArrayObject $config) {
                return new RenderTemplateAction($config['twig.env'], $config['payum.template.layout']);
            },
            'payum.extension.endless_cycle_detector' => new EndlessCycleDetectorExtension(),
            'payum.action.get_currency' => function (ArrayObject $config) {
                return new GetCurrencyAction($config['payum.iso4217']);
            },
            'payum.prepend_actions' => array(),
            'payum.prepend_extensions' => array(),
            'payum.prepend_apis' => array(),
            'payum.default_options' => array(),
            'payum.required_options' => array(),

            'payum.api.http_client' => function (ArrayObject $config) {
                return $config['payum.http_client'];
            },

            'payum.security.token_storage' => null,
        ));

        if ($config['payum.security.token_storage']) {
            $config['payum.action.get_token'] = function(ArrayObject $config) {
               return new GetTokenAction($config['payum.security.token_storage']);
            };
        }

        $config['payum.paths'] = array_replace([
            'PayumCore' => __DIR__.'/Resources/views',
        ], $config['payum.paths'] ?: []);

        return (array) $config;
    }

    /**
     * @param ArrayObject $config
     */
    protected function buildClosures(ArrayObject $config)
    {
        // with higher priority
        foreach (['guzzle.client', 'payum.http_client', 'twig.loader', 'payum.paths', 'twig.env'] as $name) {
            $value = $config[$name];
            if (is_callable($value)) {
                $config[$name] = call_user_func($value, $config);
            }
        }

        foreach ($config as $name => $value) {
            if (is_callable($value)) {
                $config[$name] = call_user_func($value, $config);
            }
        }
    }

    /**
     * @param Gateway     $gateway
     * @param ArrayObject $config
     */
    protected function buildActions(Gateway $gateway, ArrayObject $config)
    {

        foreach ($config as $name => $value) {
            if (0 === strpos($name, 'payum.action')) {
                $prepend = in_array($name, $config['payum.prepend_actions']);

                $gateway->addAction($value, $prepend);
            }
        }
    }

    /**
     * @param Gateway     $gateway
     * @param ArrayObject $config
     */
    protected function buildApis(Gateway $gateway, ArrayObject $config)
    {
        foreach ($config as $name => $value) {
            if (0 === strpos($name, 'payum.api')) {
                $prepend = in_array($name, $config['payum.prepend_apis']);

                $gateway->addApi($value, $prepend);
            }
        }
    }

    /**
     * @param Gateway     $gateway
     * @param ArrayObject $config
     */
    protected function buildExtensions(Gateway $gateway, ArrayObject $config)
    {
        foreach ($config as $name => $value) {
            if (0 === strpos($name, 'payum.extension')) {
                $prepend = in_array($name, $config['payum.prepend_extensions']);

                $gateway->addExtension($value, $prepend);
            }
        }
    }
}

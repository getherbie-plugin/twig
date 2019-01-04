<?php

namespace herbie\plugin\twig;

use Herbie\Page;
use herbie\plugin\twig\classes\HerbieExtension;
use Herbie\StringValue;
use Twig_Environment;
use Twig_Extension_Debug;
use Twig_Loader_Array;
use Twig_Loader_Chain;
use Twig_Loader_Filesystem;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;

class TwigPlugin extends \Herbie\Plugin
{

    /** @var EventManagerInterface */
    private $events;

    /**
     * @var \Twig_Environment
     */
    private $twigEnvironment;

    /**
     * @var boolean
     */
    private $initialized = false;

    /**
     * @param EventManagerInterface $events
     * @param int $priority
     */
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->events = $events;
        $events->attach('onPluginsInitialized', [$this, 'onPluginsInitialized'], $priority);
        $events->attach('onRenderContent', [$this, 'onRenderContent'], $priority);
        $events->attach('onRenderLayout', [$this, 'onRenderLayout'], $priority);
    }

    /**
     * @param EventInterface $event
     */
    public function onPluginsInitialized(EventInterface $event)
    {
        #$config = $this->getConfig();

        // Add custom namespace path to Imagine lib
        #$vendorDir = $config->get('site.path') . '/../vendor';
        #$autoload = require($vendorDir . '/autoload.php');
        #$autoload->add('Twig_', __DIR__ . '/vendor/twig/twig/lib');

        $this->init();
    }

    /**
     * @param EventInterface $event
     */
    public function onRenderContent(EventInterface $event)
    {
        $twig = $event->getParam('twig');
        if (empty($twig)) {
            return;
        }
        /** @var StringValue $stringValue */
        $stringValue = $event->getTarget();
        $parsed = $this->renderString($stringValue->get());
        $stringValue->set($parsed);
    }

    /**
     * @param EventInterface $event
     */
    public function onRenderLayout(EventInterface $event)
    {
        /** @var StringValue $stringValue */
        $stringValue = $event->getTarget();

        /** @var \Herbie\Page $page */
        $page = $event->getParam('page');

        $config = $this->getConfig();
        $this->twigEnvironment
            ->getExtension(HerbieExtension::class)
            ->setPage($page);
        $extension = trim($config->get('layouts.extension'));
        $layout = empty($extension) ? $page->layout : sprintf('%s.%s', $page->layout, $extension);
        $stringValue->set($this->render($layout));
    }

    public function init()
    {
        $loader = $this->getTwigFilesystemLoader();
        $this->twigEnvironment = new Twig_Environment($loader, [
            'debug' => $this->getConfig()->get('twig.debug'),
            'cache' => $this->getConfig()->get('twig.cache')
        ]);
        if (!$this->getConfig()->isEmpty('twig.debug')) {
            $this->twigEnvironment->addExtension(new Twig_Extension_Debug());
        }

        $herbieExtension = new HerbieExtension(
            $this->getAlias(),
            $this->getConfig(),
            $this->getRequest(),
            $this->getUrlGenerator(),
            $this->getSlugGenerator(),
            $this->getAssets(),
            $this->getMenuList(),
            $this->getMenuTree(),
            $this->getMenuRootPath(),
            $this->getEnvironment(),
            $this->getDataRepository(),
            $this->getTranslator(),
            $this
        );

        $this->twigEnvironment->addExtension($herbieExtension);
        $this->addTwigPlugins();

        /*
        foreach (Hook::trigger(Hook::CONFIG, 'addTwigFunction') as $function) {
            try {
                list($name, $callable, $options) = $function;
                $this->twigEnvironment->addFunction(new \Twig_SimpleFunction($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }

        foreach (Hook::trigger(Hook::CONFIG, 'addTwigFilter') as $filter) {
            try {
                list($name, $callable, $options) = $filter;
                $this->twigEnvironment->addFilter(new \Twig_SimpleFilter($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }

        foreach (Hook::trigger(Hook::CONFIG, 'addTwigTest') as $test) {
            try {
                list($name, $callable, $options) = $test;
                $this->twigEnvironment->addTest(new \Twig_SimpleTest($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }
        */

        $this->setTwig($this);
        $this->initialized = true;
        $this->events->trigger('onTwigInitialized', $this->twigEnvironment);
    }

    /**
     * @param string $name
     * @param array $context
     * @return string
     */
    public function render($name, array $context = [])
    {
        $context = array_merge($context, $this->getContext());
        return $this->twigEnvironment->render($name, $context);
    }

    /**
     * Renders a page content segment.
     * @param string $segmentId
     * @param Page $page
     * @return string
     */
    public function renderPageSegment(string $segmentId, Page $page)
    {
        /*if (is_null($page)) {
            $page = $this->getPage();
        }*/

        $segment = $page->getSegment($segmentId);

        $this->events->trigger('onRenderContent', $segment, $page->getData());

        return $segment;
    }

    /**
     * @param string $string
     * @return string
     */
    public function renderString($string)
    {
        // no rendering if empty
        if (empty($string)) {
            return $string;
        }
        // see Twig\Extensions\Twig_Extension_StringLoader
        $name = '__twig_string__';
        // get current loader
        $loader = $this->twigEnvironment->getLoader();
        // set loader chain with new array loader
        $this->twigEnvironment->setLoader(new Twig_Loader_Chain([new Twig_Loader_Array([$name => $string]), $loader]));
        // render string
        $context = $this->getContext();
        $rendered = $this->twigEnvironment->render($name, $context);
        // reset current loader
        $this->twigEnvironment->setLoader($loader);
        return $rendered;
    }

    /**
     * @return array
     */
    private function getContext()
    {
        return [
            'route' => $this->getEnvironment()->getRoute(),
            'baseUrl' => $this->getEnvironment()->getBaseUrl(),
            'theme' => $this->getConfig()->get('theme')
        ];
    }

    /**
     * @return void
     */
    public function addTwigPlugins()
    {
        if ($this->getConfig()->isEmpty('twig.extend')) {
            return;
        }
        // Functions
        $dir = $this->getConfig()->get('twig.extend.functions');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->twigEnvironment->addFunction($included);
        }
        // Filters
        $dir = $this->getConfig()->get('twig.extend.filters');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->twigEnvironment->addFilter($included);
        }
        // Tests
        $dir = $this->getConfig()->get('twig.extend.tests');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->twigEnvironment->addTest($included);
        }
    }

    /**
     * @return Twig_Loader_Filesystem
     * @throws \Twig_Error_Loader
     */
    private function getTwigFilesystemLoader()
    {
        $paths = [];
        if ($this->getConfig()->isEmpty('theme')) {
            $paths[] = $this->getConfig()->get('layouts.path');
        } elseif ($this->getConfig()->get('theme') == 'default') {
            $paths[] = $this->getConfig()->get('layouts.path') . '/default';
        } else {
            $paths[] = $this->getConfig()->get('layouts.path') . '/' . $this->getConfig()->get('theme');
            $paths[] = $this->getConfig()->get('layouts.path') . '/default';
        }

        $loader = new Twig_Loader_Filesystem($paths);

        // namespaces
        $namespaces = [
            'plugin' => $this->getConfig()->get('plugins.path'),
            'page' => $this->getConfig()->get('pages.path'),
            'post' => $this->getConfig()->get('posts.path'),
            'site' => $this->getConfig()->get('site.path'),
            'widget' => __DIR__ . '/classes/widgets'
        ];
        foreach ($namespaces as $namespace => $path) {
            if (is_readable($path)) {
                $loader->addPath($path, $namespace);
            }
        }

        return $loader;
    }

    /**
     * @param string $file
     * @return string
     */
    private function includePhpFile($file)
    {
        return include($file);
    }

    /**
     * @param string $dir
     * @return array
     */
    private function readPhpFiles($dir)
    {
        $dir = rtrim($dir, '/');
        if (empty($dir) || !is_readable($dir)) {
            return [];
        }
        $pattern = $dir . '/*.php';
        return glob($pattern);
    }

    /**
     * @return bool
     */
    public function isInitialized()
    {
        return true === $this->initialized;
    }

    public function __debugInfo()
    {
        return [
            'config' => call_user_func('get_object_vars', $this->getConfig()),
            'environment' => call_user_func('get_object_vars', $this->twigEnvironment),
            'initialized' => $this->initialized
        ];
    }

    public function getTwigEnvironment()
    {
        return $this->twigEnvironment;
    }
}

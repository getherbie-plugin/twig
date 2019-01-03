<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <https://www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\twig\classes;

use Herbie\Application;
use Herbie\Page;
use Twig_Environment;
use Twig_Extension_Debug;
use Twig_Loader_Chain;
use Twig_Loader_Filesystem;
use Twig_Loader_Array;

class Twig
{
    /**
     * @var Application
     */
    private $herbie;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Twig_Environment
     */
    private $environment;

    /**
     * @var boolean
     */
    private $initialized;

    /**
     * Constructor
     *
     * @param Application $herbie
     */
    public function __construct(Application $herbie)
    {
        $this->herbie = $herbie;
        $this->config = $herbie->getConfig();
        $this->initialized = false;
    }

    public function init()
    {
        $loader = $this->getTwigFilesystemLoader();
        $this->environment = new Twig_Environment($loader, [
            'debug' => $this->config->get('twig.debug'),
            'cache' => $this->config->get('twig.cache')
        ]);
        if (!$this->config->isEmpty('twig.debug')) {
            $this->environment->addExtension(new Twig_Extension_Debug());
        }
        $this->environment->addExtension(new HerbieExtension($this->herbie));
        $this->addTwigPlugins();

        /*
        foreach (Hook::trigger(Hook::CONFIG, 'addTwigFunction') as $function) {
            try {
                list($name, $callable, $options) = $function;
                $this->environment->addFunction(new \Twig_SimpleFunction($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }

        foreach (Hook::trigger(Hook::CONFIG, 'addTwigFilter') as $filter) {
            try {
                list($name, $callable, $options) = $filter;
                $this->environment->addFilter(new \Twig_SimpleFilter($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }

        foreach (Hook::trigger(Hook::CONFIG, 'addTwigTest') as $test) {
            try {
                list($name, $callable, $options) = $test;
                $this->environment->addTest(new \Twig_SimpleTest($name, $callable, (array)$options));
            } catch (\Exception $e) {
                ; //do nothing else yet
            }
        }
        */

        $this->initialized = true;
    }

    /**
     * @return Twig_Environment
     */
    public function getEnvironment()
    {
        return $this->environment;
    }

    /**
     * @param string $name
     * @param array $context
     * @return string
     */
    public function render($name, array $context = [])
    {
        $context = array_merge($context, $this->getContext());
        return $this->environment->render($name, $context);
    }

    /**
     * Renders a page content segment.
     * @param string $segmentId
     * @param Page $page
     * @return string
     */
    public function renderPageSegment(string $segmentId, Page $page)
    {
        if (is_null($page)) {
            $page = $this->herbie->getPage();
        }

        $segment = $page->getSegment($segmentId);

        $this->herbie->getPluginManager()->trigger('onRenderContent', $segment, $page->getData());

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
        $loader = $this->environment->getLoader();
        // set loader chain with new array loader
        $this->environment->setLoader(new Twig_Loader_Chain([new Twig_Loader_Array([$name => $string]), $loader]));
        // render string
        $context = $this->getContext();
        $rendered = $this->environment->render($name, $context);
        // reset current loader
        $this->environment->setLoader($loader);
        return $rendered;
    }

    /**
     * @return array
     */
    private function getContext()
    {
        return [
            'route' => $this->herbie->getRoute(),
            'baseUrl' => $this->herbie->getEnvironment()->getBaseUrl(),
            'theme' => $this->config->get('theme')
        ];
    }

    /**
     * @return void
     */
    public function addTwigPlugins()
    {
        if ($this->config->isEmpty('twig.extend')) {
            return;
        }
        // Functions
        $dir = $this->config->get('twig.extend.functions');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->environment->addFunction($included);
        }
        // Filters
        $dir = $this->config->get('twig.extend.filters');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->environment->addFilter($included);
        }
        // Tests
        $dir = $this->config->get('twig.extend.tests');
        foreach ($this->readPhpFiles($dir) as $file) {
            $included = $this->includePhpFile($file);
            $this->environment->addTest($included);
        }
    }

    /**
     * @return Twig_Loader_Filesystem
     */
    private function getTwigFilesystemLoader()
    {
        $paths = [];
        if ($this->config->isEmpty('theme')) {
            $paths[] = $this->config->get('layouts.path');
        } elseif ($this->config->get('theme') == 'default') {
            $paths[] = $this->config->get('layouts.path') . '/default';
        } else {
            $paths[] = $this->config->get('layouts.path') . '/' . $this->config->get('theme');
            $paths[] = $this->config->get('layouts.path') . '/default';
        }

        $loader = new Twig_Loader_Filesystem($paths);

        // namespaces
        $namespaces = [
            'plugin' => $this->config->get('plugins.path'),
            'page' => $this->config->get('pages.path'),
            'post' => $this->config->get('posts.path'),
            'site' => $this->config->get('site.path'),
            'widget' => __DIR__ . '/widgets'
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
            'config' => call_user_func('get_object_vars', $this->config),
            'environment' => call_user_func('get_object_vars', $this->environment),
            'initialized' => $this->initialized
        ];
    }
}

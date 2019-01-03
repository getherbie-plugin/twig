<?php

namespace herbie\plugin\twig;

use herbie\plugin\twig\classes\Twig;
use Herbie\StringValue;
use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;

class TwigPlugin extends \Herbie\Plugin
{
    /** @var Twig */
    private $twig;

    /** @var EventManagerInterface */
    private $events;

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
        #$config = $this->herbie->getConfig();

        // Add custom namespace path to Imagine lib
        #$vendorDir = $config->get('site.path') . '/../vendor';
        #$autoload = require($vendorDir . '/autoload.php');
        #$autoload->add('Twig_', __DIR__ . '/vendor/twig/twig/lib');

        $this->twig = new Twig($this->herbie);
        $this->twig->init();
        $this->herbie->setTwig($this->twig);
        $this->events->trigger('onTwigInitialized', $this->twig->getEnvironment());
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
        $parsed = $this->twig->renderString($stringValue->get());
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

        $config = $this->herbie->getConfig();
        $this->twig->getEnvironment()
            ->getExtension('herbie\\plugin\\twig\\classes\\HerbieExtension')
            ->setPage($page);
        $extension = trim($config->get('layouts.extension'));
        $layout = empty($extension) ? $page->layout : sprintf('%s.%s', $page->layout, $extension);
        $stringValue->set($this->twig->render($layout));
    }

}

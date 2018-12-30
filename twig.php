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

    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->events = $events;
        $events->attach('pluginsInitialized', [$this, 'initTwig'], $priority);
        $events->attach('renderContent', [$this, 'twigifyContent'], $priority);
        $events->attach('renderLayout', [$this, 'twigifyLayout'], $priority);
    }

    public function initTwig()
    {
        $config = $this->herbie->getConfig();

        // Add custom namespace path to Imagine lib
        $vendorDir = $config->get('site.path') . '/../vendor';
        $autoload = require($vendorDir . '/autoload.php');
        $autoload->add('Twig_', __DIR__ . '/vendor/twig/twig/lib');

        $this->twig = new Twig($this->herbie);
        $this->twig->init();
        $this->herbie->setTwig($this->twig);
        $this->events->trigger('twigInitialized', $this->twig->getEnvironment());
    }

    public function twigifyContent(EventInterface $event)
    {
        $twig = $event->getParam('twig');
        if (empty($twig)) {
            return;
        }
        /** @var StringValue $content */
        $content = $event->getTarget();
        $parsed = $this->twig->renderString($content);
        $content->set($parsed);
    }

    public function twigifyLayout(EventInterface $event)
    {
        /** @var StringValue $content */
        $content = $event->getTarget();

        /** @var \Herbie\Page $page */
        $page = $event->getParam('page');

        $config = $this->herbie->getConfig();
        $this->twig->getEnvironment()
            ->getExtension('herbie\\plugin\\twig\\classes\\HerbieExtension')
            ->setPage($page);
        $extension = trim($config->get('layouts.extension'));
        $layout = empty($extension) ? $page->layout : sprintf('%s.%s', $page->layout, $extension);
        $content->set($this->twig->render($layout));
    }

}

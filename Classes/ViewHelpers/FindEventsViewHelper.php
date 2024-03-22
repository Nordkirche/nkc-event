<?php

namespace  Nordkirche\NkcEvent\ViewHelpers;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\NkcBase\Exception\ApiException;
use Nordkirche\NkcBase\Service\ApiService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;

class FindEventsViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Initialize arguments.
     *
     * @throws Exception
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('institution', 'int', 'Find events by institution id');
        $this->registerArgument('location', 'int', 'Find events by location id');
        $this->registerArgument('search', 'int', 'Find events by fulltext query');
    }

    /**
     * @return string
     * @throws ApiException
     */
    public function render()
    {
        $api = ApiService::get();
        $eventRepository = $api->factory(EventRepository::class);
        $query = new EventQuery();

        if ($this->arguments['institution']) {
            $query->setOrganizers([$this->arguments['institution']]);
        }

        if ($this->arguments['location']) {
            $query->setLocation($this->arguments['location']);
        }

        if ($this->arguments['search']) {
            $query->setQuery($this->arguments['search']);
        }

        $events = $eventRepository->get($query);

        if ($events->count()) {
            $this->templateVariableContainer->add('events', $events);

            $output =  $this->renderChildren();

            $this->templateVariableContainer->remove('events');

            return $output;
        }
    }
}

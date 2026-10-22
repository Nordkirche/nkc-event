<?php

declare(strict_types=1);

namespace Nordkirche\NkcEvent\ViewHelpers;

use Nordkirche\Ndk\Domain\Query\EventQuery;
use Nordkirche\Ndk\Domain\Repository\EventRepository;
use Nordkirche\NkcBase\Service\NdkApiFactory;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Upcoming events of an institution, a location or a full text search.
 * The children are rendered with the variable "events" if there are events.
 *
 * <nke:findEvents institution="{institution.id}">{events.recordCount} Veranstaltungen</nke:findEvents>
 */
class FindEventsViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function __construct(private readonly NdkApiFactory $ndkApiFactory) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('institution', 'mixed', 'Id of the organizing institution');
        $this->registerArgument('location', 'string', 'Name of the location');
        $this->registerArgument('search', 'string', 'Full text query');
    }

    public function render(): string
    {
        $query = new EventQuery();
        if ($this->arguments['institution']) {
            $query->setOrganizers([(int)$this->arguments['institution']]);
        }
        if ($this->arguments['location']) {
            $query->setLocation((string)$this->arguments['location']);
        }
        if ($this->arguments['search']) {
            $query->setQuery((string)$this->arguments['search']);
        }
        $query->setTimeFromStart(new \DateTime('today'));

        try {
            $events = $this->ndkApiFactory->getApi()->factory(EventRepository::class)->get($query);
        } catch (\Throwable) {
            return '';
        }
        if (!$events->count()) {
            return '';
        }

        $variableProvider = $this->renderingContext->getVariableProvider();
        $variableProvider->add('events', $events);
        $output = (string)$this->renderChildren();
        $variableProvider->remove('events');
        return $output;
    }
}

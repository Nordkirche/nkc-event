<?php

namespace  Nordkirche\NkcEvent\ViewHelpers;

use Nordkirche\Ndk\Domain\Model\Address;
use Nordkirche\Ndk\Domain\Model\Event\Event;
use Nordkirche\Ndk\Domain\Model\File\Image;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;

class JsonViewHelper extends AbstractViewHelper
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
        $this->registerArgument('event', 'object', 'Event object', false);
    }

    /**
     * @return string
     */
    public function render()
    {
        /** @var Event $event */
        $event = $this->arguments['event'];

        if ($event === null) {
            $event = $this->renderChildren();
        }

        if ($event instanceof Event) {
            if ($event->getPicture() instanceof Image) {
                $image = $event->getPicture()->render(600);
            } else {
                $image = '';
            }

            $result = [
                '@context'  => 'https://schema.org',
                '@type'     => 'Event',
                'location' => [
                    '@type' => 'Place',
                    'name'  => $event->getLocationName(),
                ],
                'name' => $event->getTitle(),
                'description' => $event->getDescription(),
                'image' => $image,
                'startDate' => $event->getStartsAt()->format("Y-m-d\TH:i"),
            ];

            if ($event->getAddress() instanceof Address) {
                $result['location']['address'] = [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $event->getAddress()->getCity(),
                    'postalCode' => $event->getAddress()->getZipCode(),
                    'streetAddress' => $event->getAddress()->getStreet(),
                ];
            }

            $price = $event->getPrice();

            if (isset($price['range']['from']) || isset($price['range']['to'])) {
                $result['offers'] = [
                    '@type' => 'Offer',
                    'price' => (bool)$price['range']['from'] ? $price['range']['from'] : $price['range']['to'],
                    'priceCurrency' => 'EUR',
                ];

                if ($event->getRegistrationLink() != '') {
                    $result['offers']['url'] = $event->getRegistrationLink();
                }
            }

            return json_encode($result);
        }
        return json_encode([]);
    }
}

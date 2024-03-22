<?php

namespace Nordkirche\NkcEvent\Service;

use Nordkirche\Ndk\Domain\Model\Event\Event;
use TYPO3\CMS\Core\SingletonInterface;

class ExportService implements SingletonInterface
{
    /**
     * Prefix for iCalendar files
     */
    public const VCALENDAR_START = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:netl_events TYPO3 Extension\nMETHOD:PUBLISH";

    /**
     * Postfix for iCalendar files
     */
    public const VCALENDAR_END = 'END:VCALENDAR';

    /**
     * Render the iCalendar events with the required wrap
     *
     * @param  $events
     * @param  string $filename
     * @throws \Exception
     */
    public static function renderCalendar($events, $filename = 'calendar.ics')
    {
        $eventExport = '';

        foreach ($events as $event) {
            $eventExport .= self::iCalendarData($event);
        }

        $content = implode("\n", [
            self::VCALENDAR_START,
            $eventExport,
            self::VCALENDAR_END,
        ]);
        self::setIcalHeaders($content, $filename);

        echo $content;
        die;
    }

    /**
     * Return an iCalendar file as string representation suitable for sending to the client
     *
     * @param Event $event
     * @return string $iCalendarData
     */
    public static function iCalendarData($event)
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $timezone = new \DateTimeZone('Europe/Berlin');

        /** @var \DateTime $startDate */
        $startDate = new \DateTime($event->getStartsAt()->format('Y-m-d H:i'), $timezone);
        $startDate->setTimezone(new \DateTimeZone('UTC'));

        /** @var \DateTime $stopDate */
        $stopDate = new \DateTime($event->getEndsAt()->format('Y-m-d H:i'), $timezone);
        $stopDate->setTimezone(new \DateTimeZone('UTC'));

        if ($startDate == $stopDate) {
            $stopDate->add(new \DateInterval('PT1H'));
        }

        $iCalData = [];

        $iCalData[] = 'BEGIN:VEVENT';
        $iCalData[] = 'UID:' . self::getUniqueIdentifier($event);
        $iCalData[] = 'LOCATION:' . self::escapeTextForIcal($event->getLocationName());
        $iCalData[] = 'SUMMARY:' . self::escapeTextForIcal($event->getTitle());
        $iCalData[] = 'DESCRIPTION:' . self::escapeTextForIcal($event->getDescription());
        $iCalData[] = 'CLASS:PUBLIC';

        $iCalData[] = 'DTSTART:' . $startDate->format('Ymd\THis\Z');
        $iCalData[] = 'DTEND:' . $stopDate->format('Ymd\THis\Z');

        $iCalData[] = 'DTSTAMP:' . $now->format('Ymd\THis\Z');

        $iCalData[] = 'END:VEVENT';

        return implode("\r\n", $iCalData);
    }

    /**
     * Escapes given text for usage in ical format.
     *
     * @param $textInput
     * @return mixed|string
     *
     * @see http://www.ietf.org/rfc/rfc2445.txt
     */
    public static function escapeTextForIcal($textInput)
    {
        $text = html_entity_decode(strip_tags($textInput), ENT_COMPAT | ENT_HTML401, 'UTF-8');
        return str_replace(
            ['"', '\\', ',', ':', ';', "\n"],
            ['DQUOTE', '\\\\', "\,", '":"', "\;", '\\n'],
            $text
        );
    }

    /**
     * Return a unique identifier
     *
     * @param Event $event
     * @return string
     */
    public static function getUniqueIdentifier($event)
    {
        return md5($event->getId() . ':' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
    }

    /**
     * Set content headers for the iCalendar data
     *
     * @param string $content
     * @param string $filename
     * @throws \Exception
     */
    public static function setIcalHeaders($content, $filename)
    {
        if (ob_get_contents()) {
            throw new \Exception('Some data has already been sent to the browser', 1408607681);
        }
        header('Content-Type: text/calendar');
        if (headers_sent()) {
            throw new \Exception('Some data has already been sent to the browser', 1408607681);
        }

        header('Cache-Control: public');
        header('Pragma: public');
        header('Content-Description: iCalendar Event File');
        header('Content-Transfer-Encoding: binary');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if (!isset($_SERVER['HTTP_ACCEPT_ENCODING']) or empty($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            header('Content-Length: ' . strlen($content));
        }
    }
}

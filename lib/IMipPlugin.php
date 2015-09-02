<?php

/**
 * @license
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Mireau\CalDav\Schedule;

use Sabre\Katana\Configuration;
use Sabre\CalDAV as SabreCalDav;
use Sabre\VObject\ITip;
use Hoa\File;
use Hoa\Mail;
use Hoa\Socket;
use Hoa\Stringbuffer;

/**
 * IMip plugin, with our own emails.
 * based on sabre/katana IMipPlugin
 */
class IMipPlugin extends SabreCalDav\Schedule\IMipPlugin {

	var $smtpHost, $smtpPort, $smtpUser, $smtpPass;

    /**
     * Set up the configuration.
     *
     * @param  Configuration  $configuration    Configuration.
     * @return void
     */
    function __construct($smtpHost, $smtpPort, $smtpUser, $smtpPass) {
		$this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort;
        $this->smtpUser = $smtpUser;
        $this->smtpPass = $smtpPass;
		$uselessEmail = "dummy@mireau.com";
        parent::__construct($uselessEmail);
    }

    /**
     * Send the IMip message.
     *
     * @param  ITip\Message  $itip    ITip message.
     * @return void
     */
    function schedule(ITip\Message $itip) {

        // Not sending any emails if the system considers the update
        // insignificant.
        if (false === $itip->significantChange) {
            if (empty($itip->scheduleStatus)) {
                $itip->scheduleStatus = '1.0;We got the message, but it\'s not significant enough to warrant an email.';
            }

            return;
        }

        $deliveredLocally = '1.2' === $itip->getScheduleStatus();

        $senderName    = $itip->senderName;
        $recipientName = $itip->recipientName;

        // 7 is the length of `mailto:`.
        $senderEmail    = substr($itip->sender, 7);
        $recipientEmail = substr($itip->recipient, 7);

        $subject = 'SabreDAV iTIP message';
        $summary = (string)$itip->message->VEVENT->SUMMARY;


        switch (strtoupper($itip->method)) {
            case 'REPLY' :
                // In the case of a reply, we need to find the `PARTSTAT` from
                // the user.
                $partstat = (string)$itip->message->VEVENT->ATTENDEE['PARTSTAT'];

                switch (strtoupper($partstat)) {
                    case 'DECLINED':
                        $subject = $senderName . ' declined your invitation to "' . $summary . '"';
                        $action  = 'DECLINED';

                        break;

                    case 'ACCEPTED':
                        $subject = $senderName . ' accepted your invitation to "' . $summary . '"';
                        $action  = 'ACCEPTED';

                        break;

                    case 'TENTATIVE':
                        $subject = $senderName . ' tentatively accepted your invitation to "' . $summary . '"';
                        $action  = 'TENTATIVE';

                        break;

                    default:
                        $itip->scheduleStatus = '5.0;Email not deliered. We didn\'t understand this PARTSTAT.';
						error_log("unknown attendee reply PARTSTAT : ".$partstat);

                        return;
                }

                break;

            case 'REQUEST':
                $subject = $senderName . ' invited you to "' . $summary . '"';
                $action  = 'REQUEST';

                break;

            case 'CANCEL':
                $subject = '"' . $summary . '" has been canceled.';
                $action  = 'CANCEL';

                break;

            default:
                $itip->scheduleStatus = '5.0;Email not delivered. Unsupported METHOD.';

                return;
        }
		//Supression des retours à la ligne
		$subject = str_replace(array("\r\n", "\n", "\r"),' ', $subject);

        $streamsToClose = [];
		$logo = null;
		$logoUrl = null;
/*
        $logo = new Mail\Content\Attachment(
            $streamsToClose[] = new File\Read('logo_full.png'),
            'Logo_of_sabre_katana.png'
        );
        $logoUrl = $logo->getIdUrl();
*/
        $dateTime =
            isset($itip->message->VEVENT->DTSTART)
                ? $itip->message->VEVENT->DTSTART->getDateTime()
                : new \DateTime('now');

        $allDay =
            isset($itip->message->VEVENT->DTSTART) &&
            false === $itip->message->VEVENT->DTSTART->hasTime();

        $attendees = [];

        if (isset($itip->message->VEVENT->ATTENDEE)) {
            $_attendees = &$itip->message->VEVENT->ATTENDEE;

            for ($i = 0, $max = count($_attendees); $i < $max; ++$i) {
                $attendee    = $_attendees[$i];
                $attendees[] = [
                    'cn' =>
                        isset($attendee['CN'])
                            ? (string)$attendee['CN']
                            : (string)$attendee['EMAIL'],
                    'email' =>
                        isset($attendee['EMAIL'])
                            ? (string)$attendee['EMAIL']
                            : null,
                    'role' =>
                        isset($attendee['ROLE'])
                            ? (string)$attendee['ROLE']
                            : null
                ];
            }
        }

        usort(
            $attendees,
            function($a, $b) {
                if ('CHAIR' === $a['role']) {
                    return -1;
                }

                return 1;
            }
        );

        $notEmpty = function($property, $else) use ($itip) {
            if (isset($itip->message->VEVENT->$property)) {
                $handle = (string)$itip->message->VEVENT->$property;

                if (!empty($handle)) {
                    return $handle;
                }
            }

            return $else;
        };

        $url         = $notEmpty('URL', false);
        $description = $notEmpty('DESCRIPTION', false);
        $location    = $notEmpty('LOCATION', false);

        $locationImage    = null;
        $locationImageUrl = false;
        $locationLink     = false;

        if (isset($itip->message->VEVENT->{'X-APPLE-STRUCTURED-LOCATION'})) {
            $match = preg_match(
                '/^(geo:)?(?<latitude>\-?\d+\.\d+),(?<longitude>\-?\d+\.\d+)$/',
                (string)$itip->message->VEVENT->{'X-APPLE-STRUCTURED-LOCATION'},
                $coordinates
            );

            if (0 !== $match) {
                $zoom   = 16;
                $width  = 500;
                $height = 220;

                $locationImage = new Mail\Content\Attachment(
                    $streamsToClose[] = new File\Read(
                        'http://api.tiles.mapbox.com/v4' .
                        '/mapbox.streets' .
                        '/pin-m-star+285A98' .
                        '(' . $coordinates['longitude'] .
                        ',' . $coordinates['latitude'] .
                        ')' .
                        '/' . $coordinates['longitude'] .
                        ',' . $coordinates['latitude'] .
                        ',' . $zoom .
                        '/' . $width . 'x' . $height . '.png' .
                        '?access_token=pk.eyJ1IjoiZHRvYnNjaGFsbCIsImEiOiIzMzdhNTRhNGNjOGFjOGQ4MDM5ZTJhNGZjYjNmNmE5OCJ9.7ZQOdfvoZW0XIbvjN54Wrg'
                    ),
                    'Map.png',
                    'image/png'
                );
                $locationImageUrl = $locationImage->getIdUrl();

                $locationLink =
                    'http://www.openstreetmap.org' .
                    '/?mlat=' . $coordinates['latitude'] .
                    '&mlon=' . $coordinates['longitude'] .
                    '#map=' . $zoom .
                    '/' . $coordinates['latitude'] .
                    '/' . $coordinates['longitude'];
            }
        }

		
        Mail\Message::setDefaultTransport(
            new Mail\Transport\Smtp(
				new Socket\Client('tcp://' . $this->smtpHost . ":" . $this->smtpPort),
				$this->smtpUser,
				$this->smtpPass
            )
        );

        $message            = new Mail\Message();

		if($senderName) $senderEmail ="'". $senderName."' <".$senderEmail.">";
		if($recipientName) $recipientEmail ="'". $recipientName."' <".$recipientEmail.">";
        $message['from']    = $senderEmail;
        $message['to']      = $recipientEmail;
        $message['subject'] = $subject;


        $textBody = function() use (
            $senderName,
            $summary,
            $action,
            $dateTime,
            $allDay,
            $attendees,
            $location,
            $url,
            $description
        ) {
            ob_start();

            require 'resources/caldav_scheduling.txt';
            $out = ob_get_contents();
			$out = preg_replace("/(\r?\n)\.(\r?\n)/m","$1$2",$out);
            ob_end_clean();

            return $out;
        };
        $htmlBody = function() use (
            $senderName,
            $summary,
            $action,
            $logoUrl,
            $dateTime,
            $allDay,
            $attendees,
            $location,
            $locationImageUrl,
            $locationLink,
            $url,
            $description
        ) {
            ob_start();

            require 'resources/caldav_scheduling.html';
            $out = ob_get_contents();
			$out = preg_replace("/(\r?\n)\.(\r?\n)/m","$1$2",$out);
            ob_end_clean();

            return $out;
        };

        $relatedContent = [
            new Mail\Content\Html($htmlBody())
        ];
	
        if (null !== $logo) {
            $relatedContent[] = $logo;
        }

	
        if (null !== $locationImage) {
            $relatedContent[] = $locationImage;
        }

        $message->addContent(
            new Mail\Content\Alternative([
                new Mail\Content\Text($textBody()),
                new Mail\Content\Related($relatedContent)
            ])
        );

        if (false === $deliveredLocally) {
            $bodyAsStream = new Stringbuffer\Read();
            $bodyAsStream->initializeWith($itip->message->serialize());

            $attachment = new Mail\Content\Attachment(
                $bodyAsStream,
                'Event.ics',
                'text/calendar; method=' . (string)$itip->method . '; charset=UTF-8'
            );

            $message->addContent($attachment);
        }

        $message->send();

        foreach ($streamsToClose as $stream) {
            $stream->close();
        }

        if (false === $deliveredLocally) {
            $itip->scheduleStatus = '1.1;Scheduling message is sent via iMip.';
        }
    }

}

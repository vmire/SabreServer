<?php

namespace Mireau\CalDAV;

use Sabre\DAVACL\PrincipalBackend;
use Sabre\CalDAV\Backend;

/**
 * Calendars collection
 *
 * This object is responsible for generating a list of calendar-homes for each
 * user.
 *
 * This is the top-most node for the calendars tree. In most servers this class
 * represents the "/calendars" path.
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class CalendarRoot extends \Sabre\CalDAV\CalendarRoot {

    /**
     * Constructor
     *
     * This constructor needs both an authentication and a caldav backend.
     *
     * By default this class will show a list of calendar collections for
     * principals in the 'principals' collection. If your main principals are
     * actually located in a different path, use the $principalPrefix argument
     * to override this.
     *
     * @param PrincipalBackend\BackendInterface $principalBackend
     * @param Backend\BackendInterface $caldavBackend
     * @param string $principalPrefix
     */
    function __construct(PrincipalBackend\BackendInterface $principalBackend, Backend\BackendInterface $caldavBackend, $principalPrefix = 'principals') {
        parent::__construct($principalBackend, $caldavBackend, $principalPrefix);
    }

    /**
     * This method returns a node for a principal.
     *
     * The passed array contains principal information, and is guaranteed to
     * at least contain a uri item. Other properties may or may not be
     * supplied by the authentication backend.
     *
     * @param array $principal
     * @return \Sabre\DAV\INode
     */
    function getChildForPrincipal(array $principal) {
		$node = parent::getChildForPrincipal($principal);

		 /*
         * Création du calendrier par défaut s'il n'existe pas
         */
        if(!$node || count($node->getChildren())==0){
            //No calendar. Create default one
            $principalUri = $principal["uri"];
            list(, $principalId) = \Sabre\HTTP\URLUtil::splitPath($principalUri);
            $name = $principalId;
            $properties = [
                    '{DAV:}displayname' => $name,
					'{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}calendar' => $name
                ];
            $this->caldavBackend->createCalendar($principalUri,$name,$properties);
            $node = parent::getChildForPrincipal($principal);
        }

		return $node;
    }

}

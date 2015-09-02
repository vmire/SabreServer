<?php

namespace Mireau\CardDAV;

use Sabre\DAVACL;

/**
 * AddressBook rootnode
 *
 * This object lists a collection of users, which can contain addressbooks.
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class AddressBookRoot extends \Sabre\CardDAV\AddressBookRoot {

    /**
     * Constructor
     *
     * This constructor needs both a principal and a carddav backend.
     *
     * By default this class will show a list of addressbook collections for
     * principals in the 'principals' collection. If your main principals are
     * actually located in a different path, use the $principalPrefix argument
     * to override this.
     *
     * @param DAVACL\PrincipalBackend\BackendInterface $principalBackend
     * @param Backend\BackendInterface $carddavBackend
     * @param string $principalPrefix
     */
    function __construct(DAVACL\PrincipalBackend\BackendInterface $principalBackend, \Sabre\CardDAV\Backend\BackendInterface $carddavBackend, $principalPrefix = 'principals') {
        parent::__construct($principalBackend, $carddavBackend, $principalPrefix);
    }

    /**
     * Returns the name of the node
     *
     * @return string
     */
    function getName() {
        return parent::getName();
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
		 * Création du carnet d'adresse par défaut s'il n'existe pas
		 */
		if(!$node || count($node->getChildren())==0){
			//No addressBook. Create default one
			$principalUri = $principal["uri"];
			list(, $principalId) = \Sabre\HTTP\URLUtil::splitPath($principalUri);
			$name = "contacts";
			$properties = [
					'{DAV:}displayname' => $name,
					'{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => "Contacts de ".$principalId
				];
			$this->carddavBackend->createAddressBook($principalUri,$name,$properties);
			$node = parent::getChildForPrincipal($principal);
		}

		return $node;
    }
}

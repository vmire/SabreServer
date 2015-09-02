<?php

namespace Mireau\DAV\Auth;

use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
//use Sabre\HTTP\URLUtil;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\MkCol;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 *
 */
class AutoCreatePrincipalsPlugin extends ServerPlugin {

	protected $principalBackend;

	function __construct(\Sabre\DAVACL\PrincipalBackend\PDO $principalBackend) {
		$this->principalBackend = $principalBackend;
	}

	function getPluginName() {
		return 'autoCreatePrincipal';
	}

	function initialize(\Sabre\DAV\Server $server) {

        $this->server = $server;
		$server->on('beforeMethod',        [$this, 'beforeMethod'], 15);	//AuthPlugin est en priorité 10
		
	}
	/**
	 * This method is called before any HTTP method and forces users to be authenticated
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 * @return bool
	 */
	function beforeMethod(RequestInterface $request, ResponseInterface $response) {
		
		$authPlugin = $this->server->getPlugin('auth');
		if(is_null($authPlugin)) return;
		
		$principalUri = $authPlugin->getCurrentPrincipal();
		$authUsername = $authPlugin->getCurrentUser();

		$principal = $this->principalBackend->getPrincipalByPath($principalUri);
		if(empty($principal)){
			//No principal for authenticated user
			global $defaultEmailDomain;
			if(!isset($defaultEmailDomain) || !$defaultEmailDomain){
				error_log("'defaultEmailDomain' not configured in config.php");
				return;
			}
				
			$mkCol = new MkCol(
				["{DAV:}principal"],
				[
					"{DAV:}displayname" => $authUsername,
					"{http://sabredav.org/ns}email-address" => $authUsername."@".$defaultEmailDomain
				]
			);
			$this->principalBackend->createPrincipal($principalUri,$mkCol);
			$mkCol->commit();
			
			$mkCol2 = new MkCol(["{DAV:}principal"],[]);
			$this->principalBackend->createPrincipal($principalUri."/calendar-proxy-read",$mkCol2);
			$this->principalBackend->createPrincipal($principalUri."/calendar-proxy-write",$mkCol2);
		}	
	}

	/**
	 * Returns a bunch of meta-data about the plugin.
	 *
	 * Providing this information is optional, and is mainly displayed by the
	 * Browser plugin.
	 *
	 * The description key in the returned array may contain html and will not
	 * be sanitized.
	 *
	 * @return array
	 */
	function getPluginInfo() {

		return [
			'name'		=> $this->getPluginName(),
			'description' => 'create automaticaly principal for the authenticated user if it does not exists',
			'link'		=> 'http://www.mireau.com/',
		];

	}

}

<?php
require_once 'vendor/autoload.php';

use Sabre\DAV\Auth;
require_once("config.php");
require_once("lib/IMipPlugin.php");
require_once("lib/AddressBookRoot.php");
require_once("lib/CalendarRoot.php");
require_once("lib/AutoCreatePrincipalsPlugin.php");

try{
	/**
	 * UTC or GMT is easy to work with, and usually recommended for any
	 * application.
	 */
	date_default_timezone_set('UTC');
	
	/**
	 * Make sure this setting is turned on and reflect the root url for your WebDAV
	 * server.
	 *
	 * This can be for example the root / or a complete path to your server script.
	 */
	if(!isset($baseUri)){
		error_log("baseUri not configured. (see config.php)");
		die("Server configuration error");
	}
	
	/**
	 * Database
	 *
	 * Feel free to switch this to MySQL, it will definitely be better for higher
	 * concurrency.
	 */
	$pdo = new PDO($dbUrl,$dbUser,$dbPass);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	
	/**
	 * Mapping PHP errors to exceptions.
	 *
	 * While this is not strictly needed, it makes a lot of sense to do so. If an
	 * E_NOTICE or anything appears in your code, this allows SabreDAV to intercept
	 * the issue and send a proper response back to the client (HTTP/1.1 500).
	 */
	function exception_error_handler($errno, $errstr, $errfile, $errline) {
		file_put_contents("/data/MyDAVServer/errors.log","[code:".$errno."]".$errstr." (".$errfile.":".$errline.")",FILE_APPEND);
	    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	}
	//set_error_handler("exception_error_handler");
	
	
	/**
	 * The backends. Yes we do really need all of them.
	 *
	 * This allows any developer to subclass just any of them and hook into their
	 * own backend systems.
	 */
	
	$principalBackend = new \Sabre\DAVACL\PrincipalBackend\PDO($pdo);
	$carddavBackend   = new \Sabre\CardDAV\Backend\PDO($pdo);
	$caldavBackend    = new \Sabre\CalDAV\Backend\PDO($pdo);
	
	/**
	 * The directory tree
	 *
	 * Basically this is an array which contains the 'top-level' directories in the
	 * WebDAV server.
	 */
	$nodes = [
	    // /principals
	    new \Sabre\CalDAV\Principal\Collection($principalBackend),
	    // /calendars
	    new \Mireau\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
	    // /addressbook
	    new \Mireau\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
		// Per-user Directory
		new \Sabre\DAVACL\FS\HomeCollection($principalBackend,$perUserFSDirectory),
	];
	
	// The object tree needs in turn to be passed to the server class
	$server = new \Sabre\DAV\Server($nodes);
	if (isset($baseUri)) $server->setBaseUri($baseUri);
	
	//Authentication (Apache)
	$authBackend = new Auth\Backend\Apache();
	$authPlugin = new Auth\Plugin($authBackend,'SabreDAV');
	$server->addPlugin($authPlugin);
	
	//ACL
	$aclPlugin = new \Sabre\DAVACL\Plugin();
	$aclPlugin->allowAccessToNodesWithoutACL = true;
	$aclPlugin->hideNodesFromListings = true;
	foreach($adminUsers as $userId){
		if(!$userId) continue;
		$aclPlugin->adminPrincipals[] = 'principals/'.$userId;
	}
	$server->addPlugin($aclPlugin);
	
	//Browser
	$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
	
	//CardDAV
	$server->addPlugin(new \Sabre\CardDAV\Plugin());
	//$server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());
	
	//CalDAV
	$server->addPlugin(new \Sabre\CalDAV\Plugin());
	$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
	$server->addPlugin(new \Sabre\CalDAV\Schedule\Plugin());
	$server->addPlugin(new \Mireau\CalDAV\Schedule\IMipPlugin($smtpHost, $smtpPort, $smtpUser, $smtpPass));
	//$server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
	
	$server->addPlugin(new \Mireau\DAV\Auth\AutoCreatePrincipalsPlugin($principalBackend));
	
	// And off we go!
	$server->exec();
}
catch(Exception $e){
	error_log("[".$e->getFile().":".$e->getLine."]".$e->getMessage());
}

<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

vimport('~modules/MailManager/models/Message.php');

class MailManager_Connector_Connector
{

	/*
	 * Cache interval time
	*/
	static $DB_CACHE_CLEAR_INTERVAL = "-1 day"; // strtotime

	/*
	 * Mail Box URL
	*/
	public $mBoxUrl;

	/*
	 * Mail Box connection instance
	*/
	public $mBox;

	/*
	 * Last imap error
	*/
	protected $mError;

	/*
	 * Mail Box folders
	*/
	protected $mFolders = false;

	/**
	 * Modified Time of the mail
	 */
	protected $mModified = false;

	/*
	 * Base URL of the Mail Box excluding folder name
	*/
	protected $mBoxBaseUrl;


	/**
	 * Connects to the Imap server with the given parameters
	 * @param $model MailManager_Model_Mailbox Instance
	 * $param $folder String optional - mail box folder name
	 * @returns MailManager_Connector Object
	 */
	public static function connectorWithModel($model, $folder = '')
	{
		$server = $model->server();

		if ($model->serverName() == 'Office365') {
			return MailManager_Office365_Connector::connectorWithModel($model, $folder);
		}

		$port = 143; // IMAP
		if (strcasecmp($model->protocol(), 'pop') === 0) $port = 110; // NOT IMPLEMENTED
		else if (strcasecmp($model->ssltype(), 'ssl') === 0) $port = 993; // IMAP SSL

		// use custom port if specified.
		if (stripos($server, ":") !== false) {
			list($server, $port) = explode(":", $server);
		}

		$url = sprintf(
			'{%s:%s/%s/%s/%s}%s',
			$server,
			$port,
			$model->protocol(),
			$model->ssltype(),
			$model->certvalidate(),
			$folder
		);
		$baseUrl = sprintf(
			'{%s:%s/%s/%s/%s}',
			$server,
			$port,
			$model->protocol(),
			$model->ssltype(),
			$model->certvalidate()
		);
		return new self($url, $model->username(), $model->password(), $baseUrl, $model->serverName(), $model->mailproxy(), $model->authtype(), $model);
	}


	/**
	 * Opens up imap connection to the specified url
	 * @param $url String - mail server url
	 * @param $username String  - user name of the mail box
	 * @param $password String  - pass word of the mail box
	 * @param $baseUrl Optional - url of the mailserver excluding folder name.
	 *	This is used to fetch the folders of the mail box
	 */
	public function __construct($url, $username, $password, $baseUrl = false, $serverName = '', $mailproxy = '', $authtype = '', $model = null)
	{
		$boxUrl = $this->convertCharacterEncoding(html_entity_decode($url), 'UTF7-IMAP', 'UTF-8'); //handle both utf8 characters and html entities
		$this->mBoxUrl = $boxUrl;
		$this->mBoxBaseUrl = $baseUrl; // Used for folder List

		if ($serverName == 'gmail') {
			if ($authtype == "XOAUTH2" || $authtype == "google-oauth2") {
				$tokens = json_decode($password, true);
				$expiresOn = $model ? $model->authexpireson() : 0;
				
				// Automatically refresh token if it's within 5 minutes of expiring
				if (time() >= ($expiresOn - 300)) {
					if (!empty($tokens['refresh_token'])) {
						require_once "vendor/autoload.php";
						require_once "modules/Oauth2/Config.php";
						$cfgdata = require_once "oauth2callback/config.oauth2.php";
						$config = Oauth2_Config::loadConfig($cfgdata);
						$authcfg = $config->getProviderConfig('Google');
						
						if (!empty($authcfg['clientId'])) {
							$provider = new \League\OAuth2\Client\Provider\GenericProvider($authcfg);
							try {
								$newAccessToken = $provider->getAccessToken('refresh_token', [
									'refresh_token' => $tokens['refresh_token']
								]);
								
								$tokens['access_token'] = $newAccessToken->getToken();
								if ($newAccessToken->getRefreshToken()) {
									$tokens['refresh_token'] = $newAccessToken->getRefreshToken();
								}
								$expiresOn = $newAccessToken->getExpires();
								
								if ($model) {
									$db = PearDatabase::getInstance();
									$db->pquery("UPDATE vtiger_mail_accounts SET mail_password=?, auth_expireson=? WHERE account_id=?", 
										array(Vtiger_Functions::toProtectedText(json_encode($tokens)), $expiresOn, $model->mId));
								}
							} catch (Exception $e) {
							}
						}
					} else {
					}
				}
				
				$password = $tokens["access_token"];

				// route request to local-imap proxy server.
				$folder = substr($boxUrl, strpos($boxUrl, '}') + 1);
				if ($folder == '') $folder = 'INBOX';
				if (!empty($mailproxy)) {
					$boxUrl = sprintf("{%s/IMAP4/notls/novalidate-cert}%s", $mailproxy, $folder);
				}
			}
			
			// Set a short timeout to prevent infinite hanging if the proxy returns a SASL challenge for an expired token
			imap_timeout(IMAP_OPENTIMEOUT, 5);
			imap_timeout(IMAP_READTIMEOUT, 5);
			$this->mBox = @imap_open($boxUrl, $username, $password);
			
			// If it failed and we are using Google OAuth, try to force a token refresh
			if (!$this->mBox && $serverName == 'gmail' && ($authtype == "XOAUTH2" || $authtype == "google-oauth2") && !empty($tokens['refresh_token'])) {
				require_once "vendor/autoload.php";
				require_once "modules/Oauth2/Config.php";
				$cfgdata = require_once "oauth2callback/config.oauth2.php";
				$config = Oauth2_Config::loadConfig($cfgdata);
				$authcfg = $config->getProviderConfig('Google');
				
				if (!empty($authcfg['clientId'])) {
					$provider = new \League\OAuth2\Client\Provider\GenericProvider($authcfg);
					try {
						$newAccessToken = $provider->getAccessToken('refresh_token', [
							'refresh_token' => $tokens['refresh_token']
						]);
						
						$tokens['access_token'] = $newAccessToken->getToken();
						$password = $tokens['access_token'];
						if ($newAccessToken->getRefreshToken()) {
							$tokens['refresh_token'] = $newAccessToken->getRefreshToken();
						}
						
						if ($model) {
							$db = PearDatabase::getInstance();
							$db->pquery("UPDATE vtiger_mail_accounts SET mail_password=?, auth_expireson=? WHERE account_id=?", 
								array(Vtiger_Functions::toProtectedText(json_encode($tokens)), $newAccessToken->getExpires(), $model->mId));
						}
						$this->mBox = @imap_open($boxUrl, $username, $password);
					} catch (Exception $e) {
					}
				}
			}

			if (!$this->mBox) {
				$this->mError = imap_last_error();
			}
			// Also update base URL for folder listing to match connection flags
			$this->mBoxBaseUrl = str_replace("}INBOX", "}", $boxUrl);
		} else {
			$this->mBox = @imap_open($boxUrl, $username, $password, NULL, 1, array('DISABLE_AUTHENTICATOR' => 'GSSAPI'));
		}

		$this->isError();
	}


	/**
	 * Closes the connection
	 */
	public function __destruct()
	{
		$this->close();
	}


	/**
	 * Closes the imap connection
	 */
	public function close()
	{
		if (!empty($this->mBox)) {

			if ($this->mModified) imap_close($this->mBox, CL_EXPUNGE);
			else imap_close($this->mBox);

			$this->mBox = null;
		}
	}


	/**
	 * Checks for the connection
	 */
	public function isConnected()
	{
		return !empty($this->mBox);
	}


	/**
	 * Returns the last imap error
	 */
	public function isError()
	{
		$errors = imap_errors();
		if ($errors !== false) {
			$this->mError = implode(', ', $errors);
		} else {
			$this->mError = imap_last_error();
		}

		return $this->hasError();
	}


	/**
	 * Checks if the error exists
	 */
	public function hasError()
	{
		return !empty($this->mError);
	}


	/**
	 * Returns the error
	 */
	public function lastError()
	{
		return $this->mError;
	}


	/**
	 * Reads mail box folders
	 * @param string $ref Optional -
	 */
	public function folders($ref = false)
	{
		if ($this->mFolders) return $this->mFolders;

		if (!$ref) $ref = $this->mBoxBaseUrl;
		$result = imap_getmailboxes($this->mBox, $ref, "*");
		if ($this->isError()) {
			return false;
		}

		$folders = array();
		if (is_array($result)) {
			foreach ($result as $row) {
				$folderName = str_replace($ref, "", $row->name);
				$folderName = str_ireplace($ref, "", $row->name); // case insensitive fallback
				$folder = $this->convertCharacterEncoding($folderName, "UTF-8", "UTF7-IMAP"); //Decode folder name
				$folders[] = $this->folderInstance($folder);
			}
		} else {
		}
		$this->mFolders = $folders;
		return $folders;
	}


	/**
	 * Used to update the folders optionus
	 * @param imap_stats flag $options
	 */
	public function updateFolders($options = SA_UNSEEN)
	{
		$this->folders(); // Initializes the folder Instance
		foreach ($this->mFolders as $folder) {
			$this->updateFolder($folder, $options);
		}
	}


	/**
	 * Updates the mail box's folder
	 * @param MailManager_Model_Folder $folder - folder instance
	 * @param $options imap_status flags like SA_UNSEEN, SA_MESSAGES etc
	 */
	public function updateFolder($folder, $options)
	{
		$mailbox = $this->convertCharacterEncoding($folder->name($this->mBoxUrl), "UTF7-IMAP", "ISO-8859-1"); //Encode folder name
		$result = $this->mBox ? imap_status($this->mBox, $mailbox, $options) : null;
		if ($result) {
			if (isset($result->unseen)) $folder->setUnreadCount($result->unseen);
			if (isset($result->messages)) $folder->setCount($result->messages);
		}
	}


	/**
	 * Returns MailManager_Model_Folder Instance
	 * @param String $name - folder name
	 */
	public function folderInstance($name)
	{
		vimport('modules/MailManager/models/Folder.php');
		return new MailManager_Folder_Model($name);
	}


	/**
	 * Sets a list of mails with paging
	 * @param String $folder - MailManager_Model_Folder Instance
	 * @param Integer $start  - Page number
	 * @param Integer $maxLimit - Number of mails
	 */
	public function folderMails($folder, $start, $maxLimit)
	{
		$folderCheck = $this->mBox ? imap_check($this->mBox) : null;
		if ($folderCheck && $folderCheck->Nmsgs) {

			$reverse_start = $folderCheck->Nmsgs - ($start * $maxLimit);
			$reverse_end = $reverse_start - $maxLimit + 1;

			if ($reverse_start < 1) $reverse_start = 1;
			if ($reverse_end < 1) $reverse_end = 1;

			$sequence = sprintf("%s:%s", $reverse_start, $reverse_end);

			$records = imap_fetch_overview($this->mBox, $sequence);
			$mails = array();
			$mailIds = array();

			// to make sure this should not break in Vtiger6
			$layout = Vtiger_Viewer::getDefaultLayoutName();
			if ($layout == "v7") {
				$mbox = false;
			} else {
				$mbox = $this->mBox;
			}

			foreach ($records as $result) {
				$message = MailManager_Message_Model::parseOverview($result, false);
				$mailIds[] = $message->msgNo();
				array_unshift($mails, $message);
			}
			$folder->setMails($mails);
			$folder->setMailIds($mailIds);
			$folder->setPaging($reverse_end, $reverse_start, $maxLimit, $folderCheck->Nmsgs, $start);
		}
	}


	/**
	 * Return the cache interval
	 */
	public function clearDBCacheInterval()
	{
		// TODO Provide configuration option.
		if (self::$DB_CACHE_CLEAR_INTERVAL) {
			return strtotime(self::$DB_CACHE_CLEAR_INTERVAL);
		}
		return false;
	}


	/**
	 * Clears the cache data
	 */
	public function clearDBCache()
	{
		// Trigger purne any older mail saved in DB first
		$interval = $this->clearDBCacheInterval();

		$timenow = strtotime("now");

		// Optimization to avoid trigger for ever mail open (with interval specified)
		$lastClearTimeFromSession = false;
		if ($interval && isset($_SESSION) && isset($_SESSION['mailmanager_clearDBCacheIntervalLast'])) {
			$lastClearTimeFromSession = intval($_SESSION['mailmanager_clearDBCacheIntervalLast']);
			if (($timenow - $lastClearTimeFromSession) < ($timenow - $interval)) {
				$interval = false;
			}
		}
		if ($interval) {
			MailManager_Message_Model::pruneOlderInDB($interval);
			$_SESSION['mailmanager_clearDBCacheIntervalLast'] = $timenow;
		}
	}


	/**
	 * Function which deletes the mails
	 * @param String $msgno - List of message number seperated by commas.
	 */
	public function deleteMail($msgno)
	{
		$msgno = trim($msgno, ',');
		$msgno = explode(',', $msgno);
		for ($i = 0; $i < php7_count($msgno); $i++) {
			@imap_delete($this->mBox, $msgno[$i]);
		}
		imap_expunge($this->mBox);
	}


	/**
	 * Function which moves mail to another folder
	 * @param String $msgno - List of message number separated by commas
	 * @param String $folderName - folder name
	 */
	public function moveMail($msgno, $folderName)
	{
		$msgno = trim($msgno, ',');
		$msgno = explode(',', $msgno);
		$folder = $this->convertCharacterEncoding(html_entity_decode($folderName), 'UTF7-IMAP', 'UTF-8'); //handle both utf8 characters and html entities
		for ($i = 0; $i < php7_count($msgno); $i++) {
			@imap_mail_move($this->mBox, $msgno[$i], $folder);
		}
		@imap_expunge($this->mBox);
	}


	/**
	 * Creates an instance of Message
	 * @param String $msgno - Message number
	 * @return MailManager_Model_Message
	 */
	public function openMail($msgno, $folder)
	{
		$this->clearDBCache();
		return new MailManager_Message_Model($this->mBox, $msgno, true, $folder);
	}


	/**
	 * Marks the mail as Unread
	 * @param <String> $msgno - Message Number
	 */
	public function markMailUnread($msgno)
	{
		imap_clearflag_full($this->mBox, $msgno, '\\Seen');
		$this->mModified = true;
	}


	/**
	 * Marks the mail as Read
	 * @param String $msgno - Message Number
	 */
	public function markMailRead($msgno)
	{
		imap_setflag_full($this->mBox, $msgno, '\\Seen');
		$this->mModified = true;
	}


	/**
	 * Searches the Mail Box with the query
	 * @param String $query - imap search format
	 * @param MailManager_Model_Folder $folder - folder instance
	 * @param Integer $start - Page number
	 * @param Integer $maxLimit - Number of mails
	 */
	public function searchMails($query, $folder, $start, $maxLimit)
	{
		$nos = imap_search($this->mBox, $query);

		if (!empty($nos)) {
			$nmsgs = php7_count($nos);

			$reverse_start = $nmsgs - ($start * $maxLimit);
			$reverse_end   = $reverse_start - $maxLimit;

			if ($reverse_start < 1) $reverse_start = 1;
			if ($reverse_end < 1) $reverse_end = 0;

			if ($nmsgs > 1)
				$nos = array_slice($nos, $reverse_end, ($reverse_start - $reverse_end));

			// Reverse order the messages
			rsort($nos, SORT_NUMERIC);

			$mails = array();
			$records = imap_fetch_overview($this->mBox, implode(',', $nos));

			// to make sure this should not break in Vtiger6
			$layout = Vtiger_Viewer::getDefaultLayoutName();
			if ($layout == "v7") {
				$mbox = false;
			} else {
				$mbox = $this->mBox;
			}

			$mailnos = array();
			foreach ($records as $result) {
				$message = MailManager_Message_Model::parseOverview($result, false);
				array_unshift($mails, $message);
				array_unshift($mailnos, $message->msgNo());
			}
			$folder->setMails($mails);
			$folder->setMailIds($mailnos);
			$folder->setPaging($reverse_end, $reverse_start, $maxLimit, $nmsgs, $start);  //-1 as it starts from 0
		}
	}


	/**
	 * Returns list of Folder for the Mail Box
	 * @return Array folder list
	 */
	public function getFolderList()
	{
		$folderList = array();
		if (!empty($this->mBoxBaseUrl)) {
			$list = $this->mBox ? imap_list($this->mBox, $this->mBoxBaseUrl, '*') : array();
			if (is_array($list)) {
				foreach ($list as $val) {
					$folder = $this->convertCharacterEncoding($val, 'UTF-8', 'UTF7-IMAP'); //Decode folder name
					$folderList[] =  preg_replace("/{(.*?)}/", "", $folder);
				}
			}
		}
		return $folderList;
	}

	public function convertCharacterEncoding($value, $toCharset, $fromCharset)
	{
		if (function_exists('mb_convert_encoding')) {
			$value = mb_convert_encoding($value, $toCharset, $fromCharset);
		} else {
			$value = iconv($toCharset, $fromCharset, $value);
		}
		return $value;
	}
}

<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

class MailManager_Mailbox_Model {

	protected $mServer;
	public $mUsername;
	protected $mPassword;
	protected $mProtocol = 'IMAP4';
	protected $mSSLType  = 'ssl';
	protected $mCertValidate = 'novalidate-cert';
	protected $mRefreshTimeOut;
	public $mId;
	protected $mServerName;
    protected $mFolder;
	protected $mAuthType;
	protected $mAuthExpiresOn;
	protected $mProxy;

	public function exists() {
		return !empty($this->mId);
	}

	public function decrypt($value) {
		if (class_exists('Vtiger_Functions') && Vtiger_Functions::isProtectedText($value)) {
			return Vtiger_Functions::fromProtectedText($value);
		}
		require_once('include/utils/encryption.php');
		$e = new Encryption();
		return $e->decrypt($value);
	}

	public function encrypt($value) {
		if (class_exists('Vtiger_Functions')) {
			return Vtiger_Functions::toProtectedText($value);
		}
		require_once('include/utils/encryption.php');
		$e = new Encryption();
		return $e->encrypt($value);
	}

	public function server() {
		return $this->mServer;
	}

	public function setServer($server) {
		$this->mServer = trim($server);
		$this->mServerName = self::setServerName($server);
	}

	public function serverName() {
		return $this->mServerName;
	}

	public function username() {
		return $this->mUsername;
	}

	public function setUsername($username) {
		$this->mUsername = trim($username);
	}

	public function password($decrypt=true) {
		if ($decrypt) return $this->decrypt($this->mPassword);
		return $this->mPassword;
	}

	public function setPassword($password) {
		$this->mPassword = $this->encrypt(trim($password));
	}

	public function protocol() {
		return $this->mProtocol;
	}

	public function setProtocol($protocol) {
		$this->mProtocol = trim($protocol);
	}

	public function ssltype() {
		if (strcasecmp($this->mSSLType, 'ssl') === 0) {
			return $this->mSSLType;
		}
		return $this->mSSLType;
	}

	public function setSSLType($ssltype) {
		$this->mSSLType = trim($ssltype);
	}

	public function authtype() {
		return $this->mAuthType;
	}

	public function setAuthType($authType) {
		$this->mAuthType = $authType;
	}

	public function authexpireson() {
		return $this->mAuthExpiresOn;
	}

	public function setAuthExpiresOn($expireson) {
		$this->mAuthExpiresOn = $expireson;
	}

	public function mailproxy() {
		return $this->mProxy;
	}

	public function setMailProxy($mproxy) {
		$this->mProxy = $mproxy;
	}

	public function certvalidate() {
		return $this->mCertValidate;
	}

	public function setCertValidate($certvalidate) {
		$this->mCertValidate = trim($certvalidate);
	}

	public function setRefreshTimeOut($value) {
		$this->mRefreshTimeOut = $value;
	}

	public function refreshTimeOut() {
		return $this->mRefreshTimeOut;
	}

	public function account_id() {
		return $this->mId;
	}

    public function setFolder($value) {
		$this->mFolder = $value;
	}

	public function folder() {
		return decode_html($this->mFolder);
	}

	public function delete() {
		$db = PearDatabase::getInstance();
		$currentUserModel = Users_Record_Model::getCurrentUserModel();
		$db->pquery("DELETE FROM vtiger_mail_accounts WHERE user_id = ? AND account_id = ?", array($currentUserModel->getId(), $this->mId));

		$mail = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE set_default = 0 AND user_id = ?", array($currentUserModel->getId()));
		if(!$db->num_rows($mail)) {
			$db->pquery("UPDATE vtiger_mail_accounts SET set_default = 0 WHERE user_id = ? ORDER BY account_id DESC", array($currentUserModel->getId()));
		}
	}

	public function save() {
		$db = PearDatabase::getInstance();
		$currentUserModel = Users_Record_Model::getCurrentUserModel();

		$account_id = 1;
		$maxresult = $db->pquery("SELECT max(account_id) as max_account_id FROM vtiger_mail_accounts", array());
		if ($db->num_rows($maxresult)) $account_id += intval($db->query_result($maxresult, 0, 'max_account_id'));

		$isUpdate = !empty($this->mId);

		$sql = "";
		$parameters = array($this->username(), $this->server(), $this->username(), $this->password(false), $this->protocol(), $this->ssltype(), $this->certvalidate(), $this->refreshTimeOut(),$this->folder(), $this->authtype(), $this->authexpireson(), $this->mailproxy(), $currentUserModel->getId());

		if ($isUpdate) {
			$sql = "UPDATE vtiger_mail_accounts SET display_name=?, mail_servername=?, mail_username=?, mail_password=?, mail_protocol=?, ssltype=?, sslmeth=?, box_refresh=?, sent_folder=?, auth_type = ?, auth_expireson = ?, mail_proxy = ? WHERE user_id=? AND account_id=?";
			$parameters[] = $this->mId;
		} else {
			$sql = "INSERT INTO vtiger_mail_accounts(display_name, mail_servername, mail_username, mail_password, mail_protocol, ssltype, sslmeth, box_refresh,sent_folder, auth_type, auth_expireson, mail_proxy, user_id, mails_per_page, account_name, status, set_default, account_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
			$parameters[] = vglobal('list_max_entries_per_page'); // Number of emails per page
			$parameters[] = $this->username();
			$parameters[] = 1; // Status
			$parameters[] = '0'; // Set Default
			$parameters[] = $account_id;
		}
		$db->pquery($sql, $parameters);
		if (!$isUpdate) {
			$this->mId = $account_id;
			$_SESSION['mailmanager_active_account_id'] = $this->mId;
		}
	}

	public static function activeInstance($accountId = false, $mode = false, $currentUserModel = false) {

		if(!$currentUserModel)
			$currentUserModel = Users_Record_Model::getCurrentUserModel();
			
		$userId = $currentUserModel->getId();
		$db = PearDatabase::getInstance();

		// Check request for explicit account switch
		// Check request for explicit account switch
		if (!$accountId && isset($_REQUEST['account_id']) && $_REQUEST['account_id'] !== '') {
			$accountId = $_REQUEST['account_id'];
		}

		// Check session if no explicit account provided, but skip if we are creating a new one
		if (!$accountId && isset($_SESSION['mailmanager_active_account_id']) && !(isset($_REQUEST['create']) && $_REQUEST['create'] == 'new')) {
			$accountId = $_SESSION['mailmanager_active_account_id'];
		}

		$instance = new MailManager_Mailbox_Model();

		if(($mode == 'edit' && $accountId == false) || (isset($_REQUEST['create']) && $_REQUEST['create'] == 'new')){
			return $instance;
		} 

		if(!$accountId){
			// Fallback: try to find an account with set_default=0
			$result = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE user_id=? AND status=1 AND set_default=0", array($userId));
			if (!$db->num_rows($result)) {
				// Fallback to first available account
				$result = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE user_id=? AND status=1 LIMIT 1", array($userId));
			}
		} else {
			$result = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE user_id=? AND account_id=?", array($userId, $accountId));
			
			// If account found, update session and database default
			if ($db->num_rows($result)) {
				$_SESSION['mailmanager_active_account_id'] = $accountId;
				if($mode != 'edit'){
					$db->pquery("UPDATE vtiger_mail_accounts SET set_default = ? WHERE user_id = ?",array(1, $userId));
					$db->pquery("UPDATE vtiger_mail_accounts SET set_default = ? WHERE user_id = ? AND account_id=?",array(0, $userId, $accountId));
				}
			} else {
				// Invalid account ID in session/request, clear it and retry fallback
				unset($_SESSION['mailmanager_active_account_id']);
				// IMPORTANT: Also clear from REQUEST to avoid infinite recursion
				unset($_REQUEST['account_id']);
				return self::activeInstance(false, $mode, $currentUserModel);
			}
		}

		if ($db->num_rows($result)) {
			$instance->mServer = trim($db->query_result($result, 0, 'mail_servername'));
			$instance->mUsername = trim($db->query_result($result, 0, 'mail_username'));
			$instance->mPassword = trim($db->query_result($result, 0, 'mail_password'));
			$instance->mProtocol = trim($db->query_result($result, 0, 'mail_protocol'));
			$instance->mSSLType = trim($db->query_result($result, 0, 'ssltype'));
			$instance->mCertValidate = trim($db->query_result($result, 0, 'sslmeth'));
			$instance->mId = trim($db->query_result($result, 0, 'account_id'));
			$instance->mRefreshTimeOut = trim($db->query_result($result, 0, 'box_refresh'));
			$instance->mAuthType = trim($db->query_result($result, 0, 'auth_type'));
			$instance->mAuthExpiresOn = $db->query_result($result, 0, 'auth_expireson');
			$instance->mProxy = trim($db->query_result($result, 0, 'mail_proxy'));
            $instance->mFolder = trim($db->query_result($result, 0, 'sent_folder'));
			$instance->mServerName = self::setServerName($instance->mServer);
			
			// Ensure session is set for the loaded account
			$_SESSION['mailmanager_active_account_id'] = $instance->mId;
		} else {
		}
		
		return $instance;
	}

	public static function getInstanceById($accountId, $currentUserModel = false) {
		if(!$currentUserModel)
			$currentUserModel = Users_Record_Model::getCurrentUserModel();
			
		$userId = $currentUserModel->getId();
		$db = PearDatabase::getInstance();
		$instance = new MailManager_Mailbox_Model();

		$result = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE user_id=? AND account_id=?", array($userId, $accountId));
		if ($db->num_rows($result)) {
			$instance->mServer = trim($db->query_result($result, 0, 'mail_servername'));
			$instance->mUsername = trim($db->query_result($result, 0, 'mail_username'));
			$instance->mPassword = trim($db->query_result($result, 0, 'mail_password'));
			$instance->mProtocol = trim($db->query_result($result, 0, 'mail_protocol'));
			$instance->mSSLType = trim($db->query_result($result, 0, 'ssltype'));
			$instance->mCertValidate = trim($db->query_result($result, 0, 'sslmeth'));
			$instance->mId = trim($db->query_result($result, 0, 'account_id'));
			$instance->mRefreshTimeOut = trim($db->query_result($result, 0, 'box_refresh'));
			$instance->mAuthType = trim($db->query_result($result, 0, 'auth_type'));
			$instance->mAuthExpiresOn = $db->query_result($result, 0, 'auth_expireson');
			$instance->mProxy = trim($db->query_result($result, 0, 'mail_proxy'));
            $instance->mFolder = trim($db->query_result($result, 0, 'sent_folder'));
			$instance->mServerName = self::setServerName($instance->mServer);
		}
		return $instance;
	}

	public static function setServerName($mServer) {
		if($mServer == 'imap.gmail.com') {
			$mServerName = 'gmail';
		} else if($mServer == 'imap.mail.yahoo.com') {
			$mServerName = 'yahoo';
		} else if($mServer == 'mail.messagingengine.com') {
			$mServerName = 'fastmail';
		} else if($mServer == 'imap.office365.com'){
		    $mServerName = 'Office365';
		} else {
			$mServerName = 'other';
		}
		return $mServerName;
	}

	public static function getAllMailBoxes() {
		$mailBox = array();
		$db = PearDatabase::getInstance();
		$currentUserModel = Users_Record_Model::getCurrentUserModel();
		
		$result = $db->pquery("SELECT * FROM vtiger_mail_accounts WHERE user_id=?", array($currentUserModel->getId()));
		if ($db->num_rows($result)) {
			for($u=0; $u<$db->num_rows($result); $u++){
				$mailBox[$u]['account_id'] = $db->query_result($result, $u, 'account_id');
				$mailBox[$u]['account_name'] = $db->query_result($result, $u, 'mail_username');
				$mailBox[$u]['server'] = $db->query_result($result, $u, 'mail_servername');
			}
		}
		return $mailBox;
	}

}

?>

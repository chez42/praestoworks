<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/
// require_once('modules/Emails/class.smtp.php');
// require_once('modules/Emails/class.phpmailer.php');
include_once('include/utils/CommonUtils.php');
include_once('config.inc.php');
include_once('include/database/PearDatabase.php');
include_once('vtlib/Vtiger/Utils.php');
include_once('vtlib/Vtiger/Event.php');

class Vtiger_Mailer_xOauth2Provider implements \PHPMailer\PHPMailer\OAuthTokenProvider {
	protected $email;
	protected $token;
	function __construct($email, $token) {
		$this->email = $email;
		$this->token = $token;
	}
    function getOauth64() {
        return 
            base64_encode("user=".$this->email."\1auth=Bearer ".$this->token."\1\1");
    }
}

/**
 * Provides API to work with PHPMailer & Email Templates
 * @package vtlib
 */
class Vtiger_Mailer extends \PHPMailer\PHPMailer\PHPMailer {

	var $_serverConfigured = false;
	var $from_email = false;

	/**
	 * Constructor
	 */
	function __construct($exceptions=null, $account_id=false) {
		global $default_charset;
		parent::__construct($exceptions);
		$this->from_email = $account_id;
		$this->initialize();
		$this->CharSet = $default_charset;
	}

	/**
	 * Get the unique id for insertion
	 * @access private
	 */
	function __getUniqueId() {
		global $adb;
		return $adb->getUniqueID('vtiger_mailer_queue');
	}

	/**
	 * Initialize this instance
	 * @access private
	 */
	function initialize() {
		$this->Timeout = 30; /* Issue #155: to allow anti-spam tech be successful */
		$this->IsSMTP();

		global $adb;
		
		if(!$this->from_email){
			$result = $adb->pquery("SELECT * FROM vtiger_systems WHERE server_type=?", Array('email'));
		} else {
			$result = $adb->pquery("SELECT mail_servername as server, mail_username as server_username, mail_password as server_password,  
			auth_type as smtp_auth_type, 1 as smtp_auth,  mail_username as from_email_field
			FROM vtiger_mail_accounts WHERE
            account_id = ?", array($this->from_email), true);
	    }
		
		if($adb->num_rows($result)) {
			$this->Host = $adb->query_result($result, 0, 'server');
			$this->Username = decode_html($adb->query_result($result, 0, 'server_username'));
			
			$password = decode_html($adb->query_result($result, 0, 'server_password'));
			if (class_exists('Vtiger_Functions') && Vtiger_Functions::isProtectedText($password)) {
				$this->Password = Vtiger_Functions::fromProtectedText($password);
			} else {
				require_once('include/utils/encryption.php');
				$e = new Encryption();
				$this->Password = $e->decrypt($password);
			}
			
			$this->SMTPAuth = $adb->query_result($result, 0, 'smtp_auth');
			$SMTPAuthType = $adb->query_result($result, 0, 'smtp_auth_type'); // prasad

			// To support TLS
			$hostinfo = explode("://", $this->Host);
			$smtpsecure = $hostinfo[0];
			if($smtpsecure == 'tls'){
				$this->SMTPSecure = $smtpsecure;
				$this->Host = $hostinfo[1];
			}
			// End

			if(empty($this->SMTPAuth)) $this->SMTPAuth = false;

			// XOAUTH2
			if($this->SMTPAuth && $SMTPAuthType == "XOAUTH2") {
				$this->AuthType = "XOAUTH2";
				$this->SMTPAuth = true;
				$tokens = json_decode($this->Password, true);
				$this->setOAuth(new Vtiger_Mailer_xOauth2Provider($this->Username, $tokens["access_token"]));
			}

			$this->ConfigSenderInfo($adb->query_result($result, 0, 'from_email_field'));

			$this->_serverConfigured = true;
//			$this->Sender= getReturnPath($this->Host);
		}
	}

	/**
	 * Reinitialize this instance for use
	 * @access private
	 */
	function reinitialize() {
		$this->ClearAllRecipients();
		$this->ClearReplyTos();
		$this->ClearCustomHeaders();
		$this->Body = '';
		$this->Subject ='';
		$this->ClearAttachments();
		$this->ErrorInfo = '';
	}

	/**
	 * Initialize this instance using mail template
	 * @access private
	 */
	function initFromTemplate($emailtemplate) {
		global $adb;
		$result = $adb->pquery("SELECT * from vtiger_emailtemplates WHERE templatename=? AND foldername=?",
			Array($emailtemplate, 'Public'));
		if($adb->num_rows($result)) {
			$this->IsHTML(true);
			$usesubject = $adb->query_result($result, 0, 'subject');
			$usebody = decode_html($adb->query_result($result, 0, 'body'));

			$this->Subject = $usesubject;
			$this->Body    = $usebody;
			return true;
		}
		return false;
	}
	/**
	*Adding signature to mail
	*/
	function addSignature($userId) {
		global $adb;
		$sign = nl2br($adb->query_result($adb->pquery("select signature from vtiger_users where id=?", array($userId)),0,"signature"));
		$this->Signature = $sign;
	}


	/**
	 * Configure sender information
	 */
	function ConfigSenderInfo($fromemail, $fromname='', $replyto='') {
		if(empty($fromname)) $fromname = $fromemail;

		$this->From = $fromemail;
		//fix for (http://trac.vtiger.com/cgi-bin/trac.cgi/ticket/8001)
		if($fromname) {
			$this->FromName = decode_html($fromname);
		}
		if($replyto) {
			$this->AddReplyTo($replyto);
		}
	}

	/**
	 * Overriding default send
	 */
	function Send($sync=false, $linktoid=false) {
		
		if(stripos($this->Host, "outlook") !== FALSE || stripos($this->Host, "office365") !== FALSE){
			
			$tokens = json_decode($this->Password, true);
			
			$access_token = $tokens['access_token'];
			
            $attachments =  array();
            
            foreach($this->attachment as $attachmnents_file){
                $binary_content = base64_encode(file_get_contents($attachmnents_file[0]));
                $attachmentFileName = basename($attachmnents_file[0]);
                $attachments[] = array(
					'@odata.type' => '#microsoft.graph.fileAttachment',
					'contentBytes' => $binary_content, 
					'name' => $attachmentFileName
				);
            }
            
            
            $cc_email = array();
            

           if(!empty($this->cc)) {
                foreach($this->cc as $cc){
                    if($cc){
                        $cc_email[] = ['emailAddress' => ['address' => $cc[0]]];
                    }
                }
            }
            
            $bcc_email = array();
            
            if(!empty($this->bcc)) {
                foreach($this->bcc as $bcc) {
                    if($bcc){
                        $bcc_email[] = ['emailAddress' => ['address' => $bcc[0]]];
                    }
                }
            }
            
            
            $emails = array();
            
            foreach($this->to as $to_email){
                if($to_email){
                    $emails[] = ['emailAddress' => ['address' => $to_email[0]]];
                }
            }
			
			
			$emailData = [
				'message' => [
					'subject' => $this->Subject,
					'body' => [
						'contentType' => 'HTML',
						'content' => $this->Body
					],
					'toRecipients' => $emails,
					'ccRecipients' => $cc_email,
					'bccRecipients' => $bcc_email,
					'attachments' => $attachments
				],
				'saveToSentItems' => true
			];
			
			$url = "https://graph.microsoft.com/v1.0/me/sendmail";
			
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, true);
			
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Authorization: Bearer ' . $access_token,
				'Content-Type: application/json',
			]);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
			
			$response = curl_exec($ch);
			
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			
			$error = curl_error($ch);
			
			curl_close($ch);
			
            if($httpCode == 202){
                return true;
            }

			// If unauthorized, attempt to refresh the token
			if ($httpCode == 401 && stripos($response, 'InvalidAuthenticationToken') !== false) {
			    require_once 'modules/Oauth2/Config.php';
			    $cfgfile = "oauth2callback/config.oauth2.php";
			    if (file_exists($cfgfile)) {
			        $cfgdata = require_once $cfgfile;
			        $config = Oauth2_Config::loadConfig($cfgdata);
			        $cfg = $config->getProviderConfig('Office365');
			        
			        // Manual token refresh via CURL
			        $tokens = json_decode($this->Password, true);
			        $tokenUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
			        $postFields = http_build_query([
			            'client_id' => $cfg['clientId'],
			            'client_secret' => $cfg['clientSecret'],
			            'refresh_token' => $tokens['refresh_token'],
			            'grant_type' => 'refresh_token'
			        ]);
			        
			        $chToken = curl_init($tokenUrl);
			        curl_setopt($chToken, CURLOPT_POST, true);
			        curl_setopt($chToken, CURLOPT_POSTFIELDS, $postFields);
			        curl_setopt($chToken, CURLOPT_RETURNTRANSFER, true);
			        curl_setopt($chToken, CURLOPT_TIMEOUT, 15);
			        curl_setopt($chToken, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			        $tokenResponse = curl_exec($chToken);
			        $tokenHttpCode = curl_getinfo($chToken, CURLINFO_HTTP_CODE);
			        curl_close($chToken);
			        
			        if ($tokenHttpCode == 200) {
			            $newTokensDecoded = json_decode($tokenResponse, true);
			            if (isset($newTokensDecoded['access_token'])) {
			                // Keep the old refresh_token if a new one isn't provided
			                $newRefreshToken = isset($newTokensDecoded['refresh_token']) ? $newTokensDecoded['refresh_token'] : $tokens['refresh_token'];
			                
			                $updatedTokensArr = [
			                    'access_token' => $newTokensDecoded['access_token'],
			                    'refresh_token' => $newRefreshToken
			                ];
			                
			                // Update db
			                global $adb;
			                $newExpiresOn = time() + $newTokensDecoded['expires_in'];
			                if (!$this->from_email) {
                                $newPassword = Vtiger_Functions::toProtectedText(json_encode($updatedTokensArr));
                                $adb->pquery(
                                    "UPDATE vtiger_systems SET server_password=?, smtp_auth_expireson=? WHERE server_type=? AND smtp_auth_type=?",
                                    array($newPassword, $newExpiresOn, 'email', 'XOAUTH2')
                                );
			                } else {
                                $newPassword = Vtiger_Functions::toProtectedText(json_encode($updatedTokensArr));
                                $adb->pquery(
                                    "UPDATE vtiger_mail_accounts SET mail_password=?, auth_expireson=? WHERE account_id=?",
                                    array($newPassword, $newExpiresOn, $this->from_email)
                                );
			                }
                            
			                // Retry sending message
        			        $ch = curl_init($url);
        			        curl_setopt($ch, CURLOPT_POST, true);
                			curl_setopt($ch, CURLOPT_HTTPHEADER, [
                				'Authorization: Bearer ' . $updatedTokensArr['access_token'],
                				'Content-Type: application/json',
                			]);
                			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
                			
                			$response = curl_exec($ch);
                			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                			curl_close($ch);
                			
                			if($httpCode == 202){
                				return true;
                			}
			            }
			        } else {
                        $this->ErrorInfo = "Office365 Graph API Token Refresh Error (HTTP $tokenHttpCode): " . $tokenResponse . " / CURL Error: " . $error;
                        return false;
			        }
			    }
			}

			$this->ErrorInfo = "Office365 Graph API Error (HTTP $httpCode): " . $response;
            return false;
			
		} else if (stripos($this->Host, "gmail") !== FALSE || stripos($this->Host, "google") !== FALSE) {
			
			$tokens = json_decode($this->Password, true);
			$access_token = $tokens['access_token'];
			
			if (!$this->preSend()) {
				return false;
			}
			$rawMessage = $this->getMailString();
			$rawMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

			$emailData = [
				'raw' => $rawMessage
			];
			
			$url = "https://www.googleapis.com/gmail/v1/users/me/messages/send";
			
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Authorization: Bearer ' . $access_token,
				'Content-Type: application/json',
			]);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
			
			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);
			
            if($httpCode == 200){
                return true;
            }

			// If unauthorized, attempt to refresh the token
			if ($httpCode == 401) {
			    require_once 'modules/Oauth2/Config.php';
			    $cfgfile = "oauth2callback/config.oauth2.php";
			    if (file_exists($cfgfile)) {
			        $cfgdata = require_once $cfgfile;
			        $config = Oauth2_Config::loadConfig($cfgdata);
			        $cfg = $config->getProviderConfig('Google');
			        
			        // Manual token refresh via CURL
			        $tokens = json_decode($this->Password, true);
			        $tokenUrl = 'https://oauth2.googleapis.com/token';
			        $postFields = http_build_query([
			            'client_id' => $cfg['clientId'],
			            'client_secret' => $cfg['clientSecret'],
			            'refresh_token' => $tokens['refresh_token'],
			            'grant_type' => 'refresh_token'
			        ]);
			        
			        $chToken = curl_init($tokenUrl);
			        curl_setopt($chToken, CURLOPT_POST, true);
			        curl_setopt($chToken, CURLOPT_POSTFIELDS, $postFields);
			        curl_setopt($chToken, CURLOPT_RETURNTRANSFER, true);
			        curl_setopt($chToken, CURLOPT_TIMEOUT, 15);
			        curl_setopt($chToken, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
			        $tokenResponse = curl_exec($chToken);
			        $tokenHttpCode = curl_getinfo($chToken, CURLINFO_HTTP_CODE);
			        curl_close($chToken);
			        
			        if ($tokenHttpCode == 200) {
			            $newTokensDecoded = json_decode($tokenResponse, true);
			            if (isset($newTokensDecoded['access_token'])) {
			                // Keep the old refresh_token if a new one isn't provided
			                $newRefreshToken = isset($newTokensDecoded['refresh_token']) ? $newTokensDecoded['refresh_token'] : $tokens['refresh_token'];
			                
			                $updatedTokensArr = [
			                    'access_token' => $newTokensDecoded['access_token'],
			                    'refresh_token' => $newRefreshToken
			                ];
			                
			                // Update db
			                global $adb;
			                $newExpiresOn = time() + $newTokensDecoded['expires_in'];
			                if (!$this->from_email) {
                                $newPassword = Vtiger_Functions::toProtectedText(json_encode($updatedTokensArr));
                                $adb->pquery(
                                    "UPDATE vtiger_systems SET server_password=?, smtp_auth_expireson=? WHERE server_type=? AND smtp_auth_type=?",
                                    array($newPassword, $newExpiresOn, 'email', 'XOAUTH2')
                                );
			                } else {
                                $newPassword = Vtiger_Functions::toProtectedText(json_encode($updatedTokensArr));
                                $adb->pquery(
                                    "UPDATE vtiger_mail_accounts SET mail_password=?, auth_expireson=? WHERE account_id=?",
                                    array($newPassword, $newExpiresOn, $this->from_email)
                                );
			                }
                            
			                // Retry sending message
        			        $ch = curl_init($url);
        			        curl_setopt($ch, CURLOPT_POST, true);
                			curl_setopt($ch, CURLOPT_HTTPHEADER, [
                				'Authorization: Bearer ' . $updatedTokensArr['access_token'],
                				'Content-Type: application/json',
                			]);
                			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
                			
                			$response = curl_exec($ch);
                			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                			curl_close($ch);
                			
                			if($httpCode == 200){
                				return true;
                			}
			            }
			        } else {
                        $this->ErrorInfo = "Google API Token Refresh Error (HTTP $tokenHttpCode): " . $tokenResponse . " / CURL Error: " . $error;
                        return false;
			        }
			    }
			}

			$this->ErrorInfo = "Google API Error (HTTP $httpCode): " . $response;
            return false;

		} else {
		
			if(!$this->_serverConfigured) return;

			if($sync) return parent::Send();

			$this->__AddToQueue($linktoid);
			return true;
		}
	}

	/**
	 * Send mail using the email template
	 * @param String Recipient email
	 * @param String Recipient name
	 * @param String vtiger CRM Email template name to use
	 */
	function SendTo($toemail, $toname='', $emailtemplate=false, $linktoid=false, $sync=false) {
		if(empty($toname)) $toname = $toemail;
		$this->AddAddress($toemail, $toname);
		if($emailtemplate) $this->initFromTemplate($emailtemplate);
		return $this->Send($sync, $linktoid);
	}

	/** Mail Queue **/
	// Check if this instance is initialized.
	var $_queueinitialized = false;
	function __initializeQueue() {
		if(!$this->_queueinitialized) {
			if(!Vtiger_Utils::CheckTable('vtiger_mailer_queue')) {
				Vtiger_Utils::CreateTable('vtiger_mailer_queue',
					'(id INT NOT NULL PRIMARY KEY,
					fromname VARCHAR(100), fromemail VARCHAR(100),
					mailer VARCHAR(10), content_type VARCHAR(15), subject VARCHAR(999), body TEXT, relcrmid INT,
					failed INT(1) NOT NULL DEFAULT 0, failreason VARCHAR(255))',
					true);
			}
			if(!Vtiger_Utils::CheckTable('vtiger_mailer_queueinfo')) {
				Vtiger_Utils::CreateTable('vtiger_mailer_queueinfo',
					'(id INTEGER, name VARCHAR(100), email VARCHAR(100), type VARCHAR(7))',
					true);
			}
			if(!Vtiger_Utils::CheckTable('vtiger_mailer_queueattachments')) {
				Vtiger_Utils::CreateTable('vtiger_mailer_queueattachments',
					'(id INTEGER, path TEXT, name VARCHAR(100), encoding VARCHAR(50), type VARCHAR(100))',
					true);
			}
			$this->_queueinitialized = true;
		}
		return true;
	}

	/**
	 * Add this mail to queue
	 */
	function __AddToQueue($linktoid) {
		if($this->__initializeQueue()) {
			global $adb;
			$uniqueid = self::__getUniqueId();
			$adb->pquery('INSERT INTO vtiger_mailer_queue(id,fromname,fromemail,content_type,subject,body,mailer,relcrmid) VALUES(?,?,?,?,?,?,?,?)',
				Array($uniqueid, $this->FromName, $this->From, $this->ContentType, $this->Subject, $this->Body, $this->Mailer, $linktoid));
			$queueid = $adb->database->Insert_ID();
			foreach($this->to as $toinfo) {
				if(empty($toinfo[0])) continue;
				$adb->pquery('INSERT INTO vtiger_mailer_queueinfo(id, name, email, type) VALUES(?,?,?,?)',
					Array($queueid, $toinfo[1], $toinfo[0], 'TO'));
			}
			foreach($this->cc as $ccinfo) {
				if(empty($ccinfo[0])) continue;
				$adb->pquery('INSERT INTO vtiger_mailer_queueinfo(id, name, email, type) VALUES(?,?,?,?)',
					Array($queueid, $ccinfo[1], $ccinfo[0], 'CC'));
			}
			foreach($this->bcc as $bccinfo) {
				if(empty($bccinfo[0])) continue;
				$adb->pquery('INSERT INTO vtiger_mailer_queueinfo(id, name, email, type) VALUES(?,?,?,?)',
					Array($queueid, $bccinfo[1], $bccinfo[0], 'BCC'));
			}
			foreach($this->ReplyTo as $rtoinfo) {
				if(empty($rtoinfo[0])) continue;
				$adb->pquery('INSERT INTO vtiger_mailer_queueinfo(id, name, email, type) VALUES(?,?,?,?)',
					Array($queueid, $rtoinfo[1], $rtoinfo[0], 'RPLYTO'));
			}
			foreach($this->attachment as $attachmentinfo) {
				if(empty($attachmentinfo[0])) continue;
				$adb->pquery('INSERT INTO vtiger_mailer_queueattachments(id, path, name, encoding, type) VALUES(?,?,?,?,?)',
					Array($queueid, $attachmentinfo[0], $attachmentinfo[2], $attachmentinfo[3], $attachmentinfo[4]));
			}
		}
	}

	/**
	 * Function to prepares email as string
	 * @return type
	 */
	public function getMailString() {
		$le = (!empty($this->LE) ? $this->LE : "\r\n");
		return $this->MIMEHeader . $le . $this->MIMEBody;
	}

	/**
	 * Dispatch (send) email that was queued.
	 */
	static function dispatchQueue(Vtiger_Mailer_Listener $listener=null) {
		global $adb;
		if(!Vtiger_Utils::CheckTable('vtiger_mailer_queue')) return;

		$mailer = new self();
		$queue = $adb->pquery('SELECT * FROM vtiger_mailer_queue', array());
		if($adb->num_rows($queue)) {
			for($index = 0; $index < $adb->num_rows($queue); ++$index) {
				$mailer->reinitialize();

				$queue_record = $adb->fetch_array($queue, $index);
				$queueid = $queue_record['id'];
				$relcrmid= $queue_record['relcrmid'];

				$mailer->From = $queue_record['fromemail'];
				$mailer->From = $queue_record['fromname'];
				$mailer->Subject=$queue_record['subject'];
				$mailer->Body = decode_emptyspace_html($queue_record['body']);
				$mailer->Mailer=$queue_record['mailer'];
				$mailer->ContentType = $queue_record['content_type'];

				$emails = $adb->pquery('SELECT * FROM vtiger_mailer_queueinfo WHERE id=?', Array($queueid));
				for($eidx = 0; $eidx < $adb->num_rows($emails); ++$eidx) {
					$email_record = $adb->fetch_array($emails, $eidx);
					if($email_record['type'] == 'TO')     $mailer->AddAddress($email_record['email'], $email_record['name']);
					else if($email_record['type'] == 'CC')$mailer->AddCC($email_record['email'], $email_record['name']);
					else if($email_record['type'] == 'BCC')$mailer->AddBCC($email_record['email'], $email_record['name']);
					else if($email_record['type'] == 'RPLYTO')$mailer->AddReplyTo($email_record['email'], $email_record['name']);
				}

				$attachments = $adb->pquery('SELECT * FROM vtiger_mailer_queueattachments WHERE id=?', Array($queueid));
				for($aidx = 0; $aidx < $adb->num_rows($attachments); ++$aidx) {
					$attachment_record = $adb->fetch_array($attachments, $aidx);
					if($attachment_record['path'] != '') {
						$mailer->AddAttachment($attachment_record['path'], $attachment_record['name'],
												$attachment_record['encoding'], $attachment_record['type']);
					}
				}
				$sent = $mailer->Send(true);
				if($sent) {
					Vtiger_Event::trigger('vtiger.mailer.mailsent', $relcrmid);
					if($listener) {
						$listener->mailsent($queueid);
					}
					$adb->pquery('DELETE FROM vtiger_mailer_queue WHERE id=?', Array($queueid));
					$adb->pquery('DELETE FROM vtiger_mailer_queueinfo WHERE id=?', Array($queueid));
					$adb->pquery('DELETE FROM vtiger_mailer_queueattachments WHERE id=?', Array($queueid));
				} else {
					if($listener) {
						$listener->mailerror($queueid);
					}
					$adb->pquery('UPDATE vtiger_mailer_queue SET failed=?, failreason=? WHERE id=?', Array(1, $mailer->ErrorInfo, $queueid));
				}
			}
		}
	}
}

/**
 * Provides API to act on the different events triggered by send email action.
 * @package vtlib
 */
abstract class Vtiger_Mailer_Listener {
	function mailsent($queueid) { }
	function mailerror($queueid) { }
}

?>

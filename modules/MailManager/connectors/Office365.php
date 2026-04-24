<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

vimport ('~modules/MailManager/models/Message.php');

class MailManager_Office365_Connector extends MailManager_Connector_Connector {

    public $access_token;
    public $refresh_token;
    public $model;
    public $officeFolders = array();
    protected $baseUrl = 'https://graph.microsoft.com/v1.0';


    public static function connectorWithModel($model, $type = '') {
        $tokens = json_decode($model->password(), true);
        return new MailManager_Office365_Connector($tokens, $model);
    }

    public function __construct($tokens = false, $model = null) {
        $this->baseUrl = 'https://graph.microsoft.com/v1.0';
        $this->model = $model;
        if (!$tokens || !isset($tokens['access_token'])) {
            throw new Exception('Invalid tokens provided');
        }
        $this->access_token  = $tokens['access_token'];
        $this->refresh_token = $tokens['refresh_token'] ?? null;
        
        $expiresOn = $model ? $model->authexpireson() : 0;
        if (time() >= ($expiresOn - 300)) {
            $this->refreshToken();
        }

        try {
            $this->makeGraphRequest('/me');
            $this->mBox = true;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '401') !== false && !empty($this->refresh_token)) {
                 if ($this->refreshToken()) {
                     try {
                         $this->makeGraphRequest('/me');
                         $this->mBox = true;
                     } catch (Exception $e2) {
                         $this->mBox = false;
                     }
                 } else {
                     $this->mBox = false;
                 }
            } else {
                $this->mBox = false;
            }
        }
    }

    public function refreshToken() {
        if (empty($this->refresh_token)) {
            return false;
        }

        require_once "vendor/autoload.php";
        require_once "modules/Oauth2/Config.php";
        $cfgdata = require_once "oauth2callback/config.oauth2.php";
        $config = Oauth2_Config::loadConfig($cfgdata);
        $authcfg = $config->getProviderConfig('Office365');

        if (empty($authcfg['clientId'])) {
            return false;
        }

        $provider = new \League\OAuth2\Client\Provider\GenericProvider($authcfg);
        try {
            $newAccessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->refresh_token
            ]);

            $this->access_token = $newAccessToken->getToken();
            if ($newAccessToken->getRefreshToken()) {
                $this->refresh_token = $newAccessToken->getRefreshToken();
            }
            $expiresOn = $newAccessToken->getExpires();

            $tokens = [
                'access_token' => $this->access_token,
                'refresh_token' => $this->refresh_token
            ];

            if ($this->model) {
                $db = PearDatabase::getInstance();
                $db->pquery("UPDATE vtiger_mail_accounts SET mail_password=?, auth_expireson=? WHERE account_id=?", 
                    array(Vtiger_Functions::toProtectedText(json_encode($tokens)), $expiresOn, $this->model->mId));
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function makeGraphRequest($endpoint, $method = 'GET', $data = null) {
        $url     = $this->baseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->access_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new Exception('HTTP Error ' . $httpCode . ': ' . $response);
        }
        return json_decode($response, true);
    }

    public function isConnected() {
        return !empty($this->mBox);
    }

    public function folders($ref = "{folder}") {
        if ($this->mFolders) return $this->mFolders;
        if (!$this->isConnected()) return array();
        try {
            $response    = $this->makeGraphRequest('/me/mailFolders?$top=100');
            $folder_data = array();
            if (isset($response['value'])) {
                foreach ($response['value'] as $folderData) {
                    $folderInstance = $this->folderInstance($folderData['displayName']);
                    $folderInstance->setFromArray($folderData);
                    $folderInstance->folderId = base64_encode($folderData['id']);
                    $folder_data[] = $folderInstance;
                }
            }
            $this->mFolders = $folder_data;
            return $folder_data;
        } catch (Exception $e) {
            return array();
        }
    }

    public function folderInstance($val) {
        return new MailManager_Office365Folder_Model($val);
    }

    public function updateFolders($options = SA_UNSEEN) {
        $this->folders();
        if (!empty($this->mFolders)) {
            foreach ($this->mFolders as $folder) {
                if (strtolower($folder->name()) == 'inbox') {
                    $this->updateFolder($folder, $options);
                }
            }
        }
    }

    public function updateFolder($folder, $options) {
        if (!$this->isConnected()) return;
        try {
            $folder->setCount('0');
            $folderid = '';
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folder->name())) {
                    $folderid = $key;
                    break;
                }
            }
            if ($folderid) {
                $endpoint   = '/me/mailFolders/' . base64_decode($folderid) . '/messages?$filter=IsRead%20ne%20true&$count=true';
                $response   = $this->makeGraphRequest($endpoint);
                $unreadCount = isset($response['@odata.count']) ? $response['@odata.count'] : 0;
                $folder->setUnreadCount((string)$unreadCount);
            }
        } catch (Exception $e) {
            $folder->setUnreadCount('0');
        }
    }

    public function getFolderList() {
        $folders     = $this->folders();
        $folderLists = array();
        if (!empty($folders)) {
            foreach ($folders as $folder) {
                $folderLists[$folder->folderId] = $folder->name();
            }
        }
        return $folderLists;
    }

    /**
     * Check expanded attachments array for at least one real non-inline fileAttachment.
     * Does NOT check contentBytes — Graph does not return it in list/expand responses.
     */
    private function hasRealFileAttachment($attachments) {
        if (empty($attachments) || !is_array($attachments)) return false;
        foreach ($attachments as $att) {
            if (
                isset($att['@odata.type']) &&
                $att['@odata.type'] === '#microsoft.graph.fileAttachment' &&
                isset($att['isInline']) &&
                $att['isInline'] === false
            ) {
                return true;
            }
        }
        return false;
    }

    public function folderMails($folder, $page_number, $maxLimit) {
        if (!$this->isConnected()) return false;

        try {
            $folderid = '';
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folder->name())) {
                    $folderid = $key;
                    break;
                }
            }
            if (!$folderid) return false;

            $folderid = base64_decode($folderid);
            $folder->setNextLink('');
            $folder->setPreviousLink('');
            if (!($maxLimit > 0)) $maxLimit = 20;

            $skip_records = $page_number * $maxLimit;
            $start        = $page_number * $maxLimit + 1;
            $end          = $start;
            if ($start < 1) $start = 1;
            if (!($start <= 1)) $folder->setPreviousLink('true');

            $queryParams = [
                '$expand=attachments',
                '$orderby=receivedDateTime%20desc',
                '$skip='  . $skip_records,
                '$top='   . $maxLimit,
                '$count=true'
            ];
            $endpoint = '/me/mailFolders/' . $folderid . '/messages?' . implode('&', $queryParams);
            $response = $this->makeGraphRequest($endpoint);

            $totalCount      = isset($response['@odata.count']) ? $response['@odata.count'] : 0;
            $folder_messages = isset($response['value']) ? $response['value'] : [];
            $folder->setCount($totalCount);


            if (!empty($folder_messages)) {
                $end     = $start + count($folder_messages) - 1;
                $mails   = array();
                $mailIds = array();

                foreach ($folder_messages as $messageData) {
                    $rawAttachments = isset($messageData['attachments']) ? $messageData['attachments'] : array();
                    $hasReal        = $this->hasRealFileAttachment($rawAttachments);

                    // Log every message so we can see the raw API values vs our computed value

                    // Inject computed flag so parseOverview uses it
                    $messageData['_hasRealAttachments'] = $hasReal;

                    // Fresh instance per email — prevents attachment bleed between emails
                    $messageModel = new MailManager_Office365Message_Model();
                    $loaded       = $messageModel->readFromDB($messageData['id']);


                    $mailObject = $messageModel->parseOverview($messageData);
                    $mailObject->_inline_attachments = array();

                    if (!$loaded) {
                        $loaded = new MailManager_Office365Message_Model(
                            'office365', $messageData['id'], true, $messageData
                        );
                    }

                    $mailId = $loaded->_mailRecordId;

                    $mailObject->setMsgNo($mailId);
                    $mails[]   = $mailObject;
                    $mailIds[] = $mailId;
                }

                $folder->setMails($mails);
                $folder->setMailIds($mailIds);
                $folder->setPaging($start, $end, $maxLimit, $totalCount, $page_number);

                if (count($folder_messages) == $maxLimit && ($skip_records + $maxLimit) < $totalCount) {
                    $folder->setNextLink('true');
                }
            }

        } catch (Exception $e) {
            return false;
        }
    }

    public function close() {
        if (!empty($this->mBox)) $this->mBox = null;
    }

    public function openMail($msgno, $folder) {
        $this->clearDBCache();
        return MailManager_Office365Message_Model::getMailDetailById($msgno);
    }

    public function markMailRead($msgno) {
        if (!$this->isConnected()) return false;
        try {
            $this->makeGraphRequest('/me/messages/' . $msgno, 'PATCH', array("isRead" => true));
            $this->mModified = true;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function markMailUnread($msgno) {
        if (!$this->isConnected()) return false;
        try {
            $this->makeGraphRequest('/me/messages/' . $msgno, 'PATCH', array("isRead" => false));
            $this->mModified = true;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function deleteMail($msgno) {
        if (!$this->isConnected()) return false;
        $msgno      = trim($msgno, ',');
        $msgnoArray = explode(',', $msgno);
        $success    = true;
        try {
            foreach ($msgnoArray as $messageId) {
                $messageId = trim($messageId);
                if (!empty($messageId)) {
                    try {
                        $this->makeGraphRequest('/me/messages/' . $messageId, 'DELETE');
                    } catch (Exception $e) {
                        $success = false;
                    }
                }
            }
            if ($success) $this->mModified = true;
            return $success;
        } catch (Exception $e) {
            return false;
        }
    }

    public function moveMail($msgno, $folderName) {
        if (!$this->isConnected()) return false;
        try {
            $folderid = '';
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folderName)) {
                    $folderid = $key;
                    break;
                }
            }
            if (empty($folderid)) return false;

            $msgno      = trim($msgno, ',');
            $msgnoArray = explode(',', $msgno);
            $data       = array('destinationId' => base64_decode($folderid));
            $success    = true;
            foreach ($msgnoArray as $messageId) {
                $messageId = trim($messageId);
                if (!empty($messageId)) {
                    try {
                        $this->makeGraphRequest('/me/messages/' . $messageId . '/move', 'POST', $data);
                    } catch (Exception $e) {
                        $success = false;
                    }
                }
            }
            if ($success) $this->mModified = true;
            return $success;
        } catch (Exception $e) {
            return false;
        }
    }

    public function searchMails($query, $folder, $page_number, $maxLimit, $skipToken = null) {
        if (!$this->isConnected()) return false;

        try {
            $folder->setNextLink('');
            $folder->setPreviousLink('');
            if (!($maxLimit > 0)) $maxLimit = 20;

            $folderId = '';
            foreach ($this->getFolderList() as $key => $officeFolder) {
                if (strtoupper($officeFolder) == strtoupper($folder->name())) {
                    $folderId = $key;
                    break;
                }
            }
            if (empty($folderId)) return false;

            $decodedFolderId = base64_decode($folderId);
            $formattedQuery  = '';
            if (!empty($query)) {
                $parts  = explode(' ', trim($query));
                $keyword = $parts[0];
                $value   = isset($parts[1]) ? str_replace('"', '', $parts[1]) : '';
                $formattedQuery = !empty($value)
                    ? '"' . $keyword . ':' . $value . '"'
                    : '"' . $keyword . '"';
            }
            if (empty($formattedQuery)) return false;

            $skip_records = $page_number * $maxLimit;
            $start        = $page_number * $maxLimit + 1;
            $end          = $start;
            if ($start < 1) $start = 1;

            $queryParams = [
                '$search=' . rawurlencode($formattedQuery),
                '$expand=attachments',
                '$top='   . $maxLimit,
                '$count=true'
            ];
            if (!empty($_SESSION['office365']['search'][$page_number])) {
                $queryParams[] = '$skipToken=' . rawurlencode($_SESSION['office365']['search'][$page_number]);
            }

            $endpoint = '/me/mailFolders/' . $decodedFolderId . '/messages?' . implode('&', $queryParams);
            $response = $this->makeGraphRequest($endpoint);
            if (!$response) return false;

            $records           = isset($response['value']) ? $response['value'] : [];
            $actualRecordCount = count($records);
            $totalCount        = isset($response['@odata.count']) ? $response['@odata.count'] : 0;

            if ($totalCount == 0 && $actualRecordCount > 0) {
                $totalCount = ($actualRecordCount == $maxLimit)
                    ? ($page_number + 2) * $maxLimit
                    : ($page_number * $maxLimit) + $actualRecordCount;
            }
            $folder->setCount($totalCount);


            if (!empty($records)) {
                $end     = $start + count($records) - 1;
                $mails   = array();
                $mailIds = array();

                foreach ($records as $messageData) {
                    $rawAttachments = isset($messageData['attachments']) ? $messageData['attachments'] : array();
                    $hasReal        = $this->hasRealFileAttachment($rawAttachments);


                    $messageData['_hasRealAttachments'] = $hasReal;

                    $messageModel = new MailManager_Office365Message_Model();
                    $loaded       = $messageModel->readFromDB($messageData['id']);
                    $mailObject   = $messageModel->parseOverview($messageData);
                    $mailObject->_inline_attachments = array();

                    if (!$loaded) {
                        $loaded = new MailManager_Office365Message_Model(
                            'office365', $messageData['id'], true, $messageData
                        );
                    }

                    $mailId = $loaded->_mailRecordId;
                    $mailObject->setMsgNo($mailId);
                    $mails[]   = $mailObject;
                    $mailIds[] = $mailId;
                }

                $folder->setMails($mails);
                $folder->setMailIds($mailIds);

            } else {
                $folder->setMails(array());
                $folder->setMailIds(array());
                if ($totalCount == 0) { $start = 0; $end = 0; }
            }

            $folder->setPaging($start, $end, $maxLimit, $totalCount, $page_number);
            if (!($start <= 1)) $folder->setPreviousLink('true');

            if (!empty($records)) {
                if (isset($response['@odata.nextLink']) && $response['@odata.nextLink'] != '') {
                    if (preg_match('/(?:%24|\$)skiptoken=([^&]+)/i', $response['@odata.nextLink'], $matches)) {
                        $nextSkipToken = urldecode($matches[1]);
                        if ($page_number > 0) {
                            $_SESSION['office365']['search'][$page_number] = $nextSkipToken;
                        }
                        $folder->setNextLink($nextSkipToken);
                    }
                } elseif (count($records) == $maxLimit) {
                    $folder->setNextLink('true');
                }
            }

            return true;

        } catch (Exception $e) {
            return false;
        }
    }
}
?>
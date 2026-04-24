<?php

/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is: vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

vimport('~~/modules/Settings/MailConverter/handlers/MailRecord.php');

class MailManager_Office365Message_Model extends Vtiger_MailRecord {

    protected $mBox;
    protected $mRead = false;
    protected $hasAttachments = false;
    protected $mMsgNo = false;
    protected $mUid = false;
    public $_attachments = array();


    public function __construct($mBox=false, $msgno=false, $fetchbody=false, $mail_data = array()) {
        if ($mBox && $msgno) {
            $this->mBox   = $mBox;
            $this->mMsgNo = $msgno;
            $loaded       = false;
            $this->mUid   = $this->mMsgNo;

            if ($fetchbody) {
                $loaded = $this->readFromDB($this->mUid);
            }

            if (!$loaded && !empty($mail_data)) {
                $from_address = array();
                if (!empty($mail_data['from']['emailAddress']['name'])) {
                    $this->fromName = $mail_data['from']['emailAddress']['name'];
                    $from_address[] = $mail_data['from']['emailAddress']['address'];
                } else {
                    $this->fromName = '';
                }

                $to_address = array();
                foreach ($mail_data['toRecipients'] as $eaddress) {
                    if (!empty($eaddress['emailAddress']['address']))
                        $to_address[] = $eaddress['emailAddress']['address'];
                }

                $cc = array();
                foreach ($mail_data['ccRecipients'] as $eaddress) {
                    if (!empty($eaddress['emailAddress']['address']))
                        $cc[] = $eaddress['emailAddress']['address'];
                }

                $bcc = array();
                foreach ($mail_data['bccRecipients'] as $eaddress) {
                    if (!empty($eaddress['emailAddress']['address']))
                        $bcc[] = $eaddress['emailAddress']['address'];
                }

                $this->_from     = $from_address;
                $this->_to       = $to_address;
                $this->_uniqueid = $mail_data['id'];
                $this->_cc       = $cc;
                $this->_bcc      = $bcc;

                $date        = new DateTime($mail_data['receivedDateTime']);
                $this->_date = strtotime($date->format('Y-m-d H:i:s'));
                $this->_subject = self::__mime_decode($mail_data['subject']);
                if (!$this->_subject) $this->_subject = 'Untitled';

                if ($fetchbody) {
                    $this->_plainmessage = '';
                    $this->_htmlmessage  = '';
                    $this->_body         = '';
                    $this->_isbodyhtml   = false;

                    $content_type = $mail_data['body']['contentType'];
                    $data = self::__convert_encoding($mail_data['body']['content'], 'UTF-8');

                    if (strtolower($content_type) == 'text') {
                        $this->_plainmessage .= trim($data) . "\n\n";
                    } else {
                        $this->_htmlmessage .= $data . "<br><br>";
                    }

                    if ($this->_htmlmessage != '') {
                        $this->_body       = $this->_htmlmessage;
                        $this->_isbodyhtml = true;
                    } else {
                        $this->_body = $this->_plainmessage;
                    }

                    // Only keep real fileAttachments that have contentBytes
                    $rawAttachments  = isset($mail_data['attachments']) ? $mail_data['attachments'] : array();
                    $realAttachments = array();
                    foreach ($rawAttachments as $attachment) {
                        if (
                            isset($attachment['@odata.type']) &&
                            $attachment['@odata.type'] === '#microsoft.graph.fileAttachment' &&
                            !empty($attachment['contentBytes'])
                        ) {
                            $realAttachments[] = $attachment;
                        }
                    }


                    if (!empty($realAttachments)) {
                        $this->setAttachments('true');
                        $this->_attachments = $realAttachments;
                    } else {
                        $this->setAttachments('false');
                        $this->_attachments = array();
                    }

                    $this->_bodyparsed = true;
                }
            }

            if ($fetchbody) {
                $loaded = $this->saveToDB($this->mUid);
            }

            if ($loaded) {
                $this->setRead(true);
                $this->setMsgNo($msgno);
            }
        }
    }

    function hasFileAttachments($attachments = array()) {
        if (empty($attachments) || !is_array($attachments)) return false;
        foreach ($attachments as $attachment) {
            if (
                isset($attachment['@odata.type']) &&
                $attachment['@odata.type'] === '#microsoft.graph.fileAttachment' &&
                isset($attachment['isInline']) &&
                $attachment['isInline'] === false
            ) {
                return true;
            }
        }
        return false;
    }

    public static function pruneOlderInDB($waybacktime) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();
        self::removeSavedAttachmentFiles($waybacktime);
        $db->pquery("DELETE FROM vtiger_mailmanager_mailrecord WHERE userid=? AND lastsavedtime < ?",
            array($currentUserModel->getId(), $waybacktime));
        $db->pquery("DELETE FROM vtiger_mailmanager_mailattachments WHERE userid=? AND lastsavedtime < ?",
            array($currentUserModel->getId(), $waybacktime));
    }

    public static function removeSavedAttachmentFiles($waybacktime) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();
        $r = $db->pquery(
            "SELECT attachid, aname, path FROM vtiger_mailmanager_mailattachments WHERE userid=? AND lastsavedtime < ?",
            array($currentUserModel->getId(), $waybacktime)
        );
        for ($i = 0; $i < $db->num_rows($r); $i++) {
            $row = $db->raw_query_result_rowdata($r, $i);
            $db->pquery("UPDATE vtiger_crmentity set deleted=1 WHERE crmid=?", array($row['attachid']));
            $fp = $row['path'] . "/" . $row['attachid'] . "_" . $row['aname'];
            if (file_exists($fp)) unlink($fp);
        }
    }

    public function readFromDB($uid) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();
        $result = $db->pquery(
            "SELECT * FROM vtiger_mailmanager_mailrecord WHERE userid=? AND BINARY muid=?",
            array($currentUserModel->getId(), $uid)
        );
        if ($db->num_rows($result)) {
            $r = $db->fetch_array($result);
            $this->mUid          = $r['muid'];
            $this->_from         = Zend_Json::decode(decode_html($r['mfrom']));
            $this->_to           = Zend_Json::decode(decode_html($r['mto']));
            $this->_cc           = Zend_Json::decode(decode_html($r['mcc']));
            $this->_bcc          = Zend_Json::decode(decode_html($r['mbcc']));
            $this->_date         = decode_html($r['mdate']);
            $this->_subject      = str_replace("_", " ", decode_html($r['msubject']));
            $this->_body         = decode_html($r['mbody']);
            $this->_charset      = decode_html($r['mcharset']);
            $this->_isbodyhtml   = intval($r['misbodyhtml'])  ? true : false;
            $this->_plainmessage = intval($r['mplainmessage'])? true : false;
            $this->_htmlmessage  = intval($r['mhtmlmessage']) ? true : false;
            $this->_uniqueid     = decode_html($r['muniqueid']);
            $this->_bodyparsed   = intval($r['mbodyparsed'])  ? true : false;
            $this->_mailRecordId = $r['muid'];
            $this->fromName      = $r['mfromname'];
            return $this;
        }
        return false;
    }

    protected function loadAttachmentsFromDB($withContent, $aName=false, $aId=false) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();

        // CRITICAL: always reset before querying — never serve cached data
        $this->_attachments = array();

        $muid = $this->muid();


        $params      = array($currentUserModel->getId(), $muid);
        $whereClause = "AND cid IS NULL";
        if ($aName) { $whereClause .= " AND aname=?";    $params[] = $aName; }
        if ($aId)   { $whereClause .= " AND attachid=?"; $params[] = $aId; }

        $atResult = $db->pquery(
            "SELECT aname, attachid, path, cid FROM vtiger_mailmanager_mailattachments
             WHERE userid=? AND muid=? $whereClause",
            $params
        );

        $count = $db->num_rows($atResult);

        if ($count) {
            for ($i = 0; $i < $count; $i++) {
                $row         = $db->raw_query_result_rowdata($atResult, $i);
                $fileContent = false;


                if ($withContent) {
                    $binFile        = sanitizeUploadFileName($row['aname'], vglobal('upload_badext'));
                    $saved_filename = $row['path'] . $row['attachid'] . '_' . $binFile;
                    if (file_exists($saved_filename)) {
                        $fileContent = @fread(fopen($saved_filename, "r"), filesize($saved_filename));
                    }
                }

                $filePath = $row['path'] . $row['attachid'] . '_' .
                            sanitizeUploadFileName($row['aname'], vglobal('upload_badext'));
                $fileSize = $this->convertFileSize(file_exists($filePath) ? filesize($filePath) : 0);

                $this->_attachments[] = array(
                    'filename' => $row['aname'],
                    'data'     => ($withContent ? $fileContent : false),
                    'size'     => $fileSize,
                    'path'     => $filePath,
                    'attachid' => $row['attachid']
                );
                unset($fileContent);
            }
            $atResult->free();
            unset($atResult);
        }

    }

    protected function saveToDB($uid) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();

        $savedtime = strtotime("now");
        $params = array(
            $currentUserModel->getId(), $uid,
            Zend_Json::encode($this->_from),
            Zend_Json::encode($this->_to),
            Zend_Json::encode($this->_cc),
            Zend_Json::encode($this->_bcc),
            $this->_date,
            $this->_subject,
            $this->_body,
            $this->_charset,
            $this->_isbodyhtml,
            $this->_plainmessage,
            $this->_htmlmessage,
            $this->_uniqueid,
            $this->_bodyparsed,
            $savedtime
        );

        $db->pquery(
            "INSERT INTO vtiger_mailmanager_mailrecord
            (userid, muid, mfrom, mto, mcc, mbcc, mdate, msubject, mbody, mcharset,
             misbodyhtml, mplainmessage, mhtmlmessage, muniqueid, mbodyparsed, lastsavedtime)
            VALUES (" . generateQuestionMarks($params) . ")",
            $params, true
        );
        $this->_mailRecordId = $uid;

        $msgAttachments = array();


        if (!empty($this->_attachments)) {
            foreach ($this->_attachments as $attachment) {
                if (!isset($attachment['@odata.type']) ||
                    $attachment['@odata.type'] !== '#microsoft.graph.fileAttachment') {
                    continue;
                }
                if (empty($attachment['contentBytes'])) {
                    continue;
                }

                $aName    = $attachment['name'];
                $aValue   = base64_decode($attachment['contentBytes']);
                $isInline = isset($attachment['isInline']) ? (bool)$attachment['isInline'] : false;


                $attachInfo = $this->__SaveAttachmentFile($aName, $aValue);

                if (is_array($attachInfo) && !empty($attachInfo)) {
                    if ($isInline) {
                        $db->pquery(
                            "INSERT INTO vtiger_mailmanager_mailattachments
                            (userid, muid, attachid, aname, path, lastsavedtime, cid) VALUES (?,?,?,?,?,?,?)",
                            array($currentUserModel->getId(), $uid,
                                  $attachInfo['attachid'], $attachInfo['name'],
                                  $attachInfo['path'], $savedtime, $attachment['contentId'])
                        );
                    } else {
                        $db->pquery(
                            "INSERT INTO vtiger_mailmanager_mailattachments
                            (userid, muid, attachid, aname, path, lastsavedtime) VALUES (?,?,?,?,?,?)",
                            array($currentUserModel->getId(), $uid,
                                  $attachInfo['attachid'], $attachInfo['name'],
                                  $attachInfo['path'], $savedtime)
                        );
                        $msgAttachments[$attachInfo['name']] = $aValue;
                    }
                }
                unset($aValue);
            }
        }

        unset($this->_attachments);
        $this->_attachments = $msgAttachments;
        return true;
    }

    public function __SaveAttachmentFile($filename, $filecontent) {
        require_once 'modules/Settings/MailConverter/handlers/MailAttachmentMIME.php';
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();
        $filename   = imap_utf8($filename);
        $dirname    = decideFilePath();
        $usetime    = $db->formatDate(date('Y-m-d H:i:s'), true);
        $binFile    = sanitizeUploadFileName($filename, vglobal('upload_badext'));
        $attachid   = $db->getUniqueId('vtiger_crmentity');
        $saveasfile = "$dirname/$attachid" . "_" . $binFile;
        $fh = @fopen($saveasfile, 'wb');
        if ($fh) {
            fwrite($fh, $filecontent);
            fclose($fh);
        } else {
            error_log("MailManager_Office365Message_Model::__SaveAttachmentFile: failed to open $saveasfile for writing.");
            return false;
        }
        $mimetype = MailAttachmentMIME::detect($saveasfile);
        $db->pquery(
            "INSERT INTO vtiger_crmentity(crmid, smcreatorid, smownerid, modifiedby, setype,
            description, createdtime, modifiedtime, presence, deleted) VALUES (?,?,?,?,?,?,?,?,?,?)",
            array($attachid, $currentUserModel->getId(), $currentUserModel->getId(),
                  $currentUserModel->getId(), "MailManager Attachment",
                  $binFile, $usetime, $usetime, 1, 0)
        );
        $db->pquery(
            "INSERT INTO vtiger_attachments SET attachmentsid=?, name=?, description=?, type=?, path=?",
            array($attachid, $binFile, $binFile, $mimetype, $dirname)
        );
        return array('attachid' => $attachid, 'path' => $dirname, 'name' => $binFile,
                     'type' => $mimetype, 'size' => filesize($saveasfile));
    }

    public function attachments($withContent=true, $aName=false, $aId=false) {
        $this->loadAttachmentsFromDB($withContent, $aName, $aId);
        return $this->_attachments;
    }

    public function inlineAttachments() {
        return isset($this->_inline_attachments) ? $this->_inline_attachments : array();
    }

    public function subject($safehtml=true) {
        $s = str_replace("_", " ", $this->_subject);
        return $safehtml ? MailManager_Utils_Helper::safe_html_string($s) : $s;
    }

    public function setSubject($subject) {
        $this->_subject = @self::__mime_decode(str_replace("_", " ", $subject));
    }

    public function body($safehtml=true) {
        return $this->getBodyHTML($safehtml);
    }

    public function getBodyHTML($safehtml=true) {
        $bodyhtml = parent::getBodyHTML();
        
        // Process inline images - replace cid: references with data URIs
        $bodyhtml = $this->processInlineImages($bodyhtml);
        
        if ($safehtml) $bodyhtml = MailManager_Utils_Helper::safe_html_string($bodyhtml);
        return $bodyhtml;
    }

    /**
     * Process inline images in HTML body by replacing cid: references with data URIs
     */
    protected function processInlineImages($html) {
        if (empty($html) || strpos($html, 'cid:') === false) {
            return $html;
        }
        
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();
        
        // Load all inline attachments (cid IS NOT NULL) for this email
        $result = $db->pquery(
            "SELECT attachid, aname, path, cid FROM vtiger_mailmanager_mailattachments 
             WHERE userid=? AND BINARY muid=? AND cid IS NOT NULL",
            array($currentUserModel->getId(), $this->muid())
        );
        
        if ($db->num_rows($result) > 0) {
            for ($i = 0; $i < $db->num_rows($result); $i++) {
                $row = $db->raw_query_result_rowdata($result, $i);
                $cid = $row['cid'];
                
                if (empty($cid)) continue;
                
                // Build file path
                $binFile = sanitizeUploadFileName($row['aname'], vglobal('upload_badext'));
                $filePath = $row['path'] . $row['attachid'] . '_' . $binFile;
                
                if (file_exists($filePath)) {
                    // Read file and convert to base64 data URI
                    $imageData = file_get_contents($filePath);
                    $base64 = base64_encode($imageData);
                    
                    // Detect MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    
                    // Create data URI
                    $dataUri = 'data:' . $mimeType . ';base64,' . $base64;
                    
                    // Replace all occurrences of cid: reference with data URI
                    // Replace cid: references with data URI
                    $html = str_replace('cid:' . $cid, $dataUri, $html);
                }
            }
        }
        
        return $html;
    }

    public function from($maxlen=0) {
        $s = $this->_from;
        if ($maxlen && strlen($s) > $maxlen) $s = substr($s, 0, $maxlen-3) . '...';
        return $s;
    }

    public function setFrom($from) {
        $this->_from = @self::__mime_decode(str_replace("_", " ", $from));
    }

    public function to()  { return $this->_to; }
    public function cc()  { return $this->_cc; }
    public function bcc() { return $this->_bcc; }

    public function uniqueid() { return $this->_uniqueid; }
    public function muid()     { return $this->mUid; }

    public function date($format=false) {
        $date = $this->_date;
        if ($date) {
            if ($format) {
                $dateTimeFormat = Vtiger_Util_Helper::convertDateTimeIntoUsersDisplayFormat(
                    date('Y-m-d H:i:s', strtotime($date))
                );
                list($d, $time, $AMorPM) = explode(' ', $dateTimeFormat);
                $pos = strpos($dateTimeFormat, date(DateTimeField::getPHPDateFormat()));
                return ($pos === false) ? $d : $time . ' ' . $AMorPM;
            }
            return Vtiger_Util_Helper::convertDateTimeIntoUsersDisplayFormat(date('Y-m-d H:i:s', $date));
        }
        return '';
    }

    public function setDate($date)       { $this->_date  = $date; }
    public function isRead()             { return $this->mRead; }
    public function setRead($read)       { $this->mRead  = $read; }
    public function msgNo($offset=0)     { return $this->mMsgNo; }
    public function setMsgNo($msgno)     { $this->mMsgNo = $msgno; }
    public function setmUid($mUid)       { $this->mUid   = $mUid; }
    public function hasAttachments()     { return $this->hasAttachments; }
    public function setAttachments($val) { $this->hasAttachments = $val; }

    public function parseOverview($result) {
        $instance = new self();
        $instance->setSubject($result['subject']);

        $fromName    = !empty($result['from']['emailAddress']['name'])    ? $result['from']['emailAddress']['name']    : '';
        $fromAddress = !empty($result['from']['emailAddress']['address']) ? $result['from']['emailAddress']['address'] : '';
        $instance->setFrom($fromName . ' <' . $fromAddress . '>');

        $to = !empty($result['toRecipients']) ? $result['toRecipients'] : array();
        $to_address = array();
        foreach ($to as $ea) {
            if (!empty($ea['emailAddress']['address'])) $to_address[] = $ea['emailAddress']['address'];
        }
      
		if (!empty($to_address)) {
			$to_address = implode(", ", $to_address);
		} else {
			$to_address = '';
		}
		
        $instance->setTo($to_address);

        $date = new DateTime($result['receivedDateTime']);
        $instance->setDate($date->format('Y-m-d H:i:s'));

        if (!empty($result['isRead'])) $instance->setRead(true);

        // Use the pre-computed _hasRealAttachments flag injected by the connector
        if (array_key_exists('_hasRealAttachments', $result)) {
            $iconValue = $result['_hasRealAttachments'] === true;
            $instance->setAttachments($iconValue);
        } elseif (!empty($result['hasAttachments'])) {
            $instance->setAttachments(true);
        } else {
            $instance->setAttachments(false);
        }

        $instance->setmUid($result['id']);
        $instance->setMsgNo($result['id']);
        return $instance;
    }

    public function getInlineBody() {
        $bodytext = $this->body();
        $bodytext = preg_replace("/<br>/", " ", $bodytext);
        $bodytext = strip_tags($bodytext);
        $bodytext = preg_replace("/\n/", " ", $bodytext);
        return $bodytext;
    }

    function convertFileSize($size) {
        if ($size > 1048575) return round($size / (1024 * 1024), 2) . ' MB';
        if ($size > 1023)    return round($size / 1024, 2) . ' KB';
        return $size . ' Bytes';
    }

    public function getAttachmentIcon($fileName) {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'txt':                                                               return 'fa-file-text';
            case 'doc': case 'docx':                                                  return 'fa-file-word-o';
            case 'zip': case 'tar': case '7z': case 'apk': case 'bin':
            case 'bzip': case 'bzip2': case 'gz': case 'jar': case 'rar': case 'xz': return 'fa-file-archive-o';
            case 'jpeg': case 'jfif': case 'rif': case 'gif':
            case 'bmp': case 'jpg': case 'png':                                       return 'fa-file-image-o';
            case 'pdf':                                                               return 'fa-file-pdf-o';
            case 'mp3': case 'wma': case 'wav': case 'ogg':                          return 'fa-file-audio-o';
            case 'xls': case 'xlsx':                                                  return 'fa-file-excel-o';
            case 'webm': case 'mkv': case 'flv': case 'vob': case 'ogv':
            case 'avi': case 'mov': case 'mp4': case 'mpg': case 'mpeg': case '3gp': return 'fa-file-video-o';
            default:                                                                   return 'fa-file-o';
        }
    }

    public static function getMailDetailById($mailRecordId) {
        $db = PearDatabase::getInstance();
        $currentUserModel = Users_Record_Model::getCurrentUserModel();


        $result = $db->pquery(
            "SELECT * FROM vtiger_mailmanager_mailrecord WHERE userid=? AND BINARY muid=?",
            array($currentUserModel->getId(), $mailRecordId)
        );
        if ($db->num_rows($result)) {
            $r        = $db->fetch_array($result);
            $mailData = new self();
            $mailData->setMsgNo($mailRecordId);
            $mailData->setmUid($r['muid']);
            $mailData->_from         = Zend_Json::decode(decode_html($r['mfrom']));
            $mailData->_to           = Zend_Json::decode(decode_html($r['mto']));
            $mailData->_cc           = Zend_Json::decode(decode_html($r['mcc']));
            $mailData->_bcc          = Zend_Json::decode(decode_html($r['mbcc']));
            $mailData->_date         = decode_html($r['mdate']);
            $mailData->_subject      = str_replace("_", " ", decode_html($r['msubject']));
            $mailData->_body         = decode_html($r['mbody']);
            $mailData->_charset      = decode_html($r['mcharset']);
            $mailData->_isbodyhtml   = intval($r['misbodyhtml'])  ? true : false;
            $mailData->_plainmessage = intval($r['mplainmessage'])? true : false;
            $mailData->_htmlmessage  = intval($r['mhtmlmessage']) ? true : false;
            $mailData->_uniqueid     = decode_html($r['muniqueid']);
            $mailData->_bodyparsed   = intval($r['mbodyparsed'])  ? true : false;
            $mailData->_mailRecordId = $r['muid'];


            return $mailData;
        }

        return new self();
    }

    public function setTo($to) {
        $this->_to = @self::__mime_decode(str_replace("_", " ", $to));
    }
}
?>
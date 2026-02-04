<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

include_once 'vtlib/Vtiger/Net/Client.php';

abstract class Vtiger_PortalBase_Connector extends Vtiger_Connector {

    protected $client;
    protected $auth;

    public function __construct() {
        $this->client = new Vtiger_Net_Client(Portal_Config::get('crm.connect.url'));
    }

    protected function api($params) {
        if (!$this->client) {
            throw new Exception('HTTP client not initialized');
        }

        $this->client->setHeaders($this->auth);

        $response = $this->client->doPost($params);
        $responseText = json_decode($response, true);

        // Guard against invalid / non-JSON / empty responses
        if (!is_array($responseText)) {
            throw new Exception('Invalid response from CRM');
        }

        $success = $responseText['success'] ?? false;

        if ($success && array_key_exists('result', $responseText)) {
            return $responseText['result'];
        }

        // Normalize error structure
        if (isset($responseText['error'])) {
            return $responseText['error'];
        }

        // Fallback generic error
        return [
            'code'    => 'UNKNOWN_ERROR',
            'message' => 'Unknown error from CRM'
        ];
    }
}

class Vtiger_Portal_Connector extends Vtiger_PortalBase_Connector {

    public function isAuthenticated() {
        $this->auth = Portal_Session::get('portal_auth', null);
        return $this->auth !== null;
    }

    public function ping($username, $password) {
        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $args = [
            '_operation' => 'Ping',
            'username'   => $username,
            'password'   => $password
        ];

        return self::api($args);
    }

    public function authentication() {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $authHeader = $this->auth['Authorization'] ?? '';
        if (stripos($authHeader, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($authHeader, strlen('Basic ')));
        if ($decoded === false) {
            return null;
        }

        // username:password
        $parts = explode(':', $decoded, 2);
        $username = $parts[0] ?? null;
        $password = $parts[1] ?? null;

        return [
            'username' => $username,
            'password' => $password
        ];
    }

    public function fetchModules() {
        $language = Portal_Session::get('language');
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $args = [
            '_operation' => 'FetchModules',
            'language'   => $language,
            'username'   => $username,
            'password'   => $password
        ];

        $response = self::api($args);

        if (
            isset($response['contact_id'], $response['account_id'], $response['user_id']) &&
            $username && $password
        ) {
            Portal_Session::set('portal_auth', $this->auth);
            Portal_Session::set('contact_id', $response['contact_id']['value'] ?? null);
            Portal_Session::set('parent_id', $response['account_id']['value'] ?? null);
            Portal_Session::set('parent_idLabel', $response['account_id']['label'] ?? null);
            Portal_Session::set('assigned_user_id', $response['user_id']['value'] ?? null);
        } else {
            return null;
        }

        return $response;
    }

    public function describeModule($module, $language) {
        $language = Portal_Session::get('language');
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation' => 'DescribeModule',
            'module'     => $module,
            'language'   => $language,
            'username'   => $username,
            'password'   => $password
        ];

        return self::api($params);
    }

    public function fetchRecords($module, $label, $q = false, $filter = false, $pageNo = false, $pageLimit = false, $orderBy = false, $order = false) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation'  => 'FetchRecords',
            'module'      => $module,
            'moduleLabel' => $label,
            'page'        => $pageNo,
            'pageLimit'   => $pageLimit,
            'fields'      => $filter,
            'orderBy'     => $orderBy,
            'order'       => $order,
            'username'    => $username,
            'password'    => $password
        ];

        if ($q) {
            $params = array_merge($params, $q);
        }

        $response = self::api($params);
        return $this->parseListViewRecords($response, $module);
    }

    public function fetchRelatedRecords($relatedModule, $relatedModuleLabel, $id, $parentId, $pageNo, $pageLimit, $module = false) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation'       => 'FetchRelatedRecords',
            'relatedModule'    => $relatedModule,
            'relatedModuleLabel' => $relatedModuleLabel,
            'recordId'         => $id,
            'parentId'         => $parentId,
            'page'             => $pageNo,
            'pageLimit'        => $pageLimit,
            'module'           => $module,
            'username'         => $username,
            'password'         => $password
        ];

        return self::api($params);
    }

    public function fetchRecord($id, $module, $parentId = '') {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation' => 'FetchRecord',
            'module'     => $module,
            'recordId'   => $id,
            'parentId'   => $parentId,
            'username'   => $username,
            'password'   => $password
        ];

        return self::api($params);
    }

    public function fetchHistory($module, $id, $pageNo, $pageLimit, $parentId = false) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation' => 'FetchHistory',
            'record'     => $id,
            'module'     => $module,
            'page'       => $pageNo,
            'pageLimit'  => $pageLimit,
            'parentId'   => $parentId,
            'username'   => $username,
            'password'   => $password
        ];

        return self::api($params);
    }

    public function saveRecord($module, $values, $recordId = false) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation' => 'SaveRecord',
            'module'     => $module,
            'values'     => $values,
            'username'   => $username,
            'password'   => $password
        ];

        if ($recordId) {
            $params['recordId'] = $recordId;
        }

        $response = self::api($params);

        // If contact email changed, update auth
        if (is_array($response) && $module === 'Contacts' && !empty($response['email'])) {
            $authHeader = $this->auth['Authorization'] ?? '';
            $decoded = base64_decode(substr($authHeader, strlen('Basic ')));
            $parts = explode(':', $decoded, 2);
            $oldPassword = $parts[1] ?? '';

            $this->auth = [
                'Authorization' => 'Basic ' . base64_encode($response['email'] . ':' . $oldPassword)
            ];

            Portal_Session::set('portal_auth', $this->auth);
        }

        return $response;
    }

    public function addComment($values, $parentId) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation' => 'AddComment',
            'values'     => $values,
            'parentId'   => $parentId,
            'username'   => $username,
            'password'   => $password
        ];

        return self::api($params);
    }

    public function downloadFile($module, $q, $parentId = false, $parentModule = false, $attachmentId = false) {
        $username = Portal_Session::get('username');
        $password = Portal_Session::get('password');

        $this->auth = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
        ];

        $params = [
            '_operation'  => 'DownloadFile',
            'module'      => $module,
            'moduleLabel' => $module,
            'recordId'    => $q,
            'parentId'    => $parentId,
            'parentModule'=> $parentModule,
            'attachmentId'=> $attachmentId,
            'username'    => $username,
            'password'    => $password
        ];

        return self::api($params);
    }

public function changePassword($record) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation'  => 'ChangePassword',
        'username'    => $username,
        'password'    => $record['oldPassword'] ?? '',
        'newPassword' => $record['newPassword'] ?? ''
    ];

    return self::api($params);
}
	public function fetchProfile() {
		$username = Portal_Session::get('username');
		$password = Portal_Session::get('password');
		$this->auth = array('Authorization' => 'Basic '.base64_encode($username.':'.$password));

		$params = array(
			'_operation' => 'FetchProfile',
			'username' => $username,
			'password' => $password
		);
		return self::api($params);
	}

public function uploadAttachment($module, $parentId = '') {

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        return [
            'success' => false,
            'message' => 'No file uploaded'
        ];
    }

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $url = Portal_Config::get('crm.connect.url');

    $file = new CURLFile(
        $_FILES['file']['tmp_name'],
        $_FILES['file']['type'],
        $_FILES['file']['name']
    );

    $data = [
        '_operation' => 'SaveRecord',
        'module'     => $module,
        'parentId'   => $parentId,
        'filename'   => $_FILES['file']['name'],
        'file'       => $file,
        'username'   => $username,
        'password'   => $password
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseText = json_decode($response, true);

    if (!is_array($responseText)) {
        return [
            'success' => false,
            'message' => 'Invalid response from CRM'
        ];
    }

    if (!empty($responseText['success']) && isset($responseText['result'])) {
        return $responseText['result'];
    }

    return $responseText['error']['message'] ?? 'Unknown error';
}

public function forgotPassword($email) {

    // Basic Auth with empty password is allowed for ForgotPassword
    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($email . ':')
    ];

    $params = [
        '_operation' => 'ForgotPassword',
        'email'      => $email
    ];

    return self::api($params);
}

public function fetchRelatedModules($module) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchRelatedModules',
        'module'     => $module,
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchAnnouncement() {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchAnnouncement',
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchShortcuts() {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchShortcuts',
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchRecentRecords($language = null) {

    $language = Portal_Session::get('language');
    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchRecentRecords',
        'language'   => $language,
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchReferenceRecords($module, $query) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchReferenceRecords',
        'module'     => $module,
        'searchKey'  => $query,
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchCompanyDetails() {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchCompanyDetails',
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function fetchCompanyTitle() {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'FetchCompanyTitle',
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function exportRecords($module, $label, $q = false, $filter = false) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation'  => 'ExportRecords',
        'module'      => $module,
        'moduleLabel' => $label,
        'fields'      => $filter,
        'username'    => $username,
        'password'    => $password
    ];

    if ($q) {
        $params = array_merge($params, $q);
    }

    return self::api($params);
}

public function searchFaqs($module, $searchKey) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'SearchFaqs',
        'module'     => $module,
        'searchKey'  => $searchKey,
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

public function searchRecords($searchKey) {

    $username = Portal_Session::get('username');
    $password = Portal_Session::get('password');

    $this->auth = [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ];

    $params = [
        '_operation' => 'SearchRecords',
        'searchKey'  => $searchKey,
        'username'   => $username,
        'password'   => $password
    ];

    return self::api($params);
}

protected function parseListViewRecords($response, $module) {

    // If count is missing or null, return raw response
    if (!isset($response['count']) || $response['count'] === null) {
        return $response;
    }

    $headers = [];
    $records = [];
    $edit    = [];

    // Remove count so we can iterate cleanly
    $count = (int)$response['count'];
    unset($response['count']);

    foreach ($response as $index => $row) {

        if (!is_array($row)) {
            continue;
        }

        $record = [];

        foreach ($row as $field => $value) {

            // Build headers only once
            if ($index === 0) {
                $headers[] = $field;
                $edit[$field] = $field;
            }

            // Normalize reference values
            if (is_array($value)) {
                $record[$field] = $value['label'] ?? '';
            } else {
                $record[$field] = $value;
            }

        }

        $records[] = $record;
    }

    return [
        'headers' => $headers,
        'records' => $records,
        'edit'    => $edit,
        'count'   => $count
    ];
}

	public function updateLoginDetails($status) {

	    $username = Portal_Session::get('username');
	    $password = Portal_Session::get('password');

	    $this->auth = [
	        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
	    ];

	    $args = [
	        '_operation' => 'UpdateLoginDetails',
	        'status'     => $status,
	        'username'   => $username,
	        'password'   => $password
	    ];

	    return self::api($args);
	}
}

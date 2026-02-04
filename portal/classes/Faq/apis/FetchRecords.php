<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

class Faq_FetchRecords_API extends Portal_Default_API {

    public function process(Portal_Request $request) {

        $module   = $request->getModule();
        $language = $request->getLanguage();

        // Always treat q as an array
        $params = $request->get('q', []);
        if (!is_array($params)) {
            $params = [];
        }

        // Safe extraction with defaults
        $pageNo     = $params['page']      ?? 0;
        $pageLimit  = $params['pageLimit'] ?? 10;
        $field      = $params['field']     ?? null;
        $value      = $params['value']     ?? null;
        $fields     = $params['fields']    ?? null;

        // FAQ-specific filter logic
        if ($field === 'faqcategories' && $value === '') {
            $value = 'NULL';
        }

        // Build filter only if both field and value exist
        $filter = [];
        if ($field !== null && $value !== null) {
            $filter[$field] = $value;
        }

        // If filter exists, encode it as fields
        if (!empty($filter)) {
            $fields = json_encode($filter);
        }

        // Ensure fields is always a string or null
        if ($fields !== null && !is_string($fields)) {
            $fields = json_encode($fields);
        }

        // Fetch records safely
        $result = Vtiger_Connector::getInstance()->fetchRecords(
            $module,
            $request->get('label'),
            $params,
            $fields,
            $pageNo,
            $pageLimit
        );

        $response = new Portal_Response();
        $response->setResult(
            $this->processRecordsResponse($result, $module, $language)
        );

        return $response;
    }

    public function processRecordsResponse($result, $module, $language) {

        $headers = $result['headers'] ?? [];
        $records = $result['records'] ?? [];
        $edits   = $result['edit']    ?? [];

        unset($result['edit']);

        // Metadata for field labels/types
        $recordMeta = parent::processResponse($module, $language);

        $headerNames = [];
        $editNames   = [];

        // Build header labels safely
        foreach ($headers as $key) {
            $headerNames[] = $recordMeta[$key]['label'] ?? $key;
        }

        // Build editable field labels safely
        foreach ($edits as $key) {
            if (isset($recordMeta[$key]['label'])) {
                $editNames[$key] = $recordMeta[$key]['label'];
            }
        }

        // FAQ categories (picklist)
        if (isset($recordMeta['faqcategories'])) {
            $result['faqCategories'] = $recordMeta['faqcategories']['picklistValues'];
        }

        // Final output
        $result['headers']   = $headerNames;
        $result['records']   = $records;
        $result['edits']     = $editNames;
        $result['pageLimit'] = 10;

        // 🔥 FIX: Correct the count so the portal displays records
        $result['count'] = is_array($records) ? count($records) : 0;

        return $result;
    }
}

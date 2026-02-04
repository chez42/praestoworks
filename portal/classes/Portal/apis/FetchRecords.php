<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

class Portal_FetchRecords_API extends Portal_Default_API {

    public function process(Portal_Request $request) {

        $module   = $request->getModule();
        $language = Portal_Session::get('language');

        // Always treat q as an array
        $params = $request->get('q', []);
        if (!is_array($params)) {
            $params = [];
        }

        // Safe extraction with defaults
        $pageNo     = $params['page']      ?? 0;
        $pageLimit  = $params['pageLimit'] ?? 10;
        $order      = $params['order']     ?? null;
        $orderBy    = $params['orderBy']   ?? null;
        $fields     = $params['fields']    ?? null;

        // Handle filter → fields mapping
        $filter = $request->get('filter');
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


    public function processRecordsResponse($result, $module, $language, $isExport = false) {

        // If vtiger returned null, just return the raw result
        if (!isset($result['records']) || $result['records'] === null) {
            return $result;
        }

        $headers = $result['headers'] ?? [];
        $records = $result['records'] ?? [];
        $edits   = $result['edit']    ?? [];

        unset($result['edit']);

        // Metadata for field labels/types
        $recordMeta = parent::processResponse($module, $language);

        $headerNames    = [];
        $editFieldNames = [];

        // Build header labels safely
        foreach ($headers as $key) {
            if (isset($recordMeta[$key]['label'])) {
                $headerNames[] = $recordMeta[$key]['label'];
            } else {
                $headerNames[] = $key;
            }
        }

        // Build editable field labels safely
        foreach ($edits as $key) {
            if (isset($recordMeta[$key]['label'])) {
                $editFieldNames[$recordMeta[$key]['label']] = $key;
            }
        }

        // Process each record
        foreach ($records as $rowIndex => $row) {

            $docExists = false; // Always initialize

            foreach ($row as $fieldName => $fieldValue) {

                // Skip unknown metadata safely
                $meta  = $recordMeta[$fieldName] ?? null;
                $type  = $meta['type']  ?? null;
                $label = $meta['label'] ?? $fieldName;

                // Type-based transformations
                if ($type === 'picklist' && isset($meta['picklistValues'])) {
                    foreach ($meta['picklistValues'] as $opt) {
                        if ($fieldValue == $opt['value']) {
                            $fieldValue = $opt['label'];
                            break;
                        }
                    }
                }

                if ($type === 'multipicklist') {
                    $fieldValue = str_replace(' |##| ', ',', $fieldValue);
                }

                if ($type === 'double' || $type === 'currency') {
                    $fieldValue = round((float)$fieldValue, 2);
                }

                if ($type === 'boolean') {
                    $fieldValue = ((int)$fieldValue === 1) ? "Yes" : "No";
                }

                if ($type === 'integer' && $module === 'Documents' && $fieldName === 'filesize') {
                    $fieldValue = round(((float)$fieldValue / 1024), 2) . 'KB';
                }

                if ($type === 'string' && $module === 'Documents' && $fieldName === 'filelocationtype') {
                    if ($fieldValue === "I") $fieldValue = "Internal";
                    if ($fieldValue === "E") $fieldValue = "External";
                }

                if ($type === 'text') {
                    $fieldValue = strip_tags($fieldValue);
                    $fieldValue = preg_replace('/<br(\s+)?\/?>/i', "\n", $fieldValue);
                }

                if ($type === 'file' && $module === 'Documents' && $fieldName === 'filename') {
                    $docExists = !empty($fieldValue);
                }

                // Always sanitize
                if ($fieldName !== 'id') {
                    $fieldValue = strip_tags((string)$fieldValue);
                }

                // Assign transformed value under label
                $row[$label] = $fieldValue;

                // Remove original field key
                if ($fieldName !== 'id') {
                    unset($row[$fieldName]);
                }

                if ($isExport) {
                    unset($row['id']);
                }
            }

            // Add documentExists flag
            if ($module === 'Documents') {
                $row['documentExists'] = $docExists;
            }

            $records[$rowIndex] = $row;
        }

        // Final output
        $result['headers']     = $headerNames;
        $result['records']     = $records;
        $result['editLabels']  = $editFieldNames;
        $result['pageLimit']   = 10;

        return $result;
    }


    public function convertElapsedTime($value, $currentDate) {

        if ($value === '0000-00-00 00:00:00') {
            return '';
        }

        $minutes = (strtotime($currentDate) - strtotime($value)) / 60;
        if (!is_numeric($minutes)) {
            return '';
        }

        $seconds = $minutes * 60;

        $s  = (floor($seconds % 60) > 0) ? (floor($seconds % 60) . ' seconds ') : '';
        $m  = (floor(($seconds % 3600) / 60) > 0) ? (floor(($seconds % 3600) / 60) . ' minutes ') : '';
        $h  = (floor(($seconds % 86400) / 3600) > 0) ? (floor(($seconds % 86400) / 3600) . ' hours ') : '';
        $d  = (floor(($seconds % 2592000) / 86400) > 0) ? (floor(($seconds % 2592000) / 86400) . ' days ') : '';
        $Mo = (floor($seconds / 2592000) > 0) ? (floor($seconds / 2592000) . ' months ') : '';

        return trim("$Mo $d $h $m $s");
    }
}

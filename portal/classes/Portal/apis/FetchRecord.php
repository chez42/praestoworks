<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

class Portal_FetchRecord_API extends Portal_Default_API {

    public function process(Portal_Request $request) {

        $module   = $request->getModule();
        $language = $request->getLanguage();

        $parentId = $request->get('parentId') ?? '';
        $recordId = $request->get('id');

        $result = Vtiger_Connector::getInstance()->fetchRecord(
            $recordId,
            $module,
            $parentId
        );

        $response = new Portal_Response();
        $response->setResult(
            $this->processRecordResponse($result, $module, $language)
        );

        return $response;
    }


    public function processRecordResponse($result, $module, $language) {

        $recordMeta = parent::processResponse($module, $language);
        $editFieldNames = [];

        if (!empty($result) && isset($result['record'])) {

            $record = $result['record'];
            $result['record']['identifierName'] = $recordMeta['labelField'] ?? '';

            $docExists = false;

            foreach ($record as $key => $value) {

                // Safe metadata lookup
                $meta  = $recordMeta[$key] ?? null;
                $type  = $meta['type']  ?? null;
                $label = $meta['label'] ?? $key;

                // Track editable fields
                if ($meta && isset($meta['label'])) {
                    $editFieldNames[$key] = $meta['label'];
                }

                // Type-based transformations
                if ($type === 'double' || $type === 'currency') {
                    $value = round((float)$value, 2);
                }

                if ($type === 'picklist' || $type === 'metricpicklist') {
                    if (isset($meta['picklistValues'])) {
                        foreach ($meta['picklistValues'] as $opt) {

                            // HelpDesk special logic
                            if ($module === 'HelpDesk' && $key === 'ticketstatus') {
                                $result['HelpDesk']['isStatusEditable'] = $meta['editable'] ?? false;
                                if ($opt['value'] === 'Closed') {
                                    $result['HelpDesk']['closeLabel'] = $opt['label'];
                                }
                            }

                            // Quotes special logic
                            if ($module === 'Quotes' && $key === 'quotestage') {
                                if ($opt['value'] === 'Accepted') {
                                    $result['Quotes']['acceptLabel'] = $opt['label'];
                                }
                            }

                            // Match picklist value
                            if ($record[$key] == $opt['value']) {

                                if ($module === 'HelpDesk' && $key === 'ticketstatus') {
                                    $result['HelpDesk']['status'] =
                                        ($value === 'Closed') ? 'Closed' : 'Open';
                                }

                                if ($module === 'Quotes' && $key === 'quotestage') {
                                    $result['Quotes']['stage'] =
                                        ($value === 'Accepted') ? 'Accepted' : 'Created';
                                }

                                $value = $opt['label'];
                            }
                        }
                    }
                }

                if ($type === 'multipicklist') {
                    $value = str_replace(' |##| ', ',', $value);
                }

                if ($type === 'text') {
                    $value = strip_tags($value);
                    $value = preg_replace('/<br(\s+)?\/?>/i', "\n", $value);
                }

                if ($type === 'boolean') {
                    $value = ((int)$value === 1) ? "Yes" : "No";
                }

                if ($type === 'integer' && $module === 'Documents' && $key === 'filesize') {
                    $value = round(((float)$value / 1024), 2) . 'KB';
                }

                if ($type === 'string' && $module === 'Documents' && $key === 'filelocationtype') {
                    if ($value === "I") $value = "Internal";
                    if ($value === "E") $value = "External";
                }

                if ($type === 'file' && $module === 'Documents' && $key === 'filename') {
                    $docExists = !empty($value);
                }

                if ($type === 'reference') {
                    $result[$module]['referenceFields'][$label] = $value;
                }

                // Sanitize strings
                if ($type === 'string') {
                    $value = strip_tags((string)$value);
                }

                // Replace field key with label
                if ($key !== 'id') {
                    $result['record'][$label] = $value;
                    unset($result['record'][$key]);
                }

                // Handle reference arrays
                if (is_array($value) && isset($value['label'])) {
                    $result['record'][$label] = $value['label'];
                    unset($result['record'][$key]);
                }

                // Add documentExists flag
                if ($docExists && $module === 'Documents') {
                    $result['record']['documentExists'] = true;
                }
            }

            $result['editLabels'] = $editFieldNames;
        }

        return $result;
    }
}

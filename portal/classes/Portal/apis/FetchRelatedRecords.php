<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

class Portal_FetchRelatedRecords_API extends Portal_Default_API {

    public function process(Portal_Request $request) {

        $pageNo    = $request->get('page') ?? 0;
        $pageLimit = $request->get('pageLimit') ?? 10;
        $module    = $request->get('module');
        $language  = Portal_Session::get('language');

        $relatedModule       = $request->get('relatedModule');
        $relatedModuleLabel  = $request->get('relatedModuleLabel');
        $recordId            = $request->get('id');
        $parentId            = $request->get('parentId');

        $result = Vtiger_Connector::getInstance()->fetchRelatedRecords(
            $relatedModule,
            $relatedModuleLabel,
            $recordId,
            $parentId,
            $pageNo,
            $pageLimit
        );

        $response = new Portal_Response();
        $response->setResult(
            $this->processRelatedRecordsResponse($result, $relatedModule, $language)
        );

        return $response;
    }


    public function processRelatedRecordsResponse($result, $relatedModule, $language) {

        if (!$result) {
            $result = [];
        }

        // Handle ModComments separately
        if ($relatedModule === 'ModComments') {
            return [
                'comments' => $result,
                'more'     => $result['more'] ?? false
            ];
        }

        $recordMeta = parent::processResponse($relatedModule, $language);

        $headers        = [];
        $records        = [];
        $dateTimeFields = [];

        // Safe "more" flag
        $more = $result['more'] ?? false;
        unset($result['more']);

        foreach ($result as $row) {

            if (!$row) {
                continue;
            }

            $record    = [];
            $docExists = false;

            foreach ($row as $field => $value) {

                // Safe metadata lookup
                $meta  = $recordMeta[$field] ?? null;
                $type  = $meta['type']  ?? null;
                $label = $meta['label'] ?? $field;

                // Build headers only once
                if (empty($headers)) {
                    $headers[] = $label;
                }

                // Reference fields
                if (is_array($value)) {
                    $record[$label] = $value['label'] ?? '';
                    continue;
                }

                // Type-based transformations
                if ($type === 'double' || $type === 'currency') {
                    $value = round((float)$value, 2);
                }

                if ($type === 'multipicklist') {
                    $value = str_replace(' |##| ', ',', $value);
                }

                if ($type === 'boolean') {
                    $value = ((int)$value === 1) ? "Yes" : "No";
                }

                if ($type === 'text') {
                    $value = strip_tags($value);
                    $value = preg_replace('/<br(\s+)?\/?>/i', "\n", $value);
                }

                if ($relatedModule === 'Documents' && $field === 'filelocationtype') {
                    if ($value === "I") $value = "Internal";
                    if ($value === "E") $value = "External";
                }

                if ($type === 'datetime') {
                    $dateTimeFields[] = $label;
                }

                if ($relatedModule === 'Documents' && $type === 'file' && $field === 'filename') {
                    $docExists = !empty($value);
                }

                if ($relatedModule === 'Documents' && $field === 'filesize' && $type === 'integer') {
                    $value = round(((float)$value / 1024), 2) . 'KB';
                }

                // Assign transformed value
                $record[$label] = $value;

                // Preserve filename for Documents
                if ($relatedModule === 'Documents' && $field === 'filename') {
                    $record['filename'] = $value;
                }
            }

            if ($docExists) {
                $record['documentExists'] = true;
            }

            $records[] = $record;
        }

        $dateTimeFields = array_unique($dateTimeFields);

        return [
            'headers'        => $headers,
            'records'        => $records,
            'dateTimeFields' => $dateTimeFields,
            'more'           => $more
        ];
    }
}

<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.2
 * ("License.txt"); You may not use this file except in compliance with the License
 * The Original Code is: Vtiger CRM Open Source
 * The Initial Developer of the Original Code is Vtiger.
 * Portions created by Vtiger are Copyright (C) Vtiger.
 * All Rights Reserved.
 * ***********************************************************************************/

class Portal_FetchHistory_API extends Portal_Default_API {

    public function process(Portal_Request $request) {

        $module    = $request->getModule();
        $pageNo    = $request->get('page') ?? 0;
        $language  = $request->getLanguage();
        $pageLimit = $request->get('pageLimit') ?? 10;

        $parentId  = $request->get('parentId') ?? '';
        $recordId  = $request->get('id');

        $result = Vtiger_Connector::getInstance()->fetchHistory(
            $module,
            $recordId,
            $pageNo,
            $pageLimit,
            $parentId
        );

        $response = new Portal_Response();
        $response->setResult(
            $this->processHistoryResponse($result, $module, $language)
        );

        return $response;
    }


    public function processHistoryResponse($result, $module, $language) {

        $recordMeta = parent::processResponse($module, $language);

        // Always initialize history
        $history = [
            'records' => []
        ];

        if (empty($result['history']) || !is_array($result['history'])) {
            return $history;
        }

        foreach ($result['history'] as $entry) {

            $status      = $entry['status'] ?? '';
            $valuesArray = $entry['values'] ?? [];
            $modified    = $entry['modifiedtime'] ?? '';

            if (empty($valuesArray)) {
                continue;
            }

            $new = [
                'modifiedtime' => $modified
            ];

            $createCount = 0;
            $updateCount = 0;

            foreach ($valuesArray as $fieldname => $values) {

                // Safe metadata lookup
                $meta  = $recordMeta[$fieldname] ?? null;
                $type  = $meta['type']  ?? null;
                $label = $meta['label'] ?? $fieldname;

                // Normalize values
                $prev = $values['previous'] ?? '';
                $curr = $values['current'] ?? '';

                // Type-based transformations

                if ($type === 'picklist' && !empty($meta['picklistValues'])) {
                    foreach ($meta['picklistValues'] as $opt) {
                        if ($prev !== '' && $prev == $opt['value']) {
                            $prev = $opt['label'];
                        }
                        if ($curr !== '' && $curr == $opt['value']) {
                            $curr = $opt['label'];
                        }
                    }
                }

                if ($type === 'multipicklist') {
                    if ($prev !== '') {
                        $prev = str_replace(' |##| ', ',', $prev);
                        if ($prev == 0) $prev = '';
                    }
                    if ($curr !== '') {
                        $curr = str_replace(' |##| ', ',', $curr);
                    }
                }

                if ($type === 'text' || $type === 'string') {
                    if ($prev !== '' && $prev == 0) $prev = '';
                }

                if ($type === 'date' && $curr === '') {
                    $prev = '';
                }

                if ($type === 'url' && $prev !== '' && $prev == 0) {
                    $prev = '';
                }

                if ($type === 'time' && $curr === '') {
                    $prev = '';
                }

                if ($type === 'phone' && $curr === '') {
                    $prev = '';
                }

                if ($type === 'email' && $prev !== '' && $prev == 0) {
                    $prev = '';
                }

                if ($type === 'double' || $type === 'currency') {
                    if ($prev !== '' && is_numeric($prev)) {
                        $prev = round((float)$prev, 2);
                    }
                    if ($curr !== '' && is_numeric($curr)) {
                        $curr = round((float)$curr, 2);
                    }
                }

                if ($type === 'boolean') {
                    if ($prev !== '') {
                        $prev = ((int)$prev === 0) ? 'No' : 'Yes';
                    }
                    if ($curr !== '') {
                        $curr = ((int)$curr === 1) ? 'Yes' : 'No';
                    }
                }

                if ($type === 'reference') {
                    if ($prev !== '' && $prev == 0) {
                        $prev = '';
                    }
                }

                if ($type === 'text') {
                    $prev = str_replace("\n", '', $prev);
                    $curr = str_replace("\n", '', $curr);
                    $prev = preg_replace('/<br(\s+)?\/?>/i', "\n", $prev);
                    $curr = preg_replace('/<br(\s+)?\/?>/i', "\n", $curr);
                    $prev = strip_tags($prev);
                    $curr = strip_tags($curr);
                }

                // Build history entry
                if ($status === 'updated') {
                    $updateCount = count($valuesArray);
                    $new[$label]['updateStatus'] = 'updated';
                    $new[$label]['previous'] = html_entity_decode($prev, ENT_QUOTES, 'utf-8');
                    $new[$label]['current']  = html_entity_decode($curr, ENT_QUOTES, 'utf-8');
                }

                if ($status === 'created') {
                    $createCount = count($valuesArray);
                    $new['id']['updateStatus'] = 'created';
                    $new['created']['user'] = $entry['modifieduser']['label'] ?? '';
                    $history['records'][] = $new;
                    continue 2; // skip remaining fields for created
                }
            }

            $new['count'] = $createCount + $updateCount;
            $history['records'][] = $new;
            $history['records']['count'] = $new['count'];
        }

        return $history;
    }
}

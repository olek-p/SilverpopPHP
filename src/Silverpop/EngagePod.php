<?php

namespace Silverpop;

use LSS\Array2XML;
use LSS\XML2Array;

class EngagePod {

    /**
     * Current version of the library
     *
     * Uses semantic versioning (http://semver.org/)
     *
     * @const string VERSION
     */
    const VERSION = '0.0.2';

    private $_baseUrl;
    private $_session_encoding;
    private $_jsessionid;
    private $_username;
    private $_password;

    /**
     * Constructor
     *
     * Sets $this->_baseUrl based on the engage server specified in config
     */
    public function __construct($config) {
        // It would be a good thing to cache the jsessionid somewhere and reuse it across multiple requests
        // otherwise we are authenticating to the server once for every request
        $this->_baseUrl = 'http://api' . $config['engage_server'] . '.silverpop.com/XMLAPI';
        $this->_login($config['username'], $config['password']);
    }

    /**
     * Terminate the session with Silverpop.
     *
     * @return bool
     */
    public function logOut() {
        $data = $this->_prepareBody('Logout');
        $response = $this->_request($data);
        $result = $response['Envelope']['Body']['RESULT'];
        return $this->_isSuccess($result);
    }

    /**
     * Fetches the contents of a list
     *
     * $listType can be one of:
     *
     * 0 - Databases
     * 1 - Queries
     * 2 - Both Databases and Queries
     * 5 - Test Lists
     * 6 - Seed Lists
     * 13 - Suppression Lists
     * 15 - Relational Tables
     * 18 - Contact Lists
     *
     */
    public function getLists($listType = 2, $isPrivate = true, $folder = null) {
        $data = $this->_prepareBody('GetLists', array(
            'VISIBILITY' => ($isPrivate ? '0' : '1'),
            'FOLDER_ID' => $folder,
            'LIST_TYPE' => $listType,
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('LIST'));

        return $result['LIST'];
    }

    /**
     * Get mailing templates
     *
     */
    public function getMailingTemplates($isPrivate = true) {
        $data = $this->_prepareBody('GetMailingTemplates', array(
            'VISIBILITY' => ($isPrivate ? '0' : '1'),
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('MAILING_TEMPLATE'));

        return $result['MAILING_TEMPLATE'];
    }

    /**
     * Calculate a query
     *
     */
    public function calculateQuery($databaseID) {
        $data = $this->_prepareBody('CalculateQuery', array(
            'QUERY_ID' => $databaseID,
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    /**
     * Get scheduled mailings
     *
     */
    public function getScheduledMailings() {
        $data = $this->_prepareBody('GetSentMailingsForOrg', array(
            'SCHEDULED' => null,
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response);

        return $result;
    }

    /**
     * Get the meta information for a list
     *
     */
    public function getListMetaData($databaseID) {
        $data = $this->_prepareBody('GetListMetaData', array(
            'LIST_ID' => $databaseID,
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response);

        return $result;
    }

    /**
     * Remove a contact
     *
     */
    public function removeContact($databaseID, $email, $customerId) {
        $data = $this->_prepareBody('RemoveRecipient', array(
            'LIST_ID' => $databaseID,
            'EMAIL' => $email,
            'COLUMN' => array(array('NAME'=>'customerId', 'VALUE'=>$customerId)),
        ));
        $response = $this->_request($data);
        $result = $response['Envelope']['Body']['RESULT'];

        if (!$this->_isSuccess($result)) {
            $fault = $response['Envelope']['Body']['Fault']['FaultString'];
            if ($fault != 'Error removing recipient from list. Recipient is not a member of this list.') {
                throw new \Exception("Silverpop says: $fault");
            }
        }

        return true;
    }

    /**
     * Add a contact to a list
     *
     */
    public function addContact($databaseID, $updateIfFound, $columns, $contactListID = false, $sendAutoReply = false, $allowHTML = false) {
        $data = $this->_prepareBody('AddRecipient', array(
            'LIST_ID' => $databaseID,
            'CREATED_FROM' => 1,         // 1 = created manually, 2 = opted in
            'SEND_AUTOREPLY'  => ($sendAutoReply ? 'true' : 'false'),
            'UPDATE_IF_FOUND' => ($updateIfFound ? 'true' : 'false'),
            'ALLOW_HTML' => ($allowHTML ? 'true' : 'false'),
            'CONTACT_LISTS' => ($contactListID) ? array('CONTACT_LIST_ID' => $contactListID) : '',
            'COLUMN' => array(),
        ));
        foreach ($columns as $name => $value) {
            $data['Body']['AddRecipient']['COLUMN'][] = array('NAME' => $name, 'VALUE' => $value);
        }

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RecipientId'));

        return $result['RecipientId'];
    }

    public function getContact($databaseID, $email) {
        $data = $this->_prepareBody('SelectRecipientData', array(
            'LIST_ID' => $databaseID,
            'EMAIL'   => $email,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RecipientId'));

        return $result;
    }

    /**
     * Double opt in a contact
     *
     * @param  string $databaseID
     * @param  string $email
     *
     * @return int recipient ID
     */
    public function doubleOptInContact($databaseID, $email) {
        $data = $this->_prepareBody('DoubleOptInRecipient', array(
            'LIST_ID'         => $databaseID,
            'COLUMN'          => array(
                array(
                    'NAME'  => 'EMAIL',
                    'VALUE' => $email,
                ),
            ),
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RecipientId'));

        return $result['RecipientId'];
    }

    /**
     * Update a contact.
     *
     * @param int    $databaseID
     * @param string $oldEmail
     * @param array  $columns
     *
     * @return int recipient ID
     */
    public function updateContact($databaseID, $oldEmail, $columns) {
        $data = $this->_prepareBody('UpdateRecipient', array(
            'LIST_ID' => $databaseID,
            'OLD_EMAIL' => $oldEmail,
            'CREATED_FROM' => 1,// 1 = created manually
            'COLUMN' => array(),
        ));
        foreach ($columns as $name => $value) {
            $data['Body']['UpdateRecipient']['COLUMN'][] = array('NAME' => $name, 'VALUE' => $value);
        }

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RecipientId'));

        return $result['RecipientId'];
    }

    /**
     * Opt out a contact
     *
     * @param int    $databaseID
     * @param string $email
     * @param array  $columns
     *
     * @return boolean true on success
     */
    public function optOutContact($databaseID, $email, $columns = array()) {
        $data = $this->_prepareBody('OptOutRecipient', array(
            'LIST_ID' => $databaseID,
            'EMAIL' => $email,
            'COLUMN' => array(),
        ));
        $columns['EMAIL'] = $email;
        foreach ($columns as $name => $value) {
            $data['Body']['OptOutRecipient']['COLUMN'][] = array('NAME' => $name, 'VALUE' => $value);
        }

        $response = $this->_request($data);
        $this->_checkResponse(__FUNCTION__, $response);

        return true;
    }

    /**
     * Create a new query
     *
     * Takes a list of criteria and creates a query from them
     *
     * @param string $queryName The name of the new query
     * @param int    $parentListId List that this query is derived from
     * @param        $parentFolderId
     * @param        $condition
     * @param bool   $isPrivate
     *
     * @return int ListID of the query that was created
     */
    public function createQuery($queryName, $parentListId, $parentFolderId, $condition, $isPrivate = true) {
        $data = $this->_prepareBody('CreateQuery', array(
            'QUERY_NAME' => $queryName,
            'PARENT_LIST_ID' => $parentListId,
            'PARENT_FOLDER_ID' => $parentFolderId,
            'VISIBILITY' => ($isPrivate ? '0' : '1'),
            'CRITERIA' => array(
                'TYPE' => 'editable',
                'EXPRESSION' => $condition,
            ),
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('ListId'));

        return $result['ListId'];
    }

    /**
     * Send email
     *
     * Sends an email to the specified listId ($targetID) using the template
     * $templateID. You can optionally include substitutions that will act on
     * the template to fill in dynamic bits of data.
     *
     * ## Example
     *
     *     $engage->sendEmail(123, 456, 'Example Mailing with unique name', time() + 60, array(
     *         'SUBSTITUTIONS' => array(
     *             array(
     *                 'NAME' => 'FIELD_IN_TEMPLATE',
     *                 'VALUE' => 'Dynamic value to replace in template',
     *             ),
     *         )
     *     ));
     *
     * @param int      $templateID ID of template upon which to base the mailing.
     * @param int      $targetID ID of database, query, or contact list to send the template-based mailing.
     * @param string   $mailingName Name to assign to the generated mailing.
     * @param int      $scheduledTimestamp When the mailing should be scheduled to send. This must be later than the current timestamp.
     * @param array    $optionalElements An array of $key => $value, where $key can be one of SUBJECT, FROM_NAME, FROM_ADDRESS, REPLY_TO, SUBSTITUTIONS
     * @param bool|int $saveToSharedFolder
     * @param array    $suppressionLists
     *
     * @return int $mailingID
     */
    public function sendEmail($templateID, $targetID, $mailingName, $scheduledTimestamp, $optionalElements = array(), $saveToSharedFolder = 0, $suppressionLists = array()) {
        $data = $this->_prepareBody('ScheduleMailing', array(
            'SEND_HTML' => true,
            'SEND_TEXT' => true,
            'TEMPLATE_ID' => $templateID,
            'LIST_ID' => $targetID,
            'MAILING_NAME' => $mailingName,
            'VISIBILITY' => ($saveToSharedFolder ? '1' : '0'),
            'SCHEDULED' => date('m/d/Y h:i:s A',$scheduledTimestamp),
        ));
        foreach ($optionalElements as $key => $value) {
            $data['Body']['ScheduleMailing'][$key] = $value;
        }
        if (is_array($suppressionLists) && count($suppressionLists) > 0) {
            $data['Body']['ScheduleMailing']['SUPPRESSION_LISTS']['SUPPRESSION_LIST_ID'] = $suppressionLists;
        }

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('MAILING_ID'));

        return $result['MAILING_ID'];
    }

    /**
     * Send a single transactional email
     *
     * Sends an email to the specified email address ($emailID) using the mailingId
     * of the autoresponder $mailingID. You can optionally include database keys
     * to match if multikey database is used (not for replacement).
     *
     * ## Example
     *
     *     $engage->sendMailing('someone@somedomain.com', 149482, array('COLUMNS' => array(
     *         'COLUMN' => array(
     *             array(
     *                 'Name' => 'FIELD_IN_TEMPLATE',
     *                 'Value' => 'value to MATCH',
     *             ),
     *         )
     *     )));
     *
     * @param string $emailID ID of users email, must be opted in.
     * @param int    $mailingID ID of template upon which to base the mailing.
     * @param array  $optionalKeys additional keys to match reciepent
     *
     * @return int $mailingID
     */
    public function sendMailing($emailID, $mailingID, $optionalKeys = array()) {
        $data = $this->_prepareBody('SendMailing', array(
            'MailingId' => $mailingID,
            'RecipientEmail' => $emailID,
        ));
        foreach ($optionalKeys as $key => $value) {
            $data['Body']['SendMailing'][$key] = $value;
        }

        $response = $this->_request($data);
        $this->_checkResponse(__FUNCTION__, $response);

        return true;
    }

    /**
     * Import a table
     *
     * Requires a file to import and a mapping file to be in the 'upload' directory of the Engage FTP server
     *
     * Returns the data job id
     *
     */
    public function importTable($fileName, $mapFileName) {
        $data = $this->_prepareBody('ImportTable', array(
            'MAP_FILE' => $mapFileName,
            'SOURCE_FILE' => $fileName,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    /**
     * Purge a table
     *
     * Clear the contents of a table, useful before importing new content
     *
     * Returns the data job id
     *
     */
    public function purgeTable($tableName, $isPrivate = true) {
        $data = $this->_prepareBody('PurgeTable', array(
            'TABLE_NAME' => $tableName,
            'TABLE_VISIBILITY' => ($isPrivate ? '0' : '1'),
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    /**
     * This interface inserts or updates relational data
     *
     * For each Row that is passed in:
     * - If a row is found having the same key as the passed in row, update the record.
     * - If no matching row is found, insert a new row setting the column values to those passed in the request.
     *
     * Only one hundred rows may be passed in a single insertUpdateRelationalTable call!
     */
    public function insertUpdateRelationalTable($tableId, $rows) {
        $processedRows = array();
        $attribs = array();

        foreach ($rows as $row) {
            $columns = array();
            foreach ($row as $name => $value) {
                $columns['COLUMN'][] = $value;
                $attribs[5]['COLUMN'][] = array('name' => $name);
            }
            $processedRows['ROW'][] = $columns;
        }

        $data = $this->_prepareBody('InsertUpdateRelationalTable', array(
            'TABLE_ID' => $tableId,
            'ROWS' => $processedRows,
        ));

        $response = $this->_request($data);
        $this->_checkResponse(__FUNCTION__, $response);

        return true;
    }

    /**
     * This interface deletes records from a relational table.
     */
    public function deleteRelationalTableData($tableId, $rows) {
        $processedRows = array();
        $attribs = array();

        foreach ($rows as $row) {
            $columns = array();
            foreach ($row as $name => $value) {
                $columns['KEY_COLUMN'][] = $value;
                $attribs[5]['KEY_COLUMN'][] = array('name' => $name);
            }
            $processedRows['ROW'][] = $columns;
        }

        $data = $this->_prepareBody('DeleteRelationalTableData', array(
            'TABLE_ID' => $tableId,
            'ROWS' => $processedRows,
        ));

        $response = $this->_request($data);
        $this->_checkResponse(__FUNCTION__, $response);

        return true;
    }

    /**
     * Import a list/database
     *
     * Requires a file to import and a mapping file to be in the 'upload' directory of the Engage FTP server
     *
     * Returns the data job id
     *
     */
    public function importList($fileName, $mapFileName) {
        $data = $this->_prepareBody('ImportList', array(
            'MAP_FILE' => $mapFileName,
            'SOURCE_FILE' => $fileName,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    /**
     * Get a data job status
     *
     * Returns the status or throws an exception
     *
     */
    public function getJobStatus($jobId) {
        $data = $this->_prepareBody('GetJobStatus', array(
            'JOB_ID' => $jobId,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_STATUS'));

        return $result;
    }

    public function exportTable($tableName) {
        $data = $this->_prepareBody('ExportTable', array(
            'TABLE_NAME' => $tableName,
            'TABLE_VISIBILITY' => 1,
            'EXPORT_FORMAT' => 'CSV',
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID', 'FILE_PATH'));

        return array($result['JOB_ID'], $result['FILE_PATH']);
    }

    public function createTable($tableName, array $columns) {
        $data = $this->_prepareBody('CreateTable', array(
            'TABLE_NAME' => $tableName,
            'COLUMNS' => array(
                'COLUMN' => $columns,
            ),
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('TABLE_ID'));

        return $result['TABLE_ID'];
    }

    public function joinTable($tableId, $databaseId) {
        $data = $this->_prepareBody('JoinTable', array(
            'TABLE_ID' => $tableId,
            'TABLE_VISIBILITY' => 'SHARED',
            'LIST_ID' => $databaseId,
            'LIST_VISIBILITY' => 'SHARED',
            'MAP_FIELD' => array(
                array(
                    'TABLE_FIELD' => 'Country',
                    'LIST_FIELD' => 'Country',
                ),
                array(
                    'TABLE_FIELD' => 'Language',
                    'LIST_FIELD' => 'Language',
                ),
            ),
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    public function deleteTable($tablename) {
        $data = $this->_prepareBody('DeleteTable', array(
            'TABLE_NAME' => $tablename,
            'TABLE_VISIBILITY' => 1,
        ));
        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('JOB_ID'));

        return $result['JOB_ID'];
    }

    public function addDbField($dbId, $name, $type, $default = null, $key = null) {
        $fieldData = array(
            'LIST_ID' => $dbId,
            'COLUMN_NAME' => $name,
            'COLUMN_TYPE' => $type,
        );
        if ($default) {
            $fieldData['DEFAULT'] = $default;
        }
        if ($key) {
            $fieldData['KEY_COLUMN'] = $key;
        }

        $data = $this->_prepareBody('AddListColumn', $fieldData);
        $response = $this->_request($data);
        $this->_checkResponse(__FUNCTION__, $response);

        return true;
    }

    /**
     * Gets the list of Rulesets associated with $mailingId
     * 
     * @param int $mailingId 
     * @return array
     */
    public function getMailingRulesets($mailingId) {
        $data = $this->_prepareBody('ListDCRulesetsForMailing', array(
            'MAILING_ID' => $mailingId,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RULESET'));

        return $result['RULESET'];
    }

    /**
     * Gets details of ruleset identified by $rulesetId
     * 
     * @param int $rulesetId
     * @return array
     */
    public function getRulesetDetails($rulesetId) {
        $data = $this->_prepareBody('GetDCRuleset', array(
            'RULESET_ID' => $rulesetId,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RULESET'));

        return $result['RULESET'];
    }

    /**
     * Replaces ruleset contents
     * 
     * @param int $rulesetId
     * @param array $contentAreas
     * @param array $rules
     * @return array
     */
    public function replaceRuleset($rulesetId, array $contentAreas, array $rules) {
        $data = $this->_prepareBody('ReplaceDCRuleset', array(
            'RULESET_ID' => $rulesetId,
            'CONTENT_AREAS' => $contentAreas,
            'RULES' => $rules,
        ));

        $response = $this->_request($data);
        $result = $this->_checkResponse(__FUNCTION__, $response, array('RULESET_ID'));

        return $result['RULESET_ID'];
    }

    /**
     * Private method: authenticate with Silverpop
     *
     */
    private function _login($username, $password) {
        $data = array(
            'Body' => array(
                'Login' => array(
                    'USERNAME' => $username,
                    'PASSWORD' => $password,
                ),
            ),
        );
        $response = $this->_request($data);
        $result = $response['Envelope']['Body']['RESULT'];
        if ($this->_isSuccess($result)) {
            $this->_jsessionid = $result['SESSIONID'];
            $this->_session_encoding = $result['SESSION_ENCODING'];
            $this->_username = $username;
            $this->_password = $password;
        } else {
            throw new \Exception('Login Error: '.$this->_getErrorFromResponse($response));
        }
    }

    /**
     * Private method: generate the full request url
     *
     */
    private function _getFullUrl() {
        return $this->_baseUrl . (isset($this->_session_encoding) ? $this->_session_encoding : '');
    }

    /**
     * Private method: make the request
     *
     */
    private function _request(array $data) {
        $fields = array(
            'jsessionid' => isset($this->_jsessionid) ? $this->_jsessionid : '',
            'xml' => Array2XML::createXML('Envelope', $data)->saveXML(),
        );

        $response = $this->_httpPost($fields);
        if (!$response) {
            throw new \Exception('HTTP request failed');
        }

        $arr = XML2Array::createArray($response);
        if (isset($arr['Envelope']['Body']['RESULT']['SUCCESS'])) {
            return $arr;
        }

        throw new \Exception('HTTP Error: Invalid data from the server');
    }

    /**
     * Private method: post the request to the url
     *
     */
    private function _httpPost($fields) {
        $fields_string = http_build_query($fields);
        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($ch,CURLOPT_URL,$this->_getFullUrl());
        curl_setopt($ch,CURLOPT_POST,count($fields));
        curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array (
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8' ));

        //execute post
        $result = curl_exec($ch);

        //close connection
        curl_close($ch);

        return $result;
    }

    /**
     * Private method: parse an error response from Silverpop
     *
     */
    private function _getErrorFromResponse($response) {
        if (isset($response['Envelope']['Body']['Fault']['FaultString']) && !empty($response['Envelope']['Body']['Fault']['FaultString'])) {
            return $response['Envelope']['Body']['Fault']['FaultString'];
        }
        return 'Unknown Server Error';
    }

    /**
     * Private method: determine whether a request was successful
     */
    private function _isSuccess($result) {
        return isset($result['SUCCESS']) && in_array(strtolower($result['SUCCESS']), array('true', 'success'));
    }

    private function _prepareBody($method, array $args = array()) {
        return array('Body' => array($method => $args));
    }

    private function _checkResponse($method, $response, array $fieldsToCheck = array()) {
        $result = $response['Envelope']['Body']['RESULT'];

        if (!$this->_isSuccess($result)) {
            throw new \Exception($method . ' Error: ' . $this->_getErrorFromResponse($response));
        }

        foreach ($fieldsToCheck as $fieldToCheck) {
            if (!isset($result[$fieldToCheck])) {
                throw new \Exception("Method $method succeeded, but no $fieldToCheck was returned from the server.");
            }
        }

        return $result;
    }
}

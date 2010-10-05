<?php
/**
 * Twilio Datasource behavior
 *
 * Used for reading and writing to Twilio, through models.
 *
 * PHP versions 4 and 5
 *
 * Copyright 2010, Jose Diaz-Gonzalez
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright   Copyright 2010, Jose Diaz-Gonzalez
 * @package     twilio
 * @subpackage  twilio.models.datasources
 * @link        http://github.com/josegonzalez/twilio
 * @license     MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::import('Core', 'HttpSocket');

if (!function_exists('json_decode')) { 
function json_decode($json) {
	// Author: walidator.info 2009 
	$comment    = false;
	$out        = '$x=';

	for ($i = 0; $i<strlen($json); $i++) {
		if (!$comment) {
			if ($json[$i] == '{')	    $out .= ' array(';
			else if ($json[$i] == '}')  $out .= ')';
			else if ($json[$i] == ':')  $out .= '=>';
			else                        $out .= $json[$i];
		} else {
			$out .= $json[$i];
		}
		if ($json[$i] == '"')           $comment = !$comment;
	}
	eval($out . ';');
	return $x;
}

class TwilioSource extends DataSource {

	var $conf = array(
		'sid' => null,
		'token' => null,
		'version' => '2008-08-01',
		'environment' => 'sandbox',
		'sandbox_pin' => null,
		'sandbox_number' => null,
	);

	var $socket = null;
	var $last_request_status = null;
	var $last_response_raw = null;
	var $last_rest_exception = null;

	var $_schema = array(
		'texts' => array(
			'id' => array(
				'type' => 'string',
				'null' => true,
				'key' => 'primary',
				'length' => 36,
			),
			'From' => array(
				'type' => 'integer',
				'null' => true,
				'default' => '',
				'length' => 10,
			),
			'To' => array(
				'type' => 'integer',
				'null' => true,
				'default' => '',
				'length' => 10
			),
			'Body' => array(
				'type' => 'string',
				'null' => true,
				'default' => '',
				'length' => 160
			),
		)
	);

	function __construct($config = array()) {
		$this->conf = array_merge($this->conf, $config);
		$auth = "{$config['sid']}:{$config['token']}";
		$twilioSocketConfig = array(
				'persistent' => '',
				'host' => 'api.twilio.com',
				'protocol' => '6',
				'port' => '443',
				'timeout' => '30',
				'request' => array(
					'uri' => array(
						'scheme' => 'https',
						'host' => 'api.twilio.com',
						'port' => '443'
					),
					'auth' => array(
						'method' => 'Basic',
						'user' => $config['sid'],
						'pass' => $config['token']
					)
				),
		);
		$this->Socket = new TwilioSocket($twilioSocketConfig);
		parent::__construct($this->conf);
	}

	function listSources() {
		return array('texts');
	}

	function read(&$model, $queryData = array()) {
		$response = $this->Socket->get(
			"/{$this->conf['version']}/Accounts/{$this->conf['sid']}/SMS/Messages.json",
			$queryData['conditions']
		);
		$results = array();
		foreach ($response['TwilioResponse']['SMSMessages'] as $record) {
			$record = array('Text' => $record['SMSMessage']);
			$results[] = $record;
		}
		return $results;
	}

	function create(&$model, $fields = array(), $values = array()) {
		$data = array_combine($fields, $values);
		if ($this->conf['environment'] == 'sandbox') {
			$data['Body'] = "{$this->conf['sandbox_pin']} {$data['Body']}";
			$data['From'] = $this->conf['sandbox_number'];
		}
		$response = $this->Socket->post("/{$this->conf['version']}/Accounts/{$this->conf['sid']}/SMS/Messages.json", $data);
		if (isset($response['TwilioResponse']['SMSMessage']['Sid'])) {
			$model->setInsertId($response['TwilioResponse']['SMSMessage']['Sid']);
			$model->id = $response['TwilioResponse']['SMSMessage']['Sid'];
			$model->data[$model->alias][$model->primaryKey] = $response['TwilioResponse']['SMSMessage']['Sid'];
			$this->_resetErrors();
			return true;
		}
		$this->_error();
		$model->onError();
		return false;
	}

/**
 * Begin a transaction
 *
 * @param model $model
 * @return boolean True on success, false on fail
 * (i.e. if the database/model does not support transactions,
 * or a transaction has not started).
 * @access public
 */
	function begin(&$model) {
		$this->_transactionStarted = true;
		return true;
	}

/**
 * Commit a transaction
 *
 * @param model $model
 * @return boolean True on success, false on fail
 * (i.e. if the database/model does not support transactions,
 * or a transaction has not started).
 * @access public
 */
	function commit(&$model) {
		if (!parent::commit($model)) return false;

		$data = (isset($model->data[$model->alias])) ? $model->data[$model->alias] : array();

		foreach ($data as $record) {
			$model->create();
			if (!$model->save(array($model->alias => $record))) {
				$this->_error();
				return false;
			}
		}
		$this->_resetErrors();
		$this->_transactionStarted = false;
		return true;
	}

	public function describe(&$model) {
		return $this->_schema['texts'];
	}

/**
 * Sets the error information for the last TwilioSocket Request
 *
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	function _error() {
		$this->last_request_status = $this->Socket->response['status'];
		$this->last_response_raw = $this->Socket->response['body'];
		$rest_exception = json_decode($this->last_response_raw)->TwilioResponse->RestException;
		foreach ($rest_exception as $variable => $value) {
			$this->last_rest_exception[$variable] = $value;
		}
	}

/**
 * Resets the datasource errors
 * 
 * Called after each successful request.
 *
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	function _resetErrors() {
		$this->last_request_status = null;
		$this->last_response_raw = null;
		$this->last_rest_exception = null;
	}

}

class TwilioSocket extends HttpSocket {
	public $config = array();

/**
 * Override HttpSocket so that the class config
 * state is not overriden
 *
 * @param string $full
 * @return void
 * @author Jose Diaz-Gonzalez
 */
	function reset($full = true) {
		static $initalState = array();
		if (empty($initalState)) {
			$initalState = get_class_vars(__CLASS__);
		}

		if ($full == false) {
			$this->request = $initalState['request'];
			$this->response = $initalState['response'];
		}
		return true;
	}

	function post($uri = null, $data = array(), $request = array()) {
		$request['uri'] = array(
				'path' => $uri,
				'user' => $this->config['request']['auth']['user'],
				'pass' => $this->config['request']['auth']['pass']
		);
		$request['uri'] = array_merge($this->config['request']['uri'], $request['uri']);
		return json_decode(parent::post($uri, $data, $request), true);
	}

	function get($uri = null, $query = array(), $request = array()) {
		$request['uri'] = array(
				'path' => $uri,
				'user' => $this->config['request']['auth']['user'],
				'pass' => $this->config['request']['auth']['pass']
		);
		$request['uri'] = array_merge($this->config['request']['uri'], $request['uri']);
		return json_decode(parent::get($uri, $query, $request), true);
	}

}
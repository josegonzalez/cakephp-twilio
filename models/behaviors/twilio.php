<?php

class TwilioBehavior extends ModelBehavior {

/**
 * Contains configuration settings for use with individual model objects.
 * Individual model settings should be stored as an associative array, 
 * keyed off of the model name.
 *
 * @var array
 * @access public
 * @see Model::$alias
 */
	var $settings = array();

/**
 * Reference to Text model
 *
 * @var object
 */
	var $_Text = null;

/**
 * Initiate Twilio Behavior
 *
 * @param object $model
 * @param array $config
 * @return void
 * @access public
 */
	function setup(&$model, $config = array()) {
		$this->settings[$model->alias] = array_merge(array('datasource' => 'twilio', 'From' => null), $config);
	}

/**
 * Send an SMS
 *
 * @param object $model 
 * @param mixed $To Number or array of To, Message, and From
 * @param string $Message 
 * @param string $From 
 * @return boolean True if sent, false otherwise
 * @access public
 */
	function sendSms(&$model, $To, $Body = null, $From = null) {
		if (!$this->_setupModel($model)) {
			return false;
		}

		if (is_array($To)) {
			if (isset($To['From'])) {
				$From = $To['From'];
			}
			if (isset($To['Body'])) {
				$Body = $To['Body'];
			}
			if (isset($To['To'])) {
				$To = $To['To'];
			}
		}

		if (is_array($To)) {
			return false;
		}

		if (empty($From)) {
			$From = $this->settings[$model->alias]['From'];
		}

		if (empty($To) || empty($Body) || empty($From)) {
			return false;
		}

		return $this->_Text->save(compact('To', 'Body', 'From'));
	}

/**
 * Setup the model
 *
 * @return void
 * @access protected
 */
	function _setupModel(&$model) {
		if ($this->_Text) {
			return true;
		}

		$this->_Text = ClassRegistry::init(array(
			'class' => 'Twilio.TwilioText',
			'table' => 'texts',
			'ds' => $this->settings[$model->alias]['datasource'],
		));
		return true;
	}

}
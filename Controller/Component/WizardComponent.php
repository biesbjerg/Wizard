<?php
App::uses('Component', 'Controller');
class WizardComponent extends Component {

/**
 * Components
 */
	public $components = array(
		'Session'
	);

/**
 * Controller object
 *
 * @var Controller
 */
	public $controller;

/**
 * Request object
 *
 * @var CakeRequest
 */
	public $request;


/**
 * Wizard configuration
 */
	public $config = array(
		'sessionKey' => 'Wizard',
		'steps' => array()
	);

/**
 * Contains the array index of the current step
 */
	protected $_index;


	public function __construct(ComponentCollection $collection, $config = array()) {
		parent::__construct($collection);
		$this->config += $config;
	}

	public function initialize(Controller $controller) {
		$this->controller = $controller;
		$this->request = $controller->request;
	}

	public function startup(Controller $controller) {
		if (!$this->isStep($this->request->here)) {
			$this->_index = $this->_read('lastCompletedStep');
			return;
		}

		if (!$this->_setStep($this->request->here)) {
			return false;
		}
		if (!$this->_canAccessStep($this->request->here)) {
			$expectedStep = $this->getExpectedStep();
			return $this->controller->redirect($expectedStep['url']);
		}
		if ($this->request->is(array('post', 'put')) || $this->isDisabled($this->request->here)) {
			$this->process($this->request->here);
		}

		$this->request->data = Hash::merge($this->data(), $this->request->data);
	}

	public function process($url) {
		if (!$this->_canAccessStep($url)) {
			$expectedStep = $this->getExpectedStep();
			return $this->controller->redirect($expectedStep['url']);
		}

		$callback = sprintf('_process_%s', $this->request->param('action'));
		if (method_exists($this->controller, $callback)) {
			$result = $this->controller->$callback($this);
			if ($result === false) {
				return false;
			}

			// Save modifications made by callback
			$this->_write($this->_index, $this->request->data);
		}

		$this->_write($this->_index, $this->request->data);
		$this->_write('lastCompletedStep', $this->_index);

		$nextStep = $this->getNextStep();
		if ($nextStep) {
			return $this->controller->redirect($nextStep['url']);
		}
	}

	public function addStep(array $options) {
		$options += array(
			'name' => '',
			'description' => '',
			'action' => null,
			'hidden' => false,
			'disabled' => false
		);

		$options['url'] = Router::url(array(
			'action' => $options['action']
		));

		$this->config['steps'][] = $options;
	}

	public function isHidden($url) {
		$step = $this->getStep($url);
		if (is_callable($step['hidden'])) {
			$step['hidden'] = call_user_func($step['hidden']);
		}
		return (bool)$step['hidden'];
	}

	public function isDisabled($url) {
		$step = $this->getStep($url);
		if (is_callable($step['disabled'])) {
			$step['disabled'] = call_user_func($step['disabled']);
		}
		return (bool)$step['disabled'];
	}

	public function getStep($url) {
		$index = $this->getIndex($url);
		if (isset($this->config['steps'][$index])) {
			return $this->config['steps'][$index];
		}
		return false;
	}

/**
 * Returns an array of visible steps with some meta data (completed, active)
 */
	public function getSteps() {
		$stepNum = 1;
		$steps = $this->config['steps'];
		foreach ($steps as $index => $step) {
			$steps[$index]['hidden'] = $this->isHidden($step['url']);
			if ($steps[$index]['hidden']) {
				unset($steps[$index]);
				continue;
			}

			$steps[$index] += array(
				'step' => $stepNum,
				'completed' => $index < $this->_index,
				'active' => $index == $this->_index
			);

			$steps[$index]['disabled'] = $this->isDisabled($step['url']);

			$stepNum++;
		}
		return array_values($steps);
	}

/**
 * Finds the last completed step stored in the session and returns
 * the next step. If no steps were previously completed it returns the first step.
 *
 * @return array
 */
	public function getExpectedStep() {
		$index = $this->_read('lastCompletedStep');
		if (!is_numeric($index)) {
			return $this->config['steps'][0];
		}
		if (isset($this->config['steps'][$index + 1])) {
			return $this->config['steps'][$index + 1];
		}
		return $this->config['steps'][$index];
	}

	public function getCurrentStep() {
		return $this->getStep($this->request->here);
	}

	public function getNextStep() {
		if (isset($this->config['steps'][$this->_index + 1])) {
			$step = $this->config['steps'][$this->_index + 1];
			$step['hidden'] = $this->isHidden($step['url']);
			$step['disabled'] = $this->isDisabled($step['url']);
			return $step;
		}
		return false;
	}

	public function getPreviousStep() {
		for ($i = $this->_index - 1; $i >= 0; $i--) {
			$step = $this->config['steps'][$i];
			$step['hidden'] = $this->isHidden($step['url']);
			$step['disabled'] = $this->isDisabled($step['url']);
			if (!$step['disabled']) {
				return $step;
			}
		}
		return false;
	}

	public function getIndex($url) {
		foreach ($this->config['steps'] as $index => $step) {
			if ($step['url'] === $url) {
				return $index;
			}
		}
		return false;
	}

	public function isStep($url) {
		foreach ($this->config['steps'] as $step) {
			if ($url === $step['url']) {
				return true;
			}
		}
		return false;
	}

	public function data($key = null) {
		$data = $this->_getWizardData();
		if ($key === null) {
			return $data;
		}
		return Hash::get($data, $key);
	}

	protected function _write($key, $val) {
		$key = implode('.', array($this->config['sessionKey'], $key));
		return $this->Session->write($key, $val);
	}

	protected function _read($key) {
		$key = implode('.', array($this->config['sessionKey'], $key));
		return $this->Session->read($key);
	}

	public function reset() {
		return $this->Session->delete($this->config['sessionKey']);
	}

/**
 * Set step
 *
 * @param $step Step to point to.
 */
	protected function _setStep($url) {
		foreach ($this->config['steps'] as $index => $step) {
			if ($step['url'] === $url) {
				return $this->_index = $index;
			}
		}
		return false;
	}

/**
 * Check if url can be accessed (previous steps should be completed)
 *
 * @param $url Url to validate is a step that can be accessed.
 * @return boolean False on invalid step url or if step is not accessable.
 */
	protected function _canAccessStep($url) {
		if (!$this->isStep($url)) {
			return false;
		}

		$index = $this->getIndex($url);
		if ($index === 0) {
			return true; // Always allow first step
		}

		$getExpectedStep = $this->getExpectedStep();
		$indexComp = $this->getIndex($getExpectedStep['url']);
		if ($index <= $indexComp) {
			return true;
		}
		return false;
	}

	protected function _getWizardData() {
		$data = array();
		foreach ($this->config['steps'] as $index => $step) {
			if ($index > $this->_index) {
				break;
			}

			$stepData = $this->_read($index);
			if (!$stepData) {
				break;
			}
			$data = Hash::merge(
				$data,
				$stepData
			);
		}
		return $data;
	}

}

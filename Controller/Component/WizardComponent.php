<?php
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
			return;
		}

		$this->_setStep($this->request->here);

		if ($this->request->is(array('post', 'put'))) {
			$this->process($this->request->here);
		}
		if ($this->request->is('get')) {
			if (!$this->_canAccessStep($this->request->here)) {
				$expectedStep = $this->getExpectedStep();
				return $this->controller->redirect($expectedStep['url']);
			}
		}

		$this->request->data = $this->data();
	}

	public function process($url) {
		if (!$this->isStep($url) || !$this->_canAccessStep($url)) {
			$expectedStep = $this->getExpectedStep();
			return $this->controller->redirect($expectedStep['url']);
		}

		$callback = sprintf('_process_%s', $this->request->params['action']);
		if (method_exists($this->controller, $callback)) {
			$this->request->data = Hash::merge($this->data(), $this->request->data);
			$result = $this->controller->$callback();
			$this->data($this->_index, $this->request->data);
			if ($result === false) {
				return false;
			}
		}

		$this->data($this->_index, $this->request->data);
		$this->data('lastCompletedStep', $this->_index);

		$nextStep = $this->getNextStep();
		if ($nextStep) {
			return $this->controller->redirect($nextStep['url']);
		}
	}

	public function addStep(array $options) {
		$options += array(
			'name' => '',
			'description' => '',
			'url' => null,
			'hidden' => false
		);
		$options['url'] = Router::url($options['url']);
		$this->config['steps'][] = $options;
	}

/**
 * Returns an array of visible steps with some meta data (completed, active)
 */
	public function getSteps() {
		$steps = $this->config['steps'];
		foreach ($steps as $index => $step) {
			if ($step['hidden']) {
				unset($steps[$index]);
				continue;
			}
			$steps[$index] += array(
				'completed' => $index < $this->_index,
				'active' => $index == $this->_index
			);
		}
		return $steps;
	}

/**
 * Finds the last completed step stored in the session and returns
 * the next step. If no steps were previously completed it returns the first step.
 *
 * @return array
 */
	public function getExpectedStep() {
		$index = $this->data('lastCompletedStep');
		if (!is_numeric($index)) {
			return $this->config['steps'][0];
		}
		if (isset($this->config['steps'][$index + 1])) {
			return $this->config['steps'][$index + 1];
		}
		return $this->config['steps'][$index];
	}

	public function getNextStep() {
		if (isset($this->config['steps'][$this->_index + 1])) {
			return $this->config['steps'][$this->_index + 1];
		}
		return false;
	}

	public function getPreviousStep() {
		if (isset($this->config['steps'][$this->_index - 1])) {
			return $this->config['steps'][$this->_index - 1];
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

	public function data($key = null, $val = null) {
		if ($key === null) {
			return $this->_mergedRequestData();
		}

		$key = implode('.', array($this->config['sessionKey'], $key));
		if ($val === null) {
			return $this->Session->read($key);
		}
		return $this->Session->write($key, $val);
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

	protected function _mergedRequestData() {
		$data = array();
		foreach ($this->config['steps'] as $index => $step) {
			if ($index > $this->_index) {
				break;
			}
			$data = Hash::merge($data, $this->data($index));
		}
		return $data;
	}

}

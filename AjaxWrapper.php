<?php
if (!class_exists('Ajaw_v1_ActionBuilder', false)):

	class Ajaw_v1_ActionBuilder {
		private $action;
		private $callback = '__return_null';
		private $params = array();

		private $capability = null;
		private $mustBeLoggedIn = true;
		private $checkNonce = true;

		public function __construct($action) {
			$this->action = $action;
		}

		/**
		 * @param callable $callback
		 * @return $this
		 */
		public function handler($callback) {
			$this->callback = $callback;
			return $this;
		}

		public function requiredParam($name) {
			$this->params[$name] = array('required' => true);
			return $this;
		}

		public function optionalParam($name, $defaultValue = null) {
			$this->params[$name] = array(
				'required' => false,
				'defaultValue' => $defaultValue,
			);
			return $this;
		}

		public function requiredCap($capability) {
			$this->capability = $capability;
			return $this;
		}

		public function allowUnprivilegedUsers() {
			$this->mustBeLoggedIn = false;
			return $this;
		}

		public function withoutNonce() {
			$this->checkNonce = false;
			return $this;
		}

		public function build() {
			$instance = new Ajaw_v1_Action($this->action, $this->callback, $this->params);

			$instance->mustBeLoggedIn = $this->mustBeLoggedIn;
			$instance->requiredCap = $this->capability;
			$instance->nonceCheckEnabled = $this->checkNonce;

			return $instance;
		}

		public function register() {
			$instance = $this->build();
			$instance->register();
			return $instance;
		}
	}

endif;

if (!class_exists('Ajaw_v1_Action', false)):

	class Ajaw_v1_Action {
		public $action;
		public $callback;
		public $params = array();

		public $requiredCap = null;
		public $mustBeLoggedIn = false;
		public $nonceCheckEnabled = true;

		private $isScriptRegistered = false;

		public $get = array();
		public $post = array();
		public $request = array();

		public function __construct($action, $callback, $params) {
			$this->action = $action;
			$this->callback = $callback;
			$this->params = $params;

			if (empty($this->action)) {
				throw new LogicException(sprintf(
					'AJAX action name is missing. You must either pass it to the %1$s constructor '
					. 'or give the %1$s::$action property a valid default value.',
					get_class($this)
				));
			}
		}

		/**
		 * Set up hooks for AJAX and helper scripts.
		 */
		public function register() {
			//Register the AJAX handler(s).
			$hookNames = array('wp_ajax_' . $this->action);
			if (!$this->mustBeLoggedIn) {
				$hookNames[] = 'wp_ajax_nopriv_' . $this->action;
			}

			foreach($hookNames as $hook) {
				if (has_action($hook)) {
					throw new RuntimeException(sprintf('The action name "%s" is already in use.', $this->action));
				}
				add_action($hook, array($this, 'processAjaxRequest'));
			}

			//Register the utility JS library after WP is fully loaded.
			if (did_action('wp_loaded')) {
				$this->registerScript();
			} else {
				add_action('wp_loaded', array($this, 'registerScript'), 2);
			}
		}

		/**
		 * @access protected
		 */
		public function processAjaxRequest() {
			$result = $this->handleAction();

			if (is_wp_error($result)) {
				$statusCode = $result->get_error_data();
				if (isset($statusCode) && is_int($statusCode) ) {
					status_header($statusCode);
				}

				$errorResponse = array(
					'error' => array(
						'message' => $result->get_error_message(),
						'code' => $result->get_error_code()
					)
				);

				$result = $errorResponse;
			}

			if (isset($result)) {
				$this->outputJSON($result);
			}
			exit;
		}

		protected function handleAction() {
			$isAuthorized = $this->checkAuthorization();
			if ($isAuthorized !== true) {
				return $isAuthorized;
			}

			$params = $this->parseParameters();
			if ($params instanceof WP_Error) {
				return $params;
			}

			//Call the user-specified action handler.
			if (is_callable($this->callback)) {
				return call_user_func($this->callback, $params);
			} else {
				return new WP_Error(
					'missing_ajax_handler',
					sprintf(
						'There is no request handler assigned to the "%1$s" action. '
						. 'Either pass a valid callback to $builder->request() or override the %2$s::%3$s method.',
						$this->action,
						__CLASS__,
						__METHOD__
					),
					500
				);
			}
		}

		/**
		 * Check if the current user is authorized to perform this action.
		 *
		 * @return bool|WP_Error
		 */
		protected function checkAuthorization() {
			if ($this->mustBeLoggedIn && !is_user_logged_in()) {
				return new WP_Error('login_required', 'You must be logged in to perform this action.', 403);
			}

			if (isset($this->requiredCap) && !current_user_can($this->requiredCap)) {
				return new WP_Error('capability_missing', 'You don\'t have permission to perform this action.', 403);
			}

			if ($this->nonceCheckEnabled && !check_ajax_referer($this->action, false, false)) {
				return new WP_Error('nonce_check_failed', 'Invalid or missing nonce.', 403);
			}

			return true;
		}

		protected function parseParameters() {
			$method = strtoupper(filter_input(INPUT_SERVER, 'REQUEST_METHOD'));

			//Retrieve request parameters.
			if ($method === 'GET') {
				$rawParams = $_GET;
			} else if ($method === 'POST') {
				$rawParams = $_POST;
			} else {
				$rawParams = $_REQUEST;
			}

			//Remove magic quotes. WordPress applies them in wp-settings.php.
			//There's no hook for wp_magic_quotes, so we use one that's closest in execution order.
			if (did_action('sanitize_comment_cookies') && function_exists('wp_magic_quotes')) {
				$rawParams = wp_unslash($rawParams);
			}

			//Verify that all of the required parameters are present. Empty strings are not allowed.
			$inputParams = $rawParams;
			foreach($this->params as $name => $settings) {
				if (isset($inputParams[$name]) && ($inputParams[$name] !== '')) {
					continue;
				}

				if (empty($settings['required'])) {
					//It's an optional parameter. Use the default value.
					$inputParams[$name] = $settings['defaultValue'];
				} else {
					//This is an error.
					return new WP_Error(
						'missing_required_parameter',
						sprintf('Required parameter is missing or empty: "%s".', $name),
						400
					);
				}
			}

			return $inputParams;
		}

		protected function outputJSON($response) {
			@header('Content-Type: application/json; charset=' . get_option('blog_charset'));
			echo json_encode($response);
		}

		public function registerScript() {
			if ($this->isScriptRegistered) {
				return;
			}
			$this->isScriptRegistered = true;

			//There could be multiple instances of this class, but we only need to register the script once.
			$handle = $this->getScriptHandle();
			if (!wp_script_is($handle, 'registered')) {
				wp_register_script(
					$handle,
					plugins_url('ajax-action-wrapper.js', __FILE__),
					array('jquery'),
					'20161105'
				);
			}

			//Pass the action to the script.
			wp_add_inline_script($handle, $this->generateActionJs(), 'after');
		}

		protected function generateActionJs() {
			$properties = array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'requiredMethod' => null,
				'nonce' => $this->nonceCheckEnabled ? wp_create_nonce($this->action) : null,
			);

			return sprintf(
				'AjawV1.actionRegistry.add("%s", %s);' . "\n",
				esc_js($this->action),
				json_encode($properties)
			);
		}

		public function getScriptHandle() {
			return 'ajaw-v1-ajax-action-wrapper';
		}

		/**
		 * Capture $_GET, $_POST and $_REQUEST without magic quotes.
		 */
		function captureRequestVars() {
			$this->post = $_POST;
			$this->get = $_GET;
			$this->request = $_REQUEST;

			if ( function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc() ) {
				$this->post = stripslashes_deep($this->post);
				$this->get = stripslashes_deep($this->get);
			}
		}
	}

endif;

if (!function_exists('ajaw_v1_CreateAction')) {
	function ajaw_v1_CreateAction($action) {
		$className = apply_filters('ajaw_builder_class_name', 'Ajaw_v1_ActionBuilder');
		return new $className($action);
	}
}

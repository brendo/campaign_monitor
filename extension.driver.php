<?php

	class Extension_Campaign_Monitor extends Extension {
		private $params = array();

		public function about() {
			return array(
				'name'			=> 'Campaign Monitor',
				'version'		=> '0.9.2pre',
				'release-date'	=> '2011-02-18',
				'author'		=> array(
					array(
						'name' => 'Brendan Abbott',
						'website' => 'http://www.bloodbone.ws',
						'email' => 'brendan@bloodbone.ws'
					),
					array(
						'name' => 'Rowan Lewis',
						'website' => 'http://rowanlewis.com/',
						'email'	=> 'me@rowanlewis.com'
					)
				),
				'description'	=> 'A simple Event Filter to add Subscribers to your Campaign Monitor lists via Symphony events.'
	 		);
		}

		public function getSubscribedDelegates() {
			return array(
				array(
					'page'		=> '/blueprints/events/new/',
					'delegate'	=> 'AppendEventFilter',
					'callback'	=> 'appendFilter'
				),
				array(
					'page'		=> '/blueprints/events/edit/',
					'delegate'	=> 'AppendEventFilter',
					'callback'	=> 'appendFilter'
				),
				array(
					'page'		=> '/blueprints/events/new/',
					'delegate'	=> 'AppendEventFilterDocumentation',
					'callback'	=> 'appendDocumentation'
				),
				array(
					'page'		=> '/blueprints/events/edit/',
					'delegate'	=> 'AppendEventFilterDocumentation',
					'callback'	=> 'appendDocumentation'
				),
				array(
					'page'		=> '/system/preferences/',
					'delegate'	=> 'AddCustomPreferenceFieldsets',
					'callback'	=> 'appendPreferences'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'EventPostSaveFilter',
					'callback'	=> 'preProcessData'
				),
				array(
					'page'		=> '/frontend/',
					'delegate'	=> 'EventPostSaveFilter',
					'callback'	=> 'postProcessData'
				)
			);
		}

	/*-------------------------------------------------------------------------
		Installation:
	-------------------------------------------------------------------------*/

		public function install(){
			try {
				Symphony::Database()->query("
					CREATE TABLE IF NOT EXISTS `tbl_fields_campaign_monitor` (
						`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
						`field_id` INT(11) UNSIGNED NOT NULL,
						`relation_id` INT(11) UNSIGNED NOT NULL,
						`cm_list_id` VARCHAR(32) NOT NULL,
						`cache_validity` VARCHAR(32) NOT NULL,
					  	PRIMARY KEY  (`id`),
					  	UNIQUE KEY `field_id` (`field_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;
				");
			}
			catch (Exception $ex) {
				$extension = $this->about();
				Administration::instance()->Page->pageAlert(__('An error occurred while installing %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
				return false;
			}

			return true;
		}

		public function update($previousVersion){
			if(version_compare($previousVersion, '0.9.2', '<')) {
				return $this->install();
			}

			return true;
		}

		public function uninstall() {
			if(parent::uninstall() == true){
				try {
					Symphony::Configuration()->remove('campaignmonitor');
					Administration::instance()->saveConfig();
					Symphony::Database()->query("DROP TABLE `tbl_fields_campaignmonitor`");

					return true;
				}
				catch (Exception $ex) {
					$extension = $this->about();
					$this->pageAlert(__('An error occurred while uninstalling %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
					return false;
				}
			}

			return false;
		}

	/*-------------------------------------------------------------------------
		Delegate Callbacks:
	-------------------------------------------------------------------------*/

		public function appendFilter($context) {
			$context['options'][] = array(
				'campaignmonitor',
				@in_array(
					'campaignmonitor', $context['selected']
				),
				'Campaign Monitor'
			);
		}

		public function appendDocumentation($context) {
			if (!in_array('campaignmonitor', $context['selected'])) return;

			$context['documentation'][] = new XMLElement('h3', 'Campaign Monitor Filter');

			$context['documentation'][] = new XMLElement('p', '
				To use the Campaign Monitor filter, add the following field to your form:
			');

			$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode('
<input name="campaignmonitor[list]" value="{$your-list-id}" type="hidden" />
<input name="campaignmonitor[field][Name]" value="$field-first-name, $field-last-name" type="hidden" />
<input name="campaignmonitor[field][Email]" value="$field-email-address" type="hidden" />
<input name="campaignmonitor[field][Custom]" value="Value for field Custom Field..." type="hidden" />
			');

			$context['documentation'][] = new XMLElement('p', '
				If you require any existing Campaign Monitor subscriber\'s data to be merged, you can provide
				the fields you want to merge like so:
			');

			$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode('
<input name="campaignmonitor[merge-fields]" value="Name of Custom Field1, Name of CustomField2" type="hidden" />
			');
		}

		public function appendPreferences($context) {
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(
				new XMLElement('legend', 'Campaign Monitor Filter')
			);

			$uniqueID = Widget::Label('API Key');
			$uniqueID->appendChild(Widget::Input(
				'settings[campaignmonitor][apikey]', Extension_Campaign_Monitor::getAPIKey()
			));
			$group->appendChild($uniqueID);

			$context['wrapper']->appendChild($group);
		}

		public function preProcessData($context) {
			if (!in_array('campaignmonitor', $context['event']->eParamFILTERS)) return;

			if (
				!isset($_POST['campaignmonitor']['list'])
				or !isset($_POST['campaignmonitor']['field']['Email'])
			) {
				$context['messages'][] = array(
					'campaignmonitor',
					false,
					'Required field missing, see event documentation.'
				);
			}
		}

		public function postProcessData($context) {
			if (!in_array('campaignmonitor', $context['event']->eParamFILTERS)) return;

			// Create params:
			$this->params = $this->prepareFields('field', $_POST['fields']);

			// Parse values:
			$values = $this->parseFields($_POST['campaignmonitor']['field']);

			// If there is any fields that should be merged:
			if(array_key_exists('merge-fields', $_POST['campaignmonitor'])) {
				$values = $this->mergeFields($_POST['campaignmonitor']['merge-fields'], $values);
			}

			$request = array();
			// Add fields:
			foreach ($values as $name => $value) {
				if ($name == 'Name') {
					$request[$name] = $value;
				}
				elseif ($name == 'Email') {
					$request['EmailAddress'] = $value;
				}
				else {
					if(is_array($value)) {
						foreach($value as $nv) {
							$request['CustomFields'][] = array(
								'Key'	=> $name,
								'Value'	=> $nv
							);
						}
					}
					else {
						$request['CustomFields'][] = array(
							'Key'	=> $name,
							'Value'	=> $value
						);
					}
				}
			}

			$request = json_encode($request);

			$api = sprintf(
				"http://api.createsend.com/api/v3/subscribers/%s.json", $_POST['campaignmonitor']['list']
			);

			$ch = curl_init($api);
			curl_setopt_array($ch, array(
				CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
				CURLOPT_USERPWD => Extension_Campaign_Monitor::getAPIKey() . ":magic",
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $request,
				CURLOPT_HTTPHEADER => array("Content-type: application/json; charset=utf-8"),
				CURLOPT_RETURNTRANSFER => true
			));

			$response = curl_exec($ch);
			$info = curl_getinfo($ch);

			$response = json_decode($response);

			if(in_array($info['http_code'], array(200, 201))) {
				$context['messages'][] = array('campaignmonitor', true, $response);
			}
			else {
				$context['messages'][] = array('campaignmonitor', false, $response->Message);
			}

			curl_close($ch);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public static function getAPIKey() {
			return Symphony::Configuration()->get('apikey', 'campaignmonitor');
		}

		public static function appendAssets() {
			if(class_exists('Administration')
				&& Administration::instance() instanceof Administration
				&& Administration::instance()->Page instanceof HTMLPage
			) {
				Administration::instance()->Page->addStylesheetToHead(URL . '/extensions/campaign_monitor/assets/campaign_monitor.publish.css', 'screen', 10000, false);
				//Administration::instance()->Page->addScriptToHead(URL . '/extensions/campaign_monitor/assets/campaign_monitor.publish.js', 10001, false);
			}
		}

		/**
		 * Takes the `$_POST['fields']` and generates a flat array of
		 * all the fields.
		 *
		 * @param string $path
		 *  The default string to use when a field needs to be flattened
		 * @param array $fields
		 *  The `$_POST['fields']` array
		 * @return array
		 */
		public function prepareFields($path, $fields) {
			$output = array();

			foreach($fields as $key => $value) {
				if (!is_numeric($key)) {
					$key = "{$path}-{$key}";

					if (is_array($value)) {
						$temp = $this->prepareFields($key, $value);
						$output = array_merge($output, $temp);
					}
					else {
						$output[$key] = $value;
					}
				}
				else {
					$key = $path;

					$output[$key][] = $value;
				}
			}

			return $output;
		}

		/**
		 * Given an associative array of values, this will strip the
		 * dollar notation from the value, and then will map the fields
		 * to their Campaign Monitor equivalents.
		 *
		 * @param array $values
		 * @return array
		 */
		public function parseFields($values) {
			foreach($values as $key => $value) {
				$value = preg_replace('/^\$/', null, $value);

				if(isset($this->params[$value])) {
					$values[$key] = $this->params[$value];
				}
			}

			return $values;
		}

		/**
		 * Allows fields from the form to merge onto existing Campaign Monitor
		 * data for this subscriber. For instance, if you have Select Many custom
		 * field in Campaign Monitor, it expects all the values of the field to
		 * be provided, otherwise it will erase them and add the ones provided in
		 * the API call. This function will contact the CM API for the subscriber,
		 * and their existing record with the fields as supplied in `$merge`
		 *
		 * @param string $merge_fields
		 *  A comma separated string of all the fields that need to merged with
		 *  the current $_POST data. This should be the field names as they are named
		 *  in Campaign Monitor.
		 * @param array $values
		 *  The current parsed values for the current $_POST data. These have been parsed
		 *  and already mapped to the Campaign Monitor field names.
		 */
		public function mergeFields($merge_fields, $values) {
			$merge_fields = explode(",", $merge_fields);
			$merge_fields = array_map('trim', $merge_fields);

			$result = Extension_Campaign_Monitor::retreiveSubscriberByEmail($values['Email'], $_POST['campaignmonitor']['list']);

			// If subscriber is new, there's no data to fetch!
			if($result['info'] == 203) return $values;

			$response = json_decode($result['response']);

			if(is_array($response->CustomFields)) {
				foreach($response->CustomFields as $object) {
					if(!in_array($object->Key, $merge_fields)) continue;

					if(!is_array($values[$object->Key])) {
						$values[$object->Key] = array($values[$object->Key]);
					}

					$values[$object->Key][] = $object->Value;
				}
			}

			return $values;
		}

	/*-------------------------------------------------------------------------
		API Connectivity [Break this into a /lib if this grows further]
	-------------------------------------------------------------------------*/

		/**
		 * Given an email address, and a list, retrieve a subscriber's information
		 *
		 * @param string $email
		 * @param string $list
		 * @return array('response', 'info');
		 */
		public static function retreiveSubscriberByEmail($email, $list) {
			// Contact C+S and check to see if this subscriber already exists
			// and if so, merge the values so that it won't be overidden.
			$api = sprintf(
				"http://api.createsend.com/api/v3/subscribers/%s.json?email=%s",
				$list, urlencode($email)
			);

			$ch = curl_init($api);
			curl_setopt_array($ch, array(
				CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
				CURLOPT_USERPWD => Extension_Campaign_Monitor::getAPIKey() . ":magic",
				CURLOPT_HTTPHEADER => array("Content-type: application/json; charset=utf-8"),
				CURLOPT_RETURNTRANSFER => true
			));

			$response = curl_exec($ch);
			$info = curl_getinfo($ch);

			return array(
				'response' => $response,
				'info' => $info
			);
		}
	}

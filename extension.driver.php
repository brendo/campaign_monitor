<?php

	class Extension_Campaign_Monitor extends Extension {
		private $params = array();

		public function about() {
			return array(
				'name'			=> 'Campaign Monitor',
				'version'		=> '0.9',
				'release-date'	=> '2011-02-07',
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

		public function uninstall() {
			Symphony::Configuration()->remove('createsend');
			Administration::instance()->saveConfig();
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

		public function getAPIKey() {
			return Symphony::Configuration()->get('apikey', 'createsend');
		}

		public function appendDocumentation($context) {
			if (!in_array('campaignmonitor', $context['selected'])) return;

			$context['documentation'][] = new XMLElement('h3', 'Campaign Monitor filter');

			$context['documentation'][] = new XMLElement('p', '
				To use the Campaign Monitor filter, add the following field to your form:
			');

			$context['documentation'][] = contentBlueprintsEvents::processDocumentationCode('
<input name="campaignmonitor[list]" value="0427e1a03e14880793a6c0a0d3e587b3" type="hidden" />
<input name="campaignmonitor[field][Name]" value="$field-first-name, $field-last-name" type="hidden" />
<input name="campaignmonitor[field][Email]" value="$field-email-address" type="hidden" />
<input name="campaignmonitor[field][Custom]" value="Value for field Custom..." type="hidden" />
			');
		}

		public function appendFilter($context) {
			$context['options'][] = array(
				'campaignmonitor',
				@in_array(
					'campaignmonitor', $context['selected']
				),
				'Campaign Monitor'
			);
		}

		public function appendPreferences($context) {
			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(
				new XMLElement('legend', 'Campaign Monitor Filter')
			);

			$uniqueID = Widget::Label('API Key');
			$uniqueID->appendChild(Widget::Input(
				'settings[campaignmonitor][apikey]', General::Sanitize($this->getAPIKey())
			));
			$group->appendChild($uniqueID);

			$context['wrapper']->appendChild($group);
		}

		public function manipulateParameters($context) {
			$context['params']['campaignmonitor'] = $this->getHash();
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

		public function parseFields($values) {
			foreach($values as $key => $value) {
				$value = preg_replace('/^\$/', null, $value);

				if(isset($this->params[$value])) {
					$values[$key] = $this->params[$value];
				}
			}

			return $values;
		}

		public function postProcessData($context) {

			if (!in_array('campaignmonitor', $context['event']->eParamFILTERS)) return;

			// Create params:
			$this->params = $this->prepareFields('field', $_POST['fields']);

			// Parse values:
			$values = $this->parseFields($_POST['campaignmonitor']['field']);

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
				CURLOPT_USERPWD => $this->getAPIKey() . ":magic",
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
	}

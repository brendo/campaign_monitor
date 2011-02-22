<?php

	if(!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	Class fieldCampaign_Monitor extends Field {

		public function __construct(&$parent) {
			parent::__construct($parent);
			$this->_name = __('Campaign Monitor');
			$this->_required = false;
			$this->_showcolumn = false;

			$this->set('location', 'sidebar');
		}

		public function mustBeUnique() {
			return true;
		}

	/*-------------------------------------------------------------------------
		Setup:
	-------------------------------------------------------------------------*/

		public function createTable() {
			try {
				Symphony::Database()->query(sprintf("
						CREATE TABLE IF NOT EXISTS `tbl_entries_data_%d` (
							`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
							`entry_id` INT(11) UNSIGNED NOT NULL,
							`data` TEXT NOT NULL,
							`last_cached` DATETIME NOT NULL,
							PRIMARY KEY (`id`),
							UNIQUE KEY `entry_id` (`entry_id`)
						) ENGINE=MyISAM DEFAULT CHARSET=utf8;
					", $this->get('id')
				));

				return true;
			}
			catch (Exception $ex) {
				return false;
			}
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public function appendSettingsNotice(&$wrapper) {
			$p = new XMLElement('p');
			$p->appendChild(new XMLElement('strong', __('This field needs to be saved before you can complete it\'s configuration')));
			$wrapper->appendChild($p);
		}

		public function findCacheValidity() {
			$default = array('5 minutes' => '5 minutes', '10 minutes' => '10 minutes', '1 hour' => '1 hour');

			$used = Symphony::Database()->fetchCol('cache_validity', sprintf("
				SELECT DISTINCT(cache_validity) FROM `tbl_fields_campaign_monitor`
			"));

			$merged = array_merge($default, array_combine($used, $used));

			natsort($merged);

			return $merged;
		}

		public function fetchSubscriberInformation(&$wrapper, $entry_id, $email) {
			// Check cache_validity
			$cache = Symphony::Database()->fetchRow(0, sprintf("
				SELECT data, last_cached
				FROM tbl_entries_data_%d
				WHERE entry_id = %d
				AND DATE_FORMAT(last_cached, '%%Y-%%m-%%d %%H:%%i:%%s') > '%s'
			", $this->get('id'), $entry_id, DateTimeObj::get('Y-m-d H:i:s', strtotime('now - ' . $this->get('cache_validity')))
			));

			// Fetch from CM
			if(empty($cache)) {
				$result = Extension_Campaign_Monitor::retreiveSubscriberByEmail($email, $this->get('cm_list_id'));

				// Save into Database
				if($result['info']['http_code'] == 200) {
					$cache = array(
						'data' => $result['response'],
						'last_cached' => DateTimeObj::get('Y-m-d H:i:s', time()),
						'entry_id' => $entry_id
					);

					Symphony::Database()->insert($cache, "tbl_entries_data_{$this->get('id')}", true);
				}
			}

			// Display
			$result = json_decode($cache['data']);

			$dl = new XMLElement('dl');
			$keys = array();
			foreach($result as $key => $value) {
				if(is_array($value)) {
					foreach($value as $obj) {
						if(!in_array($obj->Key, $keys)) {
							$keys[] = $obj->Key;
							$dl->appendChild(new XMLElement('dt', $obj->Key));
						}

						$dl->appendChild(new XMLElement('dd', $obj->Value));
					}
				}
				else {
					$dl->appendChild(new XMLElement('dt', $this->formatCMKey($key)));
					$dl->appendChild(new XMLElement('dd', $value));
				}
			}

			$wrapper->appendChild($dl);
			$wrapper->appendChild(
				new XMLElement('span', __('Last Updated %s', array(DateTimeObj::get(__SYM_DATETIME_FORMAT__, strtotime($cache['last_cached'])))))
			);

		}

		public function formatCMKey($key) {
			switch ($key) {
				case "EmailAddress": return "Email Address";break;
				case "Date": return "Subscription Date";break;
				default: return $key;
			}
		}



	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/

		/**
		 * Displays setting panel in section editor.
		 *
		 * @param XMLElement $wrapper - parent element wrapping the field
		 * @param array $errors - array with field errors, $errors['name-of-field-element']
		 */
		public function displaySettingsPanel(&$wrapper, $errors = null) {
			//	Initialize field settings based on class defaults (name, placement)
			parent::displaySettingsPanel($wrapper, $errors);

			$order = $this->get('sortorder');

			// Get all the fields in this section
			$section = $this->get('parent_section');
			if(is_null($section)) {
				$this->appendSettingsNotice(&$wrapper);
				return true;
			}

			$sectionManager = new SectionManager($this->_Parent);
			$section = $sectionManager->fetch($section);
			$related_fields = $section->fetchFields();

			$options = array();
			foreach($related_fields as $field) {
				if($field->get('id') == $this->get('id')) continue;

				$options[] = array($field->get('id'), ($field->get('id') == $this->get('relation_id')), $field->get('label'));
			}

			if(empty($options)) {
				$this->appendSettingsNotice(&$wrapper);
				return true;
			}

			// Add Fields so one can be picked to use to query Campaign Monitor
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Email Field'));
			$label->appendChild(
				Widget::Select("fields[{$order}][relation_id]", $options)
			);

			$group->appendChild($label);

			$wrapper->appendChild($group);

			/* ---------------------------- */

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			// Campaign Monitor List ID
			$label = Widget::Label(__('Campaign Monitor List ID'));
			$label->appendChild(
				new XMLElement('i', __('This list will be queried with the <code>Email Field</code> value'))
			);
			$label->appendChild(Widget::Input(
				"fields[{$order}][cm_list_id]", $this->get('cm_list_id')
			));

			$group->appendChild($label);

			// Cache Time
			$div = new XMLElement('div');

			$label = Widget::Label(__('Cache Validity'));
			$label->appendChild(
				new XMLElement('i', __('How long the results from the API be cached for'))
			);
			$label->appendChild(Widget::Input(
				"fields[{$order}][cache_validity]", $this->get('cache_validity')
			));

			$div->appendChild($label);

			$ul = new XMLElement('ul', NULL, array('class' => 'tags singular'));
			$tags = $this->findCacheValidity();
			foreach($tags as $name => $time) $ul->appendChild(new XMLElement('li', $name, array('class' => $time)));

			$div->appendChild($ul);

			$group->appendChild($div);

			$wrapper->appendChild($group);
		}

		/**
		 * Save field settings in section editor.
		 */
		public function commit() {
			if(!parent::commit()) return false;

			$id = $this->get('id');
			$handle = $this->handle();

			if($id === false) return false;

			Symphony::Database()->delete("tbl_entries_data_{$id}", "1 = 1");

			$fields = array(
				'field_id' => $id,
				'relation_id' => $this->get('relation_id'),
				'cm_list_id' => $this->get('cm_list_id'),
				'cache_validity' => $this->get('cache_validity')
			);

			return Symphony::Database()->insert($fields, "tbl_fields_{$handle}", true);
		}

	/*-------------------------------------------------------------------------
		Input:
	-------------------------------------------------------------------------*/

		public function displayPublishPanel(XMLElement &$wrapper, $data = null, $error = null, $prefix = null, $postfix = null, $entry_id = null) {
			Extension_Campaign_Monitor::appendAssets();

			$label = Widget::Label($this->get('label'));
			$wrapper->appendChild($label);

			if(is_null($entry_id)) {
				$p = new XMLElement('p', __('No entry information'));
			}

			$entryManager = new EntryManager($this->_Parent);
			$entry = $entryManager->fetch($entry_id, $this->get('parent_section'));

			if($entry === false) {
				$p = new XMLElement('p', __('No information'));
			}
			else {
				$entry = current($entry);
			}

			$fieldManager = new FieldManager($this->_Parent);
			$field = $fieldManager->fetch($this->get('relation_id'));

			$email = $field->prepareTableValue($entry->getData($this->get('relation_id')));

			if(is_null($email)) {
				$p = new XMLElement('p', __('No email field'));
			}
			else {
				$this->fetchSubscriberInformation(&$wrapper, $entry_id, $email);
			}

			if($p != null) {
				$wrapper->appendChild($p);
			}

			if ($error != null) {
				$wrapper = Widget::wrapFormElementWithError($wrapper, $error);
			}
		}

		public function checkPostFieldData($data, &$message = null, $entry_id = null) {
			return self::__OK__;
		}

		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {
			$status = self::__OK__;

			$result = array(
				'data' => 'test',
				'last_cached' => time()
			);

			return $result;
		}

	}
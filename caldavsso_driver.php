<?php
require_once (dirname(__FILE__).'/config.inc.php');
require_once (dirname(__FILE__).'/caldavsso_dav.php');
require_once (dirname(__FILE__).'/caldavsso_db.php');
require_once (dirname(__FILE__).'/caldavsso_converters.php');

class caldavsso_driver extends calendar_driver
{
	const PRODID = "windkracht8/caldavsso";
	// features this backend supports
	public $alarms = true;
	public $attendees = true;
	public $freebusy = false;
	public $attachments = false; // ActiveSync version >16
	public $alarm_types = array('DISPLAY');

	private $rc;
	private $cal;
	private $calendars = array();

	/**
	 * Default constructor
	 */
	public function __construct($cal){
		$this->cal = $cal;
		$this->rc = $cal->rc;

		$this->_read_calendars();
	}

	/**
	 * Read available calendars for the current user into $this->calendars
	 */
	protected function _read_calendars(){
		$cals = caldavsso_db::get_instance()->get_cals();

		if(empty($cals)){
			$default_cal = array(
				'cal_id' => 0
				,'name' => "My Calendar"
				,'color' => "0"
				,'showalarms' => true
				,'dav_readonly' => false
				,'dav_url' => str_replace("%USER%", $this->rc->get_user_name(), caldavsso_config::$DEFAULT_DAVSERVER).caldavsso_config::$DEFAULT_TASKLIST
				,'dav_sso' => true
				,'dav_user' => ''
				,'dav_pass' => ''
			);
			$this->create_calendar($default_cal);
			$cals = caldavsso_db::get_instance()->get_cals();
		}

		$this->calendars = array();
		foreach($cals as $cal){
			$this->calendars[$cal['cal_id']] = array(
				'id' => $cal['cal_id']
				,'name' => $cal['name']
				,'listname' => $cal['name']
				,'color' => $cal['color']
				,'showalarms' => $cal['showalarms']
				,'active' => true
				,'group' => ""
				,'editable' => $cal['dav_readonly'] != 1
				,'default' => true
				,'children' => false
				,'dav_url' => $cal['dav_url']
				,'dav_sso' => $cal['dav_sso']
				,'dav_user' => $cal['dav_user']
				,'dav_readonly' => $cal['dav_readonly']
			);
		}
		
		// append the virtual birthdays calendar
		if($this->rc->config->get('calendar_contact_birthdays', false)){
			$prefs = $this->rc->config->get('birthday_calendar', array('color' => '87CEFA'));
			$hidden = array_filter(explode(',', $this->rc->config->get('hidden_calendars', '')));

			$id = self::BIRTHDAY_CALENDAR_ID;
			if(!$active || !in_array($id, $hidden)) {
				$this->calendars[$id] = array(
				'id'         => $id,
				'name'       => $this->cal->gettext('birthdays'),
				'listname'   => $this->cal->gettext('birthdays'),
				'color'      => $prefs['color'],
				'showalarms' => (bool)$this->rc->config->get('calendar_birthdays_alarm_type'),
				'active'     => !in_array($id, $hidden),
				'group'      => 'x-birthdays',
				'editable'  => false,
				'default'    => false,
				'children'   => false,
				);
			}
		}
	}

	/**
	 * Get a list of available calendars from this source
	 *
	 * @param integer Bitmask defining filter criterias
	 *   We ignore this because all dav calendars are active
	 *
	 * @return array List of calendars
	 */
	public function list_calendars($filter = 0){
		return $this->calendars;
	}

	/**
	 * Create a new calendar.
	 *
	 * return whether create succeeded
	 * @see database_driver::create_calendar()
	 */
	public function create_calendar($cal){
		return caldavsso_db::get_instance()->set_cal_data(null
										,$cal['name']
										,$cal['color']
										,$cal['showalarms']
										,$cal['caldav_url']
										,$cal['caldav_sso'] ? 1 : 0
										,$cal['caldav_user']
										,$cal['caldav_pass']
										,$cal['caldav_readonly']
										);
	}

	/**
	 * Update properties of an existing calendar
	 *
	 * @see calendar_driver::edit_calendar()
	 */
	public function edit_calendar($cal){
		return caldavsso_db::get_instance()->set_cal_data($cal['id']
										,$cal['name']
										,$cal['color']
										,$cal['showalarms']
										,$cal['caldav_url']
										,$cal['caldav_sso'] ? 1 : 0
										,$cal['caldav_user']
										,$cal['caldav_pass']
										,$cal['caldav_readonly']
										);
	}

	/**
	 * Set active/subscribed state of a calendar
	 * Save a list of hidden calendars in user prefs
	 *
	 * @see calendar_driver::subscribe_calendar()
	 */
	public function subscribe_calendar($prop){
		return true; // not applicable
	}

	/**
	 * Delete the given calendar with all its contents
	 *
	 * @see calendar_driver::delete_calendar()
	 */
	public function delete_calendar($prop){
		caldavsso_db::get_instance()->del_cal($prop['id']);
		$this->_read_calendars();
		return;
	}

	/**
	 * Search for shared or otherwise not listed calendars the user has access
	 *
	 * @param string Search string
	 * @param string Section/source to search
	 * @return array List of calendars
	 */
	public function search_calendars($query, $source){
		rcube::raise_error(array('code' => 501, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "search_calendars is not implemented yet"), true, true);
	}

	/**
	 * Add a single event to the database
	 *
	 * @param array Hash array with event properties
	 * @see calendar_driver::new_event()
	 */
	public function new_event($event){
		return caldavsso_dav::create_event($event);
	}

	/**
	 * Update the event entry with the given data and sync with caldav server.
	 *
	 * @param array Hash array with event properties
	 * @param array Internal use only, filled with non-modified event if this is second try after a calendar sync was enforced first.
	 * @see caldavsso_driver::_db_edit_event()
	 * @return bool
	 */
	public function edit_event($event, $old_event = null){
		return caldavsso_dav::upd_event($event);
	}
	
	/**
	 * Extended event editing with possible changes to the argument
	 *
	 * @param array  Hash array with event properties
	 * @param string New participant status
	 * @param array  List of hash arrays with updated attendees
	 * @return boolean True on success, False on error
	 */
	public function edit_rsvp(&$event, $status, $attendees){
		rcube::raise_error(array('code' => 501, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "edit_rsvp is not implemented yet"), true, true);
	}

	/**
	 * Update the participant status for the given attendees
	 *
	 * @see calendar_driver::update_attendees()
	 */
	public function update_attendees(&$event, $attendees){
		return caldavsso_dav::update_attendees($event, $attendees);
	}

	/**
	 * Move a single event
	 *
	 * @param array Hash array with event properties
	 * @see calendar_driver::move_event()
	 * @return bool
	 */
	public function move_event($event){
		return caldavsso_dav::upd_event_time($event);
	}

	/**
	 * Resize a single event
	 *
	 * @param array Hash array with event properties
	 * @see calendar_driver::resize_event()
	 * @return bool
	 */
	public function resize_event($event){
		return caldavsso_dav::upd_event_time($event);
	}

	/**
	 * Remove a single event from the database and from the CalDAV server.
	 *
	 * @param array Hash array with event properties
	 * @param boolean Remove record irreversible
	 *
	 * @see calendar_driver::remove_event()
	 * @return bool
	 */
	public function remove_event($event, $force = true){
		return caldavsso_dav::del_event($event);
	}

	/**
	 * Return data of a specific event
	 * @param mixed  Hash array with event properties or event UID
	 * @param integer Bitmask defining the scope to search events in
	 * @param boolean If true, recurrence exceptions shall be added
	 * @return array Hash array with event properties
	 */
	public function get_event($event, $scope = 0, $full = false){
		if(!isset($event['id'])){
			// This is a meeting invite from an email message, search for the event in all calendars
			$cal_id = -1;
			foreach($this->calendars as $calendar){
				if(caldavsso_dav::does_exists($calendar['id'], $event['uid'])){
					$cal_id = $calendar['id'];
					$id_mixed = $event['uid'];
					break;
				}
			}
			if($cal_id == -1){return false;}
		}else{
			// There is a request for an event from the ui, provide the data
			if(!caldavsso_dav::does_exists($event['calendar'], $event['id'])){
				return $event;
			}
			$cal_id = $event['calendar'];
			$id_mixed = $event['id'];
		}

		$dav_vcal = caldavsso_dav::get_dav_vcal_id($cal_id, $id_mixed);
		return caldavsso_converters::vevent2driver($dav_vcal->VEVENT, $cal_id, $id_mixed);
	}

	public function load_events($start, $end, $query = null, $calendars = null, $virtual = 1, $modifiedsince = null){
		if(is_array($calendars)){
			$events = array();
			foreach($calendars as $calendar){
				$temp = self::load_events_cal($start, $end, $query, $calendar, $virtual, $modifiedsince);
				if(is_array($temp) && count($temp) > 0){
					$events = array_merge($events, $temp);
				}
			}
			return $events;
		}else{
			return self::load_events_cal($start, $end, $query, $calendars, $virtual, $modifiedsince);
		}
	}

	private function load_events_cal($start, $end, $query, $calendar, $virtual, $modifiedsince){
		if($calendar == self::BIRTHDAY_CALENDAR_ID){
			return $this->load_birthday_events($start, $end, $query, $modifiedsince);
		}
		return caldavsso_dav::get_events($start, $end, $query, $calendar, $virtual, $modifiedsince);
	}

	/**
	 * Get number of events in the given calendar
	 *
	 * @param  mixed   List of calendar IDs to count events (either as array or comma-separated string)
	 * @param  integer Date range start (unix timestamp)
	 * @param  integer Date range end (unix timestamp)
	 * @return array   Hash array with counts grouped by calendar ID
	 */
	public function count_events($calendars, $start, $end = null){
		rcube::raise_error(array('code' => 501, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "count_events is not implemented yet"), true, true);
	}

	/**
	 * Get a list of pending alarms to be displayed to the user
	 *
	 * @see calendar_driver::pending_alarms()
	 */
	public function pending_alarms($time, $calendars = null){
		return array(); // TODO pending_alarms
	}

	/**
	 * Feedback after showing/sending an alarm notification
	 *
	 * @see calendar_driver::dismiss_alarm()
	 */
	public function dismiss_alarm($event_id, $snooze = 0){
		return; // TODO dismiss_alarm
	}

	/**
	 * Handler for user_delete plugin hook
	 */
	public function user_delete($args){
		return caldavsso_db::get_instance()->del_user($args['user']->ID);
	}

	/**
	 * Callback function to produce driver-specific calendar create/edit form
	 *
	 * @param string Request action 'form-edit|form-new'
	 * @param array  Calendar properties (e.g. id, color)
	 * @param array  Edit form fields
	 *
	 * @return string HTML content of the form
	 */
	public function calendar_form($action, $calendar, $formfields){
		$calendar = $this->calendars[$calendar["id"]];
		
		$array_caldav_readonly = array(
            "name" => "caldav_readonly",
            "id" => "caldav_readonly",
		);
		if($calendar["dav_readonly"] != 1){
			$array_caldav_readonly["value"] = "1";
		}
        $input_caldav_readonly = new html_checkbox($array_caldav_readonly);
        $formfields["caldav_readonly"] = array(
            "label" => $this->cal->gettext("readonly"),
            "value" => $input_caldav_readonly->show(),
            "id" => "caldav_readonly",
        );

        $input_caldav_url = new html_inputfield( array(
            "name" => "caldav_url",
            "id" => "caldav_url",
				"autocomplete" => "off",
            "size" => 20
        ));
        $formfields["caldav_url"] = array(
            "label" => $this->cal->gettext("caldavurl"),
            "value" => $input_caldav_url->show($calendar["dav_url"]),
            "id" => "caldav_url",
        );

		$array_caldav_sso = array(
            "name" => "caldav_sso",
            "id" => "caldav_sso"
        );
		if($calendar["dav_sso"] != 1){
			$array_caldav_sso["value"] = "1";
		}
        $input_caldav_sso = new html_checkbox($array_caldav_sso);
        $formfields["caldav_sso"] = array(
            "label" => $this->cal->gettext("caldavsso"),
            "value" => $input_caldav_sso->show(),
            "id" => "caldav_sso",
        );

        $input_caldav_user = new html_inputfield( array(
            "name" => "caldav_user",
            "id" => "caldav_user",
			"value" => $calendar["dav_user"],
            "size" => 20
        ));
        $formfields["caldav_user"] = array(
            "label" => $this->cal->gettext("username"),
            "value" => $input_caldav_user->show(),
            "id" => "caldav_user",
        );

        $input_caldav_pass = new html_passwordfield( array(
            "name" => "caldav_pass",
            "id" => "caldav_pass",
            "size" => 20
        ));

        $formfields["caldav_pass"] = array(
            "label" => $this->cal->gettext("password"),
            "value" => $input_caldav_pass->show(null), // Don't send plain text password to GUI
            "id" => "caldav_pass",
        );

        return parent::calendar_form($action, $calendar, $formfields);
    }
}
